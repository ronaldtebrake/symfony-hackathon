<?php

declare(strict_types=1);

use CodeMode\BatchSession;
use CodeMode\Symfony\Http\CatalogTools;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

$app = dirname(__DIR__);
require $app.'/vendor/autoload.php';
$report = ['format' => 'symfony-http-catalog/v1', 'capturedAt' => gmdate(\DATE_ATOM), 'status' => 'running',
    'scope' => 'Operator-authored JSON-RPC over actual Symfony MCP HTTP and bearer authentication. Independent Composer application, synthetic articles, no model or generated code. Process-control and child-process functions disabled inside the HTTP application.',
    'execution' => ['mode' => 'in-process', 'hardTermination' => false, 'budgets' => 'Persistent per authenticated user; failures and reconnects do not reset them.'],
    'checks' => [], 'httpChecks' => [], 'calls' => []];
$verify = static function (string $name, bool $ok) use (&$report): void {
    $report['checks'][$name] = $ok;
    if (!$ok) {
        throw new RuntimeException('Check failed: '.$name);
    }
};
$token = bin2hex(random_bytes(32));
$viewer = bin2hex(random_bytes(32));
$accessFile = tempnam(sys_get_temp_dir(), 'symfony-reader-access-');
if (false === $accessFile) {
    throw new RuntimeException('Cannot allocate permission fixture.');
}
$environment = array_replace(array_fill_keys(array_keys(getenv()), false), [
    'PATH' => getenv('PATH') ?: '', 'SystemRoot' => getenv('SystemRoot') ?: false,
    'ADOPTION_MCP_TOKEN' => $token, 'ADOPTION_VIEWER_TOKEN' => $viewer, 'ADOPTION_MODE' => 'catalog',
    'ADOPTION_READER_ACCESS_FILE' => $accessFile,
]);
$disabled = 'pcntl_fork,pcntl_waitpid,pcntl_alarm,pcntl_signal,posix_setsid,posix_kill,posix_getpid,proc_open,popen,exec,shell_exec,system,passthru';
$http = HttpClient::create(['timeout' => 10, 'max_duration' => 15, 'max_redirects' => 0]);
$server = null;
$sequence = 0;
$session = null;
$control = static function (array $args) use ($app, $environment): string {
    $p = new Process([\PHP_BINARY, 'bin/console', ...$args], $app, $environment, timeout: 20);
    $p->mustRun();

    return $p->getOutput();
};
$startServer = static function () use ($app, $environment, $disabled, $http): array {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (false === $socket) {
        throw new RuntimeException('Cannot allocate a test HTTP port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    // Import the harness environment before Dotenv loads local recording credentials.
    $p = new Process([\PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'disable_functions='.$disabled, '-S', $address, '-t', 'public', 'public/index.php'], $app, $environment);
    $p->start();
    $url = 'http://'.$address.'/mcp';
    $deadline = microtime(true) + 15;
    do {
        try {
            if (401 === $http->request('GET', $url)->getStatusCode()) {
                return [$p, $url];
            }
        } catch (Throwable) {
        }
        usleep(20000);
    } while ($p->isRunning() && microtime(true) < $deadline);
    $p->stop();
    throw new RuntimeException('The authenticated HTTP endpoint did not start.');
};
$request = static function (string $method, array $params = [], ?string $credential = null, array $extraHeaders = []) use (&$url, &$sequence, &$session, $http, $token): array {
    $rpc = ['jsonrpc' => '2.0', 'id' => ++$sequence, 'method' => $method, 'params' => (object) $params];
    $headers = ['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer '.($credential ?? $token), 'MCP-Protocol-Version' => '2025-11-25'];
    if (null !== $session) {
        $headers['Mcp-Session-Id'] = $session;
    }
    $response = $http->request('POST', $url, ['headers' => array_replace($headers, $extraHeaders), 'json' => $rpc]);
    $status = $response->getStatusCode();
    $body = $response->getContent(false);
    $newSession = $response->getHeaders(false)['mcp-session-id'][0] ?? null;
    if (null !== $newSession) {
        $session = $newSession;
    }
    if (preg_match('/^data: (.+)$/m', $body, $m)) {
        $body = $m[1];
    }
    $decoded = json_decode($body, true);

    return ['httpStatus' => $status, 'request' => $rpc, 'response' => $decoded];
};
$initialize = static function () use ($request, &$session, $verify): void {
    $session = null;
    $r = $request('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'Symfony HTTP proof', 'version' => '1']]);
    $verify('http_initialize', 200 === $r['httpStatus'] && isset($r['response']['result']['serverInfo']));
};
$call = static function (string $name, array $arguments, string $label) use ($request, &$report): array {
    $row = $request('tools/call', ['name' => $name, 'arguments' => $arguments]);
    $row['label'] = $label;
    $report['calls'][] = $row;

    return $row['response']['result']['structuredContent'] ?? [];
};
$read = static fn ($id) => ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => $id]]]];
$controller = static function (int $id) use (&$url, $http, $token): array {
    $response = $http->request('GET', substr($url, 0, -4).'/articles/'.$id, ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']]);

    return ['httpStatus' => $response->getStatusCode(), 'response' => json_decode($response->getContent(false), true)];
};
try {
    foreach ([BatchSession::class, CatalogTools::class] as $class) {
        $file = realpath((new ReflectionClass($class))->getFileName());
        $verify('own_vendor:'.$class, str_starts_with($file, realpath($app.'/vendor').\DIRECTORY_SEPARATOR));
    }
    $verify('no_root_autoloader', !class_exists('App\\Kernel'));
    $verify('copied_packages', !is_link($app.'/vendor/ronaldtebrake/php-code-mode') && !is_link($app.'/vendor/ronaldtebrake/symfony-code-mode'));
    $report['doctor'] = json_decode($control(['code-mode:doctor', '--json']), true, flags: \JSON_THROW_ON_ERROR);
    $verify('plain_php_runtime', true === $report['doctor']['ready'] && false === $report['doctor']['runtime']['hardTermination']);
    $control(['code-mode:reset-budget', 'demo-reader']);
    $control(['code-mode:reset-budget', 'demo-viewer']);
    $control(['demo:reader-access', 'grant']);
    [$server, $url] = $startServer();
    $report['httpChecks']['unauthenticated'] = ['httpStatus' => $http->request('GET', $url)->getStatusCode()];
    $verify('authentication_required', 401 === $report['httpChecks']['unauthenticated']['httpStatus']);
    $report['httpChecks']['invalid_token'] = $request('tools/list', credential: 'invalid');
    $verify('invalid_token_rejected', 401 === $report['httpChecks']['invalid_token']['httpStatus']);
    $initialize();
    $tools = $request('tools/list');
    $report['httpChecks']['advertised_tools'] = $tools;
    $names = array_column($tools['response']['result']['tools'] ?? [], 'name');
    sort($names);
    $verify('two_entry_tools', ['execute_tools', 'search_tools'] === $names);
    $verify('discovery_required', 'discovery_required' === ($call('execute_tools', $read(101), 'before_discovery')['error']['reason'] ?? null));
    $search = $call('search_tools', ['query' => 'publication'], 'discovery');
    $verify('only_approved_contract', ['publication_read'] === array_column($search['tools'] ?? [], 'name'));
    $allowed = $call('execute_tools', $read(101), 'authorized');
    $verify('authorized_read', ['id' => 101, 'title' => 'A Symfony application owns its tools'] === ($allowed['results'][0]['value'] ?? null));
    $report['httpChecks']['controller_authorized'] = $controller(101);
    $report['httpChecks']['controller_restricted'] = $controller(202);
    $verify('controller_shares_authorized_read', 200 === $report['httpChecks']['controller_authorized']['httpStatus']
        && $allowed['results'][0]['value'] === $report['httpChecks']['controller_authorized']['response']);
    $verify('controller_restricted_read_denied', 403 === $report['httpChecks']['controller_restricted']['httpStatus']);
    $denied = $call('execute_tools', $read(202), 'restricted');
    $verify('restricted_read_denied', 'tool_denied' === ($denied['error']['reason'] ?? null) && 0 === ($denied['completedCalls'] ?? null) && !isset($denied['results']));
    $verify('failure_consumes_attempt', 'execution_limit' === ($call('execute_tools', $read(101), 'exhausted')['error']['reason'] ?? null));
    // A new process and protocol session must preserve both discovery and exhausted attempts.
    $server->stop();
    [$server, $url] = $startServer();
    $initialize();
    $verify('restart_and_reconnect_preserve_budget', 'execution_limit' === ($call('execute_tools', $read(101), 'after_restart')['error']['reason'] ?? null));
    $report['httpChecks']['untrusted_origin'] = $request('tools/list', extraHeaders: ['Origin' => 'https://untrusted.example']);
    $verify('origin_check', 403 === $report['httpChecks']['untrusted_origin']['httpStatus']);
    $spoof = $request('tools/call', ['name' => 'execute_tools', 'arguments' => $read(101) + ['identity' => 'admin']]);
    $report['httpChecks']['forged_identity'] = $spoof;
    $verify('identity_argument_rejected', isset($spoof['response']['error']) || true === ($spoof['response']['result']['isError'] ?? false));
    // A different authenticated user cannot inherit the first user's discovery or grants.
    $other = $request('tools/call', ['name' => 'execute_tools', 'arguments' => $read(101)], $viewer);
    $report['httpChecks']['other_user_discovery'] = $other;
    $verify('discovery_isolated_by_user', 'discovery_required' === ($other['response']['result']['structuredContent']['error']['reason'] ?? null));
    $hidden = $request('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => 'publication']], $viewer);
    $report['httpChecks']['other_user_search'] = $hidden;
    $verify('discovery_filters_permissions', [] === ($hidden['response']['result']['structuredContent']['tools'] ?? null) && 0 === ($hidden['response']['result']['structuredContent']['matched'] ?? null));
    $other = $request('tools/call', ['name' => 'execute_tools', 'arguments' => $read(101)], $viewer);
    $report['httpChecks']['other_user_permissions'] = $other;
    $verify('security_role_enforced', 'discovery_required' === ($other['response']['result']['structuredContent']['error']['reason'] ?? null));
    $control(['code-mode:reset-budget', 'demo-reader']);
    $call('search_tools', ['query' => 'publication'], 'operator_reset_discovery');
    $tooMany = $call('execute_tools', ['calls' => array_fill(0, 11, $read(101)['calls'][0])], 'call_budget');
    $verify('call_budget_enforced', 'tool_call_limit' === ($tooMany['error']['reason'] ?? null));
    $verify('invalid_attempt_consumed', 101 === ($call('execute_tools', $read(101), 'recovery_after_invalid')['results'][0]['value']['id'] ?? null)
        && 'execution_limit' === ($call('execute_tools', $read(101), 'invalid_attempt_exhausted')['error']['reason'] ?? null));
    // Separate take: same token, user, server and MCP session throughout. No reset between reads.
    $control(['code-mode:reset-budget', 'demo-reader']);
    $call('search_tools', ['query' => 'publication'], 'revocation_discovery');
    $first = $call('execute_tools', $read(101), 'before_revocation');
    $verify('read_before_revocation', 101 === ($first['results'][0]['value']['id'] ?? null));
    $report['permissionChange'] = ['command' => 'php bin/console demo:reader-access revoke',
        'output' => trim($control(['demo:reader-access', 'revoke'])),
        'subject' => 'demo-reader', 'permission' => 'publication.read_published',
        'after' => 'before_revocation', 'before' => 'after_revocation'];
    $denied = $call('execute_tools', $read(101), 'after_revocation');
    $verify('revoked_call_denied', 'tool_denied' === ($denied['error']['reason'] ?? null)
        && 0 === ($denied['completedCalls'] ?? null) && !isset($denied['results']));
    $report['httpChecks']['controller_revoked'] = $controller(101);
    $verify('controller_revocation_enforced', 403 === $report['httpChecks']['controller_revoked']['httpStatus']);
    $hidden = $call('search_tools', ['query' => 'publication'], 'revoked_discovery');
    $verify('revoked_contract_hidden', [] === ($hidden['tools'] ?? null) && 0 === ($hidden['matched'] ?? null));
    $control(['demo:reader-access', 'grant']);
    $verify('regrant_does_not_reset_budget', 'execution_limit' === ($call('execute_tools', $read(101), 'regrant_exhausted')['error']['reason'] ?? null));
    $verify('private_data_absent', !str_contains(json_encode($report['calls']), 'PRIVATE_FIXTURE_DO_NOT_RETURN'));
    $verify('credentials_absent', !str_contains(json_encode($report), $token) && !str_contains(json_encode($report), $viewer));
    $report['installation'] = ['composerLockSha256' => hash_file('sha256', $app.'/composer.lock'),
        'rootAutoloaderPresent' => is_file($app.'/../../vendor/autoload.php'), 'pathRepositorySourcesPresent' => is_dir($app.'/../../packages'),
        'disabledFunctions' => explode(',', $disabled), 'phpVersion' => \PHP_VERSION, 'osFamily' => \PHP_OS_FAMILY];
    $report['sourceSha256'] = [];
    foreach (['src/ArticleVoter.php', 'src/ArticleController.php', 'src/DemoReaderAccess.php', 'src/ReaderAccessCommand.php', 'scripts/http-proof.php', 'src/ArticleTool.php', 'src/DemoTokenHandler.php', 'public/index.php', 'config/services.php', 'config/routes.php', 'config/bundles.php', 'vendor/ronaldtebrake/symfony-code-mode/src/Http/SecurityIdentityProvider.php', 'vendor/ronaldtebrake/symfony-code-mode/src/Security/VoterToolPolicy.php', 'vendor/ronaldtebrake/symfony-code-mode/src/CodeModeBundle.php', 'vendor/ronaldtebrake/php-code-mode/src/BatchSession.php', 'vendor/ronaldtebrake/symfony-code-mode/src/Http/CatalogTools.php', 'vendor/ronaldtebrake/symfony-code-mode/src/Http/CatalogStateStore.php'] as $source) {
        $report['sourceSha256'][$source] = hash_file('sha256', $app.'/'.$source);
    }
    $report['status'] = 'passed';
} catch (Throwable $error) {
    $report['status'] = 'failed';
    $report['errorType'] = $error::class;
    fwrite(\STDERR, 'HTTP proof failed: '.$error->getMessage()."\n");
} finally {
    $server?->stop();
    unlink($accessFile);
}
echo json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
exit('passed' === $report['status'] ? 0 : 1);
