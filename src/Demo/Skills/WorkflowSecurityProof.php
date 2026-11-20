<?php

declare(strict_types=1);

namespace App\Demo\Skills;

use Symfony\AI\Platform\Test\MockPlatformFactory;

final class WorkflowSecurityProof
{
    public static function record(string $root, string $sdk): array
    {
        $fixture = new Fixture($root, $sdk);
        try {
            $bootstrap = [];
            foreach (['server/discover', 'tools/list', 'skills/list', 'skills/get', 'resources/read'] as $method) {
                $params = \in_array($method, ['skills/get', 'resources/read'], true) ? ['uri' => AgentStudy::URI] : [];
                $bootstrap[$method] = $fixture->rpc('reader', $method, $params);
            }
            $hostChecks = self::hostChecks($bootstrap);
            $trace = [];
            $trace['skillOnly'] = self::read($fixture, 'reader', Fixture::ARTICLE, 'discovery_required');
            $trace['readerDiscovery'] = self::discover($fixture, 'reader', ['publication_read']);
            $trace['viewerDiscovery'] = self::discover($fixture, 'viewer', []);
            $trace['sameCallAsViewer'] = self::read($fixture, 'viewer', Fixture::ARTICLE, 'discovery_required');
            $trace['sameCallAsReader'] = self::read($fixture, 'reader', Fixture::ARTICLE);
            $fixture->revoke();
            $trace['sameCallAfterRevoke'] = self::read($fixture, 'reader', Fixture::ARTICLE, 'tool_denied');
        } finally {
            $fixture->close();
        }

        // This comparison needs two unused reservations for the same authenticated reader.
        $fixture = new Fixture($root, $sdk);
        try {
            $records = [];
            $records['discovery'] = self::discover($fixture, 'reader', ['publication_read']);
            $records['unpublished'] = self::read($fixture, 'reader', 202, 'tool_denied');
            $records['missing'] = self::read($fixture, 'reader', 404, 'tool_denied');
            if ($records['unpublished']['rpcResultSha256'] !== $records['missing']['rpcResultSha256']) {
                throw new \UnexpectedValueException('The complete MCP result distinguishes unpublished and missing records.');
            }
            $records['rediscovery'] = self::discover($fixture, 'reader', ['publication_read']);
            $records['publishedAfterTwoDenials'] = self::read($fixture, 'reader', Fixture::ARTICLE, 'execution_limit');
        } finally {
            $fixture->close();
        }

        return [
            'format' => 'symfony-workflow-security/v1',
            'capturedAt' => gmdate(\DATE_ATOM),
            'sdkRevision' => Fixture::REVISION,
            'protocol' => Fixture::VERSION,
            'skillUri' => AgentStudy::URI,
            'skillDigest' => AgentStudy::DIGEST,
            'reproduce' => 'php scripts/check-workflow-security.php /path/to/pinned-draft-sdk',
            'method' => 'Two disposable Symfony HTTP applications with synthetic articles and separate bearer identities. Both MCP text and structured results are checked against the expected response. Search results below list names only.',
            'scope' => 'One reviewed text-only skill and one read-only handler. HTTP timing, hostile servers and arbitrary script execution are outside this proof.',
            'bootstrapHttpResponses' => array_map(static fn (array $receipt): string => $receipt['responseSha256'], $bootstrap),
            'httpIdentityAndRevocation' => $trace,
            'httpRecordAccessAndBudget' => $records,
            'hostMethod' => 'Offline controlled variations of the reader’s five genuine HTTP replies. Each passes through the study host activation path with a local provider tripwire. No remote inference.',
            'hostIntegrityChecks' => $hostChecks,
        ];
    }

