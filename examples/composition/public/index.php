<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
// Read-only, fixed local evidence paths. This viewer never creates a session or executes PHP.
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
$root = dirname(__DIR__);
$path = is_file($root.'/var/latest-proof.json') ? $root.'/var/latest-proof.json' : $root.'/proof/reference.json';
$report = is_file($path) ? json_decode(file_get_contents($path), true, 64, \JSON_THROW_ON_ERROR) : [];
$checks = (new Composition\Verifier())->check($report);
$passed = !in_array(false, $checks, true);
$e = static fn ($value) => htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
$json = static fn ($value) => json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
$audit = $report['executions']['audit'] ?? [];
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Symfony Code Mode · Provider composition</title>
<style>
:root{color-scheme:light;--bg:#f8f7ff;--panel:#ffffff;--line:#e3ddec;--text:#191226;--muted:#686174;--mint:#4c1d95;--purple:#db2777}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 80% 0,#eee7ff 0,transparent 50%),var(--bg);color:var(--text);font:16px/1.6 system-ui,sans-serif}main{max-width:1120px;margin:auto;padding:36px 28px 70px}header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding-bottom:20px;font-size:14px}header b{font-size:18px}.eyebrow{color:var(--mint);font-size:12px;letter-spacing:.15em;text-transform:uppercase}h1{font-size:clamp(36px,6vw,64px);line-height:1.08;letter-spacing:-.05em;margin:16px 0 20px;max-width:850px}h1 em{font-style:normal;background:linear-gradient(110deg,#4c1d95,#db2777);background-clip:text;color:transparent}h2{font-size:24px;letter-spacing:-.02em}p{color:var(--muted);max-width:830px}a{color:var(--mint)}.hero{padding:46px 0 24px}.pill{color:var(--mint);border:1px solid #d3c2f2;background:#eee7ff;border-radius:20px;padding:5px 12px;font-size:12px}.failed{color:#a52a48;border-color:#ecc0ce;background:#fff0f4}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.card,details{border:1px solid var(--line);border-radius:16px;background:var(--panel);padding:22px}.card strong{font-size:30px;color:var(--mint);display:block}.card span{color:var(--muted);font-size:14px}.flow{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:22px 0 30px}.flow span{padding:9px 15px;background:#eee7ff;border-radius:8px;font-size:14px}.flow .native{color:var(--mint)}.flow .mcp{color:var(--purple)}section{margin-top:34px}pre{font:13px/1.65 ui-monospace,SFMono-Regular,monospace;white-space:pre;overflow:auto;padding:20px;margin:12px 0 0;background:#171027;color:#f5f0ff;border:1px solid var(--line);border-radius:10px;tab-size:4}summary{cursor:pointer;font-weight:600}details{margin-top:12px}details[open] summary{margin-bottom:14px}table{border-collapse:collapse;width:100%;font-size:14px}td,th{padding:10px;text-align:left;border-bottom:1px solid var(--line)}th{color:var(--muted);font-weight:500}.source{color:var(--purple)}.check{color:var(--mint)}.dim{color:var(--muted)}.small{font-size:13px}footer{margin-top:40px;border-top:1px solid var(--line);padding-top:20px;font-size:13px;color:var(--muted)}@media(max-width:700px){.grid{grid-template-columns:1fr}.card{padding:16px}.card strong{font-size:24px}main{padding:20px 16px}header{gap:16px}table{font-size:12px}td,th{padding:8px 4px}}
</style>
<main><header><b>Symfony Code Mode</b><span class="dim">Independent adoption example</span></header>
<div class="hero"><span class="eyebrow">Compose approved tools</span><h1>Different providers.<br><em>One PHP program.</em></h1>
<p>Symfony AI reads the articles. An MCP service reads their media. PHP joins both inside gVisor, while every call keeps its original policy and schema checks.</p>
<span class="pill <?php echo $passed ? '' : 'failed'; ?>"><?php echo $passed ? 'Verified reference execution' : 'Proof missing or verification failed'; ?></span>
<p class="small">Operator-written PHP · No model called · No token-savings claim · <?php echo $e($report['createdAt'] ?? 'Run composition:proof to capture evidence.'); ?></p></div>
<div class="grid"><div class="card"><strong><?php echo $e($audit['value']['audited'] ?? '—'); ?></strong><span>published articles audited</span></div><div class="card"><strong><?php echo $e(isset($audit['value']['findings']) ? count($audit['value']['findings']) : '—'); ?></strong><span>findings matched to the fixture</span></div><div class="card"><strong><?php echo $e(isset($audit['toolCalls']) ? count($audit['toolCalls']) : '—'); ?></strong><span>actual reads across two providers</span></div></div>
<div class="flow"><span class="native">catalog · Symfony AI</span><span>+</span><span class="mcp">media · MCP</span><span>→</span><span>PHP in gVisor</span><span>→</span><span>Verified findings</span></div>
<section><h2>Write against the approved contracts</h2><p>Namespaced calls are a convenience over the existing broker. The alias grants no access. Search and full schema discovery still come first.</p>
<pre><?php echo $e($audit['php'] ?? ''); ?></pre>
<details><summary>Schema-derived PHP interfaces</summary><p class="small">Editor guidance derived from approved schemas. Complex shapes fall back to broad types. This is not static verification of generated programs; the full schema and host policy stay authoritative.</p><pre><?php echo $e($report['interfaces'] ?? ''); ?></pre></details>
<details><summary>Exact executed source, including the guest proxy</summary><p class="small">SHA-256: <?php echo $e($audit['sourceSha256'] ?? ''); ?> · The proxy also counts against the existing source-byte limit.</p><pre><?php echo $e($audit['executedPhp'] ?? ''); ?></pre></details></section>
<section><h2>The actual tool calls</h2><p class="small">Recorded by the host broker. <?php echo $e(isset($audit['executionMs']) ? round($audit['executionMs']).' ms execution' : ''); ?> · <?php echo $e($audit['outputBytes'] ?? '—'); ?> execution-output bytes, not model tokens.</p><div class="card"><table><thead><tr><th>#</th><th>PHP tool</th><th>Provider → upstream</th><th>Result</th></tr></thead><tbody>
<?php foreach ($audit['toolCalls'] ?? [] as $i => $call) {
    $origin = $report['origins'][$call['name']] ?? []; ?>
<tr><td><?php echo $i + 1; ?></td><td><?php echo $e($call['name']); ?></td><td><span class="source"><?php echo $e($origin['source'] ?? ''); ?></span><br><span class="small dim"><?php echo $e($origin['upstreamTool'] ?? ''); ?></span></td><td class="check"><?php echo $e($call['status']); ?><br><span class="small dim"><?php echo $e($call['resultBytes'] ?? '—'); ?> B</span></td></tr>
<?php } ?></tbody></table></div><details><summary>Returned findings</summary><pre><?php echo $e($json($audit['value'] ?? null)); ?></pre></details></section>
<section><h2>The boundaries still hold</h2><p>A draft read and an out-of-scope media read are denied. Raw upstream names and undiscovered tools cannot bypass the broker. A third attempt is rejected before allocation; an infinite loop is terminated.</p>
<?php foreach ($report['executions'] ?? [] as $label => $run) {
    if ('audit' === $label) {
        continue;
    } ?>
<details><summary><?php echo $e($label); ?> <span class="dim">· <?php echo $e($run['reason']); ?></span></summary><pre><?php echo $e($run['php']); ?></pre><p class="small">Run: <?php echo $e($run['runId'] ?? 'No container allocated'); ?> · <?php echo $e($run['elapsedMs']); ?> ms · <?php echo $run['containerRemoved'] ? 'Container removed' : 'No cleanup required'; ?></p><pre><?php echo $e($json($run['toolCalls'])); ?></pre></details>
<?php } ?>
<details><summary>Verification checks</summary><pre><?php echo $e($json($checks)); ?></pre></details></section>
<footer>This is a read-only viewer. Conversations stay in your agent, connected to <code>php bin/console code-mode:serve</code>. It has its own Composer install and proof files; the main hackathon demo remains independent.</footer></main></html>
