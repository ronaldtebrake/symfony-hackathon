<?php

declare(strict_types=1);

use CodeMode\{ExecutionIdentity, SandboxException, ToolDefinition};
use CodeMode\Provider\{ProviderRegistry, ToolBinding, ToolProviderInterface};
use PHPUnit\Framework\TestCase;
use SurfaceProof\ObservedProvider;

require_once dirname(__DIR__).'/src/ObservedProvider.php';

final class ObservedProviderTest extends TestCase
{
    public function testObservationPreservesAuthorizationSchemaAndActualResults(): void
    {
        $provider = new class implements ToolProviderInterface {
            public int $invocations = 0;
            public function name(): string { return 'drupal'; }
            public function source(): string { return 'mcp'; }
            public function tools(): iterable
            {
                $schema = json_decode('{"type":"object","properties":{"limit":{"type":"integer","maximum":10}},"required":["limit"],"additionalProperties":false}');
                yield new ToolBinding('search', new ToolDefinition('upstream', 'Search', $schema),
                    static fn ($identity) => $identity->allows('read'),
                    function ($args) { ++$this->invocations; return ['matches' => [], 'count' => 0]; });
            }
        };
        $journal = tmpfile();
        try {
            $registry = new ProviderRegistry([new ObservedProvider($provider, $journal)]);
            foreach ([[new ExecutionIdentity('reader', []), 1, 'tool_denied'], [new ExecutionIdentity('reader', ['read']), 11, 'invalid_arguments']] as [$identity, $limit, $reason]) {
                try {
                    $registry->broker()->call('drupal.search', (object) ['limit' => $limit], $identity, ['drupal.search']);
                    self::fail('A forbidden call passed.');
                } catch (SandboxException $error) { self::assertSame($reason, $error->reason); }
            }
            self::assertSame(0, $provider->invocations);
            rewind($journal);
            self::assertSame('', stream_get_contents($journal));
            $value = $registry->broker()->call('drupal.search', (object) ['limit' => 3], new ExecutionIdentity('reader', ['read']), ['drupal.search']);
            self::assertSame(1, $provider->invocations);
            rewind($journal);
            $event = json_decode(stream_get_contents($journal), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($value, $event['value']);
            self::assertSame(['limit' => 3], $event['arguments']);
            self::assertSame('upstream', $event['upstreamTool']);
            self::assertSame('drupal.search', $event['name']);
        } finally { fclose($journal); }
    }
}
