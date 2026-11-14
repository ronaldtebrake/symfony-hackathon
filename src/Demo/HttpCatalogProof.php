<?php

declare(strict_types=1);

namespace App\Demo;

/** Verify retained HTTP messages; no network, credentials or tool execution in the viewer. */
final class HttpCatalogProof
{
    public static function verify(array $report): array
    {
        $calls = array_column($report['calls'] ?? [], null, 'label');
        $value = static fn ($key) => $calls[$key]['response']['result']['structuredContent'] ?? [];
        $read = static fn ($id) => ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => $id]]]];
        $http = $report['httpChecks'] ?? [];
        $consistent = true;
        foreach ($calls as $call) {
            $result = $call['response']['result'] ?? [];
            $consistent = $consistent && 200 === ($call['httpStatus'] ?? null)
                && 'tools/call' === ($call['request']['method'] ?? null)
                && ($call['request']['id'] ?? null) === ($call['response']['id'] ?? null)
                && json_decode($result['content'][0]['text'] ?? '', true) === ($result['structuredContent'] ?? null)
                && ($result['isError'] ?? false) === isset($result['structuredContent']['error']);
        }
        $checks = [
            'format' => 'symfony-http-catalog/v1' === ($report['format'] ?? null),
            'messagesAgree' => $consistent && \count($calls) >= 10,
            'authenticatedHttp' => 401 === ($http['unauthenticated']['httpStatus'] ?? null) && 401 === ($http['invalid_token']['httpStatus'] ?? null),
            'originProtected' => 403 === ($http['untrusted_origin']['httpStatus'] ?? null),
            'approvedContract' => ['publication_read'] === array_column($value('discovery')['tools'] ?? [], 'name'),
            'authorizedRead' => $read(101) === ($calls['authorized']['request']['params']['arguments'] ?? null)
                && ['results' => [['name' => 'publication_read', 'value' => ['id' => 101, 'title' => 'A Symfony application owns its tools']]]] === $value('authorized'),
            'restrictedReadDenied' => $read(202) === ($calls['restricted']['request']['params']['arguments'] ?? null)
                && ['error' => ['reason' => 'tool_denied', 'tool' => 'publication_read', 'index' => 0], 'completedCalls' => 0] === $value('restricted'),
            'sharedControllerPolicy' => 200 === ($http['controller_authorized']['httpStatus'] ?? null)
                && ($value('authorized')['results'][0]['value'] ?? null) === ($http['controller_authorized']['response'] ?? null)
                && 403 === ($http['controller_restricted']['httpStatus'] ?? null)
                && 403 === ($http['controller_revoked']['httpStatus'] ?? null),
            'budgetPersists' => 'execution_limit' === ($value('exhausted')['error']['reason'] ?? null)
                && 'execution_limit' === ($value('after_restart')['error']['reason'] ?? null),
            'callBudget' => 'tool_call_limit' === ($value('call_budget')['error']['reason'] ?? null)
                && 'execution_limit' === ($value('invalid_attempt_exhausted')['error']['reason'] ?? null),
            'usersIsolated' => 'discovery_required' === ($http['other_user_discovery']['response']['result']['structuredContent']['error']['reason'] ?? null)
                && 'discovery_required' === ($http['other_user_permissions']['response']['result']['structuredContent']['error']['reason'] ?? null),
            'discoveryFiltersPermissions' => [] === ($http['other_user_search']['response']['result']['structuredContent']['tools'] ?? null)
                && 0 === ($http['other_user_search']['response']['result']['structuredContent']['matched'] ?? null),
            'permissionRevokedAfterDiscovery' => ['publication_read'] === array_column($value('revocation_discovery')['tools'] ?? [], 'name')
                && $read(101) === ($calls['before_revocation']['request']['params']['arguments'] ?? null)
                && $read(101) === ($calls['after_revocation']['request']['params']['arguments'] ?? null)
                && $value('authorized') === $value('before_revocation')
                && ['error' => ['reason' => 'tool_denied', 'tool' => 'publication_read', 'index' => 0], 'completedCalls' => 0] === $value('after_revocation')
                && [] === ($value('revoked_discovery')['tools'] ?? null) && 0 === ($value('revoked_discovery')['matched'] ?? null)
                && 'demo-reader: publication.read_published revoked' === ($report['permissionChange']['output'] ?? null)
                && 'execution_limit' === ($value('regrant_exhausted')['error']['reason'] ?? null),
            'freshInstall' => false === ($report['installation']['rootAutoloaderPresent'] ?? null) && false === ($report['installation']['pathRepositorySourcesPresent'] ?? null),
            'ordinaryExecution' => 'in-process' === ($report['doctor']['runtime']['execution'] ?? null)
                && false === ($report['doctor']['runtime']['hardTermination'] ?? null)
                && [] === array_diff(['pcntl_fork', 'posix_kill', 'proc_open'], $report['installation']['disabledFunctions'] ?? []),
            'operatorChecks' => 'passed' === ($report['status'] ?? null) && \count($report['checks'] ?? []) >= 31 && !\in_array(false, $report['checks'] ?? [], true),
        ];

        return ['passed' => !\in_array(false, $checks, true), 'checks' => $checks];
    }

    public static function load(string $root): array
    {
        $raw = file_get_contents($root.'/docs/evidence/http-catalog-proof.json');
        $capture = json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        $verification = self::verify($capture);
        $implementation = [];
        foreach (['handler' => 'ArticleTool.php', 'policy' => 'ArticleVoter.php', 'controller' => 'ArticleController.php'] as $role => $file) {
            $path = 'examples/symfony/src/'.$file;
            $source = file_get_contents($root.'/'.$path);
            $implementation[$role] = ['path' => $path, 'source' => $source, 'sha256' => hash('sha256', $source)];
            $verification['checks'][$role.'SourceMatchesCapture'] = $implementation[$role]['sha256'] === ($capture['sourceSha256']['src/'.$file] ?? null);
        }
        foreach ($capture['sourceSha256'] as $path => $hash) {
            $current = str_starts_with($path, 'vendor/ronaldtebrake/')
                ? $root.'/packages/'.substr($path, \strlen('vendor/ronaldtebrake/'))
                : $root.'/examples/symfony/'.$path;
            $verification['checks']['source:'.$path] = is_file($current) && hash_file('sha256', $current) === $hash;
        }
        $verification['passed'] = !\in_array(false, $verification['checks'], true);

        return ['status' => $verification['passed'] ? 'passed' : 'failed', 'capture' => $capture,
            'captureSha256' => hash('sha256', $raw), 'verification' => $verification, 'implementation' => $implementation];
    }
}
