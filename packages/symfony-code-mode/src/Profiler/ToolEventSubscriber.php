<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Profiler;

use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Observe native Symfony AI execution without inspecting tool arguments or results. */
final readonly class ToolEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private CatalogTrace $trace)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ToolCallRequested::class => ['requested', 1024], ToolCallSucceeded::class => 'succeeded', ToolCallFailed::class => 'failed'];
    }

    public function requested(ToolCallRequested $event): void
    {
        $this->trace->nativeEvent($event->getToolCall()->getName(), 'requested');
    }

    public function succeeded(ToolCallSucceeded $event): void
    {
        $this->trace->nativeEvent($event->getDefinition()->getName(), 'succeeded');
    }

    public function failed(ToolCallFailed $event): void
    {
        $this->trace->nativeEvent($event->getDefinition()->getName(), 'failed');
    }
}
