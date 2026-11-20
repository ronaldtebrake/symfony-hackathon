<?php

declare(strict_types=1);

namespace App\Demo\GroupSelection;

use App\Demo\Groups\PublishingGroups;
use App\Demo\Mcp\StudyToolset;
use App\Demo\Refund\Fixture;
use App\Demo\RunTrace;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Symfony\AI\Agent\Bridge\Mcp\McpToolbox;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;

/** Host presentation for the experiment. open_group is a local action that sends groups/get. */
final class Host implements ToolsetInterface
{
    public array $calls = [];
    public array $selected = [];
    public readonly McpToolbox $toolbox;
    private ?Tool $open = null;
    private ?StudyToolset $members = null;
    private array $groups = [];

    public function __construct(private readonly Fixture $fixture, private readonly RunTrace $trace, private readonly bool $guided = false)
    {
        $this->toolbox = new McpToolbox($this);
    }

    public function discover(string $arm): void
    {
        $capabilities = $this->rpc('server/discover')['response']['result']['capabilities'] ?? [];
        if ('catalog' === $arm) {
            $this->members = $this->toolset($this->rpc('tools/list')['response']['result'] ?? [], Discovery::CATALOG);

            return;
        }
        if (!isset($capabilities['extensions'][PublishingGroups::ID])) {
            throw new \UnexpectedValueException('The server did not advertise Groups.');
        }
        $groups = $this->rpc('groups/list')['response']['result']['groups'] ?? [];
        $this->groups = array_column($groups, 'description', 'name');
        if ([] === $this->groups || array_diff(array_keys($this->groups), array_keys(Discovery::GROUPS))) {
            throw new \UnexpectedValueException('Unexpected group listing.');
        }
        $this->open = new Tool('open_group', null, ['type' => 'object', 'required' => ['name'], 'additionalProperties' => false,
            'properties' => ['name' => ['type' => 'string', 'enum' => array_keys($this->groups)]]],
            'Open one listed tool group for this task. You can switch groups. Available groups: '.json_encode($this->groups, \JSON_THROW_ON_ERROR), null);
    }

    public function getName(): string
    {
        return 'symfony';
    }

    public function getTools(): array
    {
        return [...(null === $this->open ? [] : [$this->open]), ...($this->members?->getTools() ?? [])];
    }

    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        if ('open_group' !== $name) {
            if (null === $this->members) {
                throw new \RuntimeException('No group is open.');
            }

            return $this->members->callTool($name, $arguments);
        }
        $selected = $arguments['name'] ?? null;
        if (null === $this->open || !\is_string($selected) || !isset($this->groups[$selected]) || ['name'] !== array_keys($arguments)) {
            throw new \UnexpectedValueException('Unknown group.');
        }
        $response = $this->rpc('groups/get', ['name' => $selected])['response'];
        $this->members = null;
        $this->toolbox->reset();
        $result = $response['result'] ?? [];
        $expectedGuide = $this->guided && 'refund-review' === $selected ? Fixture::instructions() : null;
        if (!isset($result['tools']) || ($result['instructions'] ?? null) !== $expectedGuide || isset($result['resources'])) {
            return CallToolResult::error([new TextContent('Group unavailable.')]);
        }
        $names = array_column($result['tools'], 'name');
        if ($names !== Discovery::GROUPS[$selected]['members']) {
            throw new \UnexpectedValueException('Unexpected group member.');
        }
        $this->members = $this->toolset($result, $names);
        $this->selected[] = $selected;

        $opened = ['available' => $names];
        if (null !== $expectedGuide) {
            $opened['instructions'] = $result['instructions'];
        }

        return new CallToolResult([new TextContent(json_encode($opened, \JSON_THROW_ON_ERROR))]);
    }

    private function rpc(string $method, array $params = []): array
    {
        if (\count($this->calls) >= 20) {
            throw new \RuntimeException('Study transport budget exhausted.');
        }
        $receipt = $this->fixture->rpc('reader', $method, $params, true);
        $receipt['modelRequest'] = $this->trace->modelRequests;
        $this->calls[] = $receipt;

        return $receipt;
    }

    private function toolset(array $listing, array $names): StudyToolset
    {
        return new StudyToolset($listing, $names, 12, fn (string $name, array $arguments): array => $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]));
    }
}
