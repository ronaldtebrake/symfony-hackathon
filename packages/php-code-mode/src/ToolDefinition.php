<?php

declare(strict_types=1);

namespace CodeMode;

use Mcp\Schema\Tool as McpTool;

/** Metadata from an upstream tool service. Treat descriptions and schemas as untrusted data. */
final readonly class ToolDefinition implements \JsonSerializable
{
    public function __construct(public string $name, public string $description, public \stdClass $inputSchema, public ?\stdClass $outputSchema = null)
    {
        if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]{0,63}\z/D', $name)
            || \strlen($description) > 2048
            || \strlen(json_encode([$inputSchema, $outputSchema], \JSON_THROW_ON_ERROR, 32)) > 32768) {
            throw new \InvalidArgumentException('Tool metadata exceeds the supported bounds.');
        }
    }

    /** Import the SDK's normalized contracts, applying the same bounds as local definitions. */
    public static function fromMcpTool(McpTool $tool): self
    {
        $asObject = static fn (array $schema): \stdClass => json_decode(json_encode((object) $schema, \JSON_THROW_ON_ERROR, 32), false, 32, \JSON_THROW_ON_ERROR);

        return new self($tool->name, $tool->description ?? '', $asObject($tool->inputSchema), null === $tool->outputSchema ? null : $asObject($tool->outputSchema));
    }

    public function jsonSerialize(): array
    {
        $data = ['name' => $this->name, 'description' => $this->description, 'inputSchema' => $this->inputSchema];
        if (null !== $this->outputSchema) {
            $data['outputSchema'] = $this->outputSchema;
        }

        return $data;
    }
}
