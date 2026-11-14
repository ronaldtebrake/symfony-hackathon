<?php

declare(strict_types=1);

namespace CodeMode;

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

    public function jsonSerialize(): array
    {
        $data = ['name' => $this->name, 'description' => $this->description, 'inputSchema' => $this->inputSchema];
        if (null !== $this->outputSchema) {
            $data['outputSchema'] = $this->outputSchema;
        }

        return $data;
    }
}
