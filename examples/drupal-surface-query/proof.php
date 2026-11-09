<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use CodeMode\{CodeModeSession, DockerEngine, ExecutionIdentity, ExecutionLimits, GvisorSandbox, SandboxException};
use SurfaceProof\{Connection, Verifier};

$env = static fn ($key, $default='') => (string) ($_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default);
$connection = new Connection($env('SURFACE_PROOF_SITE'));
$docker = new DockerEngine(json_decode($env('CODE_MODE_DOCKER_COMMAND','["docker"]'),true,flags:JSON_THROW_ON_ERROR));
$image = $env('CODE_MODE_IMAGE');
$sandbox = new GvisorSandbox($image,$docker);
$limits = new ExecutionLimits(wallTimeMs:15000,toolTimeMs:3000,maxToolCalls:2);
if (!is_dir(__DIR__.'/var')) { mkdir(__DIR__.'/var',0700,true); }
$lock = fopen(__DIR__.'/var/proof.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { throw new RuntimeException('Another query proof is active.'); }
$report = ['version'=>1,'kind'=>'data-surface-query-proof','createdAt'=>gmdate(DATE_ATOM),'modelCalled'=>false,
    'programOrigin'=>'Operator-written reference PHP; no model or token comparison.', 'runtime'=>$sandbox->profile()->metadata(), 'image'=>$image,
    'limits'=>get_object_vars($limits)+['executionAttempts'=>2], 'executions'=>[], 'mcp'=>[]];
$revoked = false;
try {
    $docker->requireGvisor();
    $report['before'] = $connection->control('snapshot');
    if (!Verifier::fixture($report['before'])) { throw new RuntimeException('Unexpected fixture access or permissions.'); }
    $providers = $connection->providers();
    $session = static function () use ($providers,$sandbox,$limits): CodeModeSession {
        $session = new CodeModeSession($providers->catalog(),$providers->broker(),$sandbox,new ExecutionIdentity('surface-proof-reader',['published.search']),$limits,phpInterface:$providers->php());
        $session->describe(['drupal.search']); return $session;
    };
    $report['contracts'] = $session()->describe(['drupal.search']);
    $report['origins'] = $providers->origins(); $report['interfaces'] = $providers->php()->stubs();
    $report['direct'] = $connection->control('direct')['direct'];
    foreach (['orchard'=>'SCMQ orchard','garden'=>'SCMQ garden','empty'=>'SCMQ no-such-result','draft'=>'PRIVATE_DRAFT_CANARY','field_denied'=>'PRIVATE_FIELD_CANARY'] as $key=>$keywords) {
        $report['mcp'][$key] = $connection->mcp(['keywords'=>$keywords,'limit'=>10]);
    }
    $capture = static function (string $label, CodeModeSession $session, string $code) use (&$report,$docker): void {
        fwrite(STDERR,'Running '.$label."…\n"); $start = hrtime(true);
        $trace = ['php'=>$code,'executedPhp'=>$session->executionCode($code)]; $trace['sourceSha256'] = hash('sha256',$trace['executedPhp']);
        try {
            $result = $session->execute($code);
            $trace += ['status'=>'succeeded','reason'=>null,'value'=>$result->value,'toolCalls'=>$result->toolCalls,'runId'=>$result->runId,'runtime'=>$result->profile->value,'executionMs'=>$result->durationMs,'outputBytes'=>$result->outputBytes];
        } catch (SandboxException $error) {
            $trace += ['status'=>'failed','reason'=>$error->reason,'value'=>null,'toolCalls'=>$error->toolCalls,'runId'=>$error->runId,'runtime'=>$error->profile->value];
        }
        $trace['elapsedMs'] = round((hrtime(true)-$start)/1e6,2);
        $trace['containerRemoved'] = null === $trace['runId'] ? null : '' === $docker->control(['ps','-aq','--filter','name=^/'.$trace['runId'].'$']);
        $report['executions'][$label] = json_decode(json_encode($trace,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
    };
    $main = $session();
    $capture('composition',$main,trim(file_get_contents(__DIR__.'/proof/compose.php.txt')));
    $capture('empty',$main,'return $tools->drupal->search(["values" => ["keywords" => "SCMQ no-such-result", "limit" => 10]]);');
    $capture('attempt-budget',$main,'return 1;');
    $capture('restricted-records',$session(),'return ["draft" => $tools->drupal->search(["values" => ["keywords" => "PRIVATE_DRAFT_CANARY", "limit" => 10]]), "field_denied" => $tools->drupal->search(["values" => ["keywords" => "PRIVATE_FIELD_CANARY", "limit" => 10]])];');
    $capture('excessive-limit',$session(),'return $tools->drupal->search(["values" => ["limit" => 11]]);');
    $capture('unknown-input',$session(),'return $tools->drupal->search(["values" => ["limit" => 3, "include_unpublished" => true]]);');
    $capture('raw-upstream-name',$session(),'return $tools->call("tool_api__code_mode_surface_search", ["values" => ["limit" => 3]]);');
    // Discovery happens before permission changes. Keep this exact session afterward.
    $recovery = $session();
    $revoked = true; // Restore in finally even if control fails after saving its change.
    $report['revoked'] = $connection->control('revoke');
    $report['mcp']['revoked'] = $connection->mcp(['keywords'=>'SCMQ orchard','limit'=>10]);
    $read = 'return $tools->drupal->search(["values" => ["keywords" => "SCMQ orchard", "limit" => 10]]);';
    $capture('revoked',$recovery,$read);
    $connection->control('restore'); $revoked = false;
    $capture('restored',$recovery,$read);
} catch (Throwable $error) {
    $report['error'] = $error instanceof SandboxException ? $error->reason : 'proof_unavailable';
} finally {
    if ($revoked) {
        try { $connection->control('restore'); } catch (Throwable) { $report['restorationFailed'] = true; }
    }
    try { $report['after'] = $connection->control('snapshot'); } catch (Throwable) { $report['snapshotFailed'] = true; }
    $report['verification'] = (new Verifier())->check($report);
    $report['status'] = !isset($report['error']) && !isset($report['restorationFailed']) && !isset($report['snapshotFailed']) && !in_array(false,$report['verification'],true) ? 'complete' : 'failed';
    // Independent captures are retained locally, including failed runs.
    $json = json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    file_put_contents(__DIR__.'/var/proof-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json',$json,LOCK_EX);
    file_put_contents(__DIR__.'/var/latest-proof.json',$json,LOCK_EX);
    foreach ($report['verification'] as $name=>$ok) { echo ($ok?'PASS ':'FAIL ').$name."\n"; }
    flock($lock,LOCK_UN); fclose($lock);
}
exit($report['status']==='complete'?0:1);
