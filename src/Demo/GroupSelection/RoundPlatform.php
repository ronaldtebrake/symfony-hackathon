<?php

declare(strict_types=1);

namespace App\Demo\GroupSelection;

use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\Tool;

/** Symfony AI snapshots tools at the start of an agent turn. Refresh both arms on each provider request. */
final class RoundPlatform implements PlatformInterface
{
    public array $inputs = [];

    public function __construct(private readonly PlatformInterface $platform, private readonly ToolboxInterface $toolbox)
    {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $options['tools'] = $this->toolbox->getTools();
        $definitions = array_map(static fn (Tool $tool): array => ['name' => $tool->getName(), 'description' => $tool->getDescription(), 'parameters' => $tool->getParameters()], $options['tools']);
        $request = \count($this->inputs);
        $deferred = $this->platform->invoke($model, $input, $options);
        $this->inputs[] = ['request' => $request + 1, 'definitions' => $definitions, 'modelCalls' => []];
        $deferred->onConvert(function (ResultInterface $result) use ($request): ResultInterface {
            $tools = $result instanceof MultiPartResult ? $result->asToolCallResult() : $result;
            if ($tools instanceof ToolCallResult) {
                $this->inputs[$request]['modelCalls'] = array_values(array_map(static fn (ToolCall $call): array => ['name' => $call->getName(), 'arguments' => $call->getArguments()], $tools->getContent()));
            }

            return $result;
        });

        return $deferred;
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform->getModelCatalog();
    }
}
