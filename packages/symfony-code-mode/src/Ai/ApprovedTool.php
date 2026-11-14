<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Ai;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\ReadToolInterface;
use CodeMode\ToolDefinition;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;

/** @internal Invokes installed handlers on the host, never inside the generated-PHP sandbox. */
final readonly class ApprovedTool implements ReadToolInterface
{
    public function __construct(private ToolboxInterface $toolbox, private ToolDefinition $tool, private ToolAuthorizationInterface $authorization)
    {
    }

    public function definition(): ToolDefinition
    {
        return $this->tool;
    }

    public function authorize(ExecutionIdentity $identity, \stdClass $arguments): bool
    {
        return $this->authorization->isGranted($identity, $this->tool->name, $arguments);
    }

    public function invoke(\stdClass $arguments, ExecutionIdentity $identity): mixed
    {
        if (!$this->authorize($identity, $arguments)) {
            throw new \RuntimeException('Read authorization changed.');
        }
        // Preserve the application's argument resolver and tool lifecycle listeners.
        $result = $this->toolbox->execute(new ToolCall('code-mode-'.bin2hex(random_bytes(8)), $this->tool->name, get_object_vars($arguments)))->getResult();
        $value = json_decode(json_encode($result, \JSON_THROW_ON_ERROR, 32), false, 32, \JSON_THROW_ON_ERROR);
        if (null !== $this->tool->outputSchema && !(new Validator(new SchemaLoader()))->validate($value, $this->tool->outputSchema)->isValid()) {
            throw new \RuntimeException('The tool result does not match its declared output schema.');
        }

        return $value;
    }
}
