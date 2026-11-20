<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\Mcp\StudyToolset;
use App\Demo\Refund\Tools;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\ToolCall;

final class StudyToolsetTest extends TestCase
{
    public function testNativeBridgeUsesTheGroupNamesAndReceivesThePermissionChange(): void
    {
        $capture = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/refund-protocol.json'), true, flags: \JSON_THROW_ON_ERROR)['calls'];
        $group = $capture['group_before_skill_revoke']['response']['result'];
        $expected = [$capture['policy'], $capture['denied_policy']];
        $exchanges = 0;
        $study = new StudyToolset($group, Tools::NAMES, 2, function (string $name, array $args) use ($expected, &$exchanges): array {
            $record = $expected[$exchanges++];
            $this->assertSame($record['request']['params']['name'], $name);
            $this->assertSame($record['request']['params']['arguments'], $args);

            return $record;
        });
        $bridge = $study->toolbox();
        $this->assertSame(['symfony_refund_get_order', 'symfony_refund_get_policy'], array_map(static fn ($tool) => $tool->getName(), $bridge->getTools()));
        $call = new ToolCall('one', 'symfony_refund_get_policy', ['policyId' => 'standard-eu']);
        $this->assertSame($expected[0]['response']['result']['structuredContent'], $bridge->execute($call)->getResult());
        $followUp = new ToolCall('two', $call->getName(), $call->getArguments());
        $this->assertSame($expected[1]['response']['result']['structuredContent'], json_decode($bridge->execute($followUp)->getResult(), true, flags: \JSON_THROW_ON_ERROR));
        $this->assertSame($expected, $study->calls);
        $this->assertIsString($bridge->execute($followUp)->getResult());
        $this->assertSame(2, $exchanges);
    }

    public function testMalformedAndFailedCallsCannotDiscloseTheirDetailsOrRenewTheBudget(): void
    {
        $listing = ['tools' => [['name' => 'read', 'inputSchema' => ['type' => 'object']]]];
        $exchanges = 0;
        $study = new StudyToolset($listing, ['read'], 3, static function () use (&$exchanges): array {
            ++$exchanges;
            if (1 === $exchanges) {
                return ['httpStatus' => 200, 'response' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                    'structuredContent' => ['value' => 'public'],
                    'content' => [['type' => 'text', 'text' => '{"value":"public"}'], ['type' => 'text', 'text' => 'private diagnostics']],
                ]]];
            }
            if (2 === $exchanges) {
                return ['httpStatus' => 400, 'response' => ['jsonrpc' => '2.0', 'id' => 2, 'error' => ['code' => -32602, 'message' => 'private diagnostics']]];
            }
            throw new \RuntimeException('private transport details');
        });
        $bridge = $study->toolbox();
        $call = new ToolCall('one', 'symfony_read');
        $this->assertStringContainsString('was not found', $bridge->execute(new ToolCall('unknown', 'symfony_other'))->getResult());
        $this->assertStringNotContainsString('private', $bridge->execute($call)->getResult());
        $this->assertSame(['error' => ['jsonRpcCode' => -32602]], json_decode($bridge->execute($call)->getResult(), true, flags: \JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private', $bridge->execute($call)->getResult());
        $this->assertStringNotContainsString('private', $bridge->execute($call)->getResult());
        $this->assertSame(3, $exchanges);
    }

    public function testExtraOrPaginatedContractsCannotEnterTheStudy(): void
    {
        $definition = ['name' => 'read', 'inputSchema' => ['type' => 'object']];
        foreach ([['tools' => [$definition], 'nextCursor' => 'more'], ['tools' => [$definition, $definition]], ['tools' => []]] as $listing) {
            try {
                new StudyToolset($listing, ['read'], 1, static fn () => throw new \LogicException());
                $this->fail('The unexpected listing was accepted.');
            } catch (\UnexpectedValueException $error) {
                $this->assertStringContainsString('tool', $error->getMessage());
            }
        }
    }
}
