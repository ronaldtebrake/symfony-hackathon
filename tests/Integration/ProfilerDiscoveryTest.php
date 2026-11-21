<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\Refund\Fixture;
use App\Demo\Refund\RecordingProfiler;
use App\Demo\Refund\Tools;
use CodeMode\Symfony\Profiler\CatalogDataCollector;
use CodeMode\Symfony\Profiler\CatalogTrace;
use CodeMode\ToolDefinition;
use Mcp\Schema\JsonRpc\Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;

final class ProfilerDiscoveryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mcp-discovery-profile-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('errors')]
    public function testMatchedDiscoveryErrorOnlyRecordsPublicReason(string $method, int $code, string $reason): void
    {
        $request = $this->request($method);
        $reply = ['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => $code, 'message' => 'PRIVATE_MESSAGE', 'data' => ['name' => 'PRIVATE_TOOL']]];
        $trace = new CatalogTrace();
        foreach ([['id' => 8], ['jsonrpc' => '1.0'], ['result' => ['tools' => []]]] as $invalid) {
            $this->assertNull(RecordingProfiler::capture($this->directory, $request, new JsonResponse(array_replace($reply, $invalid)), $trace, 2.5));
        }
        $token = RecordingProfiler::capture($this->directory, $request, new JsonResponse($reply), $trace, 2.5);
        $this->assertNotNull($token);
        $this->assertSame([['operation' => $method, 'status' => 'failed', 'budget' => [], 'calls' => [], 'nativeEvents' => [],
            'durationMs' => 2.5, 'reason' => $reason]], RecordingProfiler::operations($this->directory, $token));
    }

    public static function errors(): iterable
    {
        yield ['groups/get', Error::RESOURCE_NOT_FOUND, 'resource_not_found'];
        yield ['groups/list', Error::MISSING_REQUIRED_CLIENT_CAPABILITY, 'missing_client_capability'];
        yield ['tools/list', Error::INVALID_PARAMS, 'invalid_params'];
        yield ['groups/get', Error::SERVER_ERROR, 'discovery_failed'];
    }

    public function testDisclosedSchemaMustEqualTheReviewedLocalContract(): void
    {
        $trace = new CatalogTrace();
        $known = new ToolDefinition('refund_get_order', 'Read the order.', (object) ['type' => 'object', 'properties' => (object) []]);
        $trace->registerLocalContract($known);
        $definition = $known->jsonSerialize();
        $returned = [
            array_replace($definition, ['name' => 'PRIVATE_TOOL']),
            array_replace($definition, ['description' => 'PRIVATE_DESCRIPTION']),
            array_replace($definition, ['inputSchema' => (object) ['type' => 'object', 'default' => 'PRIVATE_DEFAULT']]),
            $definition + ['_meta' => ['secret' => 'PRIVATE_METADATA']],
        ];
        $result = ['jsonrpc' => '2.0', 'id' => 7, 'result' => ['tools' => $returned]];
        $token = RecordingProfiler::capture($this->directory, $this->request('tools/list'), new JsonResponse($result), $trace, 0);
        $this->assertNotNull($token);
        $page = RecordingProfiler::operations($this->directory, $token)[0]['page'];
        $this->assertSame(4, $page['tools']);
        $this->assertEquals([$definition], $page['toolDefinitions']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($page, \JSON_THROW_ON_ERROR));
    }

    public function testOnlyTheDisclosedReviewedGroupProcedureAppearsInTheProfile(): void
    {
        $guide = Fixture::instructions();
        $tools = array_map(static fn (string $name): ToolDefinition => new ToolDefinition($name, 'Read.', (object) ['type' => 'object']), Tools::NAMES);
        foreach ([
            ['groups/get', 'refund-review', $guide, $guide],
            ['groups/get', 'refund-review', 'PRIVATE_REPLACEMENT', null],
            ['groups/get', 'refund-review', null, null],
            ['groups/get', 'policy-lookup', $guide, null],
            ['groups/list', 'refund-review', $guide, null],
        ] as [$method, $name, $instructions, $expected]) {
            $trace = new CatalogTrace();
            foreach ($tools as $tool) {
                $trace->registerLocalContract($tool);
            }
            $result = ['tools' => $tools];
            if (null !== $instructions) {
                $result['instructions'] = $instructions;
            }
            $response = new JsonResponse(['jsonrpc' => '2.0', 'id' => 7, 'result' => $result]);
            $token = RecordingProfiler::capture($this->directory, $this->request($method, $name), $response, $trace, 0);
            $this->assertNotNull($token);
            $op = RecordingProfiler::operations($this->directory, $token)[0];
            $this->assertSame($expected, $op['page']['instructions'] ?? null);
            $this->assertSame([], $op['calls']);
            $this->assertSame([], $op['nativeEvents']);
        }
    }

    public function testRecordedExchangeStoresOnlyReviewedFixtureValues(): void
    {
        $trace = new CatalogTrace();
        $args = ['calls' => [['name' => 'refund_get_order', 'arguments' => ['orderId' => 'R-1042']]]];
        $request = Request::create('/mcp', 'POST', content: json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
            'params' => ['name' => 'execute_tools', 'arguments' => $args]], \JSON_THROW_ON_ERROR));
        $value = ['orderId' => 'R-1042', 'policyId' => 'standard-eu', 'amountMinor' => 'PRIVATE_AMOUNT', 'notes' => 'PRIVATE_NOTES'];
        foreach ([false, true] as $failed) {
            $trace->reset();
            $trace->begin('execute_tools');
            $trace->finish([], $failed, 1);
            $result = ['isError' => $failed, 'structuredContent' => ['results' => [['name' => 'refund_get_order', 'value' => $value]]]];
            $token = RecordingProfiler::capture($this->directory, $request, new JsonResponse(['jsonrpc' => '2.0', 'id' => 7, 'result' => $result]), $trace, 1);
            $profile = (new FileProfilerStorage('file:'.$this->directory.'/profiles'))->read($token);
            $this->assertTrue($profile->hasCollector('mcp'));
            $collector = $profile->getCollector('code_mode');
            $this->assertInstanceOf(CatalogDataCollector::class, $collector);
            $exchange = $collector->getExchange();
            $this->assertSame($args['calls'][0], $exchange['Requested read']);
            $this->assertSame($failed ? null : ['orderId' => 'R-1042', 'policyId' => 'standard-eu'], $exchange['Returned fields'] ?? null);
            $this->assertStringNotContainsString('PRIVATE', serialize($profile));
        }
    }

    private function request(string $method, string $name = 'PRIVATE_GROUP'): Request
    {
        $message = ['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => ['name' => $name]];

        return Request::create('/mcp', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer PRIVATE_TOKEN'], content: json_encode($message, \JSON_THROW_ON_ERROR));
    }
}