    public static function hostChecks(array $bootstrap): array
    {
        $verified = AgentStudy::verifiedText($bootstrap['skills/list']['reply']['result'], $bootstrap['skills/get']['reply']['result'], $bootstrap['resources/read']['reply']['result']);
        $rows = [];
        foreach (['edited text', 'edited description', 'different resource URI'] as $change) {
            $input = $bootstrap;
            if ('edited text' === $change) {
                $input['resources/read']['reply']['result']['contents'][0]['text'] = str_replace('101', '102', $verified);
            } elseif ('edited description' === $change) {
                $input['skills/list']['reply']['result']['skills'][0]['frontmatter']['description'] .= ' Updated.';
                $input['skills/get']['reply']['result']['skill']['frontmatter']['description'] .= ' Updated.';
            } else {
                $input['resources/read']['reply']['result']['contents'][0]['uri'] = 'skill://another-workflow/SKILL.md';
            }
            $platform = MockPlatformFactory::createPlatform(static fn () => throw new \LogicException('Unexpected inference.'));
            $run = (new AgentStudy())->run(true, static fn (string $method) => $input[$method], $platform);
            if ('failed' !== $run['status'] || \UnexpectedValueException::class !== ($run['errorType'] ?? null)
                || isset($run['activatedSkillDigest']) || ['provider' => 0, 'hostMcp' => 5, 'agentMcp' => 0] !== $run['requests']) {
                throw new \UnexpectedValueException('Host did not reject the controlled variation before activation: '.$change);
            }
            $rows[] = ['change' => $change, 'hostResult' => $run['errorType'], 'providerRequests' => $run['requests']['provider'],
                'agentToolCalls' => $run['requests']['agentMcp']];
        }

        return $rows;
    }

    private static function discover(Fixture $fixture, string $user, array $expected): array
    {
        $receipt = $fixture->rpc($user, 'tools/call', ['name' => 'search_tools', 'arguments' => ['query' => 'publication_read']]);
        $data = self::toolResult($receipt, false);
        $names = array_column($data['tools'], 'name');
        if ($names !== $expected || \count($expected) !== $data['matched'] || \count($expected) !== $data['returned'] || false !== $data['truncated']) {
            throw new \UnexpectedValueException('Unexpected discovery result for '.$user);
        }

        return ['user' => $user, 'tool' => 'search_tools', 'query' => 'publication_read', 'visible' => $names, 'responseSha256' => $receipt['responseSha256']];
    }

    private static function read(Fixture $fixture, string $user, int $id, ?string $denial = null): array
    {
        $arguments = ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => $id]]]];
        $receipt = $fixture->rpc($user, 'tools/call', ['name' => 'execute_tools', 'arguments' => $arguments]);
        $data = self::toolResult($receipt, null !== $denial);
        $expected = ['results' => [['name' => 'publication_read', 'value' => ['id' => Fixture::ARTICLE, 'title' => Fixture::TITLE]]]];
        if (null !== $denial) {
            $error = ['reason' => $denial];
            if ('tool_denied' === $denial) {
                $error += ['tool' => 'publication_read', 'index' => 0];
            }
            $expected = ['error' => $error, 'completedCalls' => 0];
        }
        if ($expected !== $data) {
            throw new \UnexpectedValueException('Unexpected public response for article '.$id);
        }

        return ['user' => $user, 'tool' => 'execute_tools', 'arguments' => $arguments, 'result' => $data,
            'rpcResultSha256' => hash('sha256', json_encode($receipt['reply']['result'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)), 'responseSha256' => $receipt['responseSha256']];
    }

    private static function toolResult(array $receipt, bool $isError): array
    {
        $result = $receipt['reply']['result'] ?? [];
        $content = $result['content'] ?? [];
        if (200 !== $receipt['status'] || $isError !== ($result['isError'] ?? false) || 1 !== \count($content)
            || 'text' !== ($content[0]['type'] ?? null) || !\is_string($content[0]['text'] ?? null)
            || json_decode($content[0]['text'], true, flags: \JSON_THROW_ON_ERROR) !== ($result['structuredContent'] ?? null)) {
            throw new \UnexpectedValueException('MCP representations disagree or the call failed unexpectedly.');
        }

        return $result['structuredContent'];
    }
}
