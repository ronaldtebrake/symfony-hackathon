<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\{AgentResponse, ExecutionProfile, ExecutionResult, SandboxException};
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Replay presentation over retained executions. Never runs a tool, PHP guest or model. */
final readonly class ResponsePayloadComparison
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir) {}

    public function samples(): array
    {
        $samples = [];
        $adoption = $this->read('adoption-proof.json');
        foreach (['probe' => 'Symfony boundary recovery', 'liveTrace' => 'Symfony AI adoption'] as $section => $label) {
            foreach ($adoption[$section]['agentCalls'] as $index => $call) {
                if ('php_execute' !== $call['name']) { continue; }
                $samples[] = $this->sample($label, 'adoption-proof.json', $section.'/agentCalls/'.$index, $call['result']);
            }
        }
        foreach ($this->read('codex-conversation-2026-11-01.json')['turns'] as $turnIndex => $turn) {
            foreach ($turn['items'] as $index => $item) {
                if ('php_execute' !== ($item['tool'] ?? null)) { continue; }
                // The capture retained structuredContent, not the client's model prompt serialization.
                $samples[] = $this->sample('Codex MCP structured result', 'codex-conversation-2026-11-01.json', 'turns/'.$turnIndex.'/items/'.$index,
                    json_encode($item['result']['structuredContent'], JSON_THROW_ON_ERROR));
            }
        }
        foreach ($this->read('huggingface-pair.json')['code']['result']['agentCalls'] as $index => $call) {
            if ('php_execute' !== $call['name']) { continue; }
            $samples[] = $this->sample('Hugging Face audit', 'huggingface-pair.json', 'code/result/agentCalls/'.$index, $call['result']);
        }
        return $samples;
    }

    /** Attach token counts only if they still match the exact source and current formatter. */
    public function report(): array
    {
        $saved = $this->read('lean-responses.json');
        $samples = $this->samples();
        $valid = count($samples) === count($saved['samples'] ?? []);
        foreach ($samples as $index => &$sample) {
            $measured = $saved['samples'][$index] ?? [];
            foreach (['sourceSha256', 'beforeSha256', 'afterSha256', 'beforeBytes', 'afterBytes'] as $key) {
                $valid = $valid && ($sample[$key] === ($measured[$key] ?? null));
            }
            $sample['beforeTokens'] = $measured['beforeTokens'] ?? null;
            $sample['afterTokens'] = $measured['afterTokens'] ?? null;
            $valid = $valid && is_int($sample['beforeTokens']) && is_int($sample['afterTokens']);
        }
        unset($sample);
        return ['status' => $valid ? 'verified' : 'stale', 'measurement' => $saved['measurement'], 'samples' => $samples];
    }

    private function sample(string $group, string $file, string $pointer, string $before): array
    {
        $old = json_decode($before, true, 64, JSON_THROW_ON_ERROR);
        $calls = is_array($old['toolCalls'] ?? null) ? $old['toolCalls'] : [];
        if (isset($old['error'])) {
            $new = AgentResponse::failure(new SandboxException($old['error']['reason'], '', $calls), ExecutionProfile::Gvisor);
        } else {
            $value = array_key_exists('value', $old) ? $old['value'] : $old['result'];
            $new = AgentResponse::success(new ExecutionResult($value, $calls, 0, 0, 'replay', ExecutionProfile::Gvisor));
        }
        $after = json_encode($new, JSON_THROW_ON_ERROR);
        return ['group' => $group, 'source' => 'docs/evidence/'.$file, 'pointer' => $pointer,
            'sourceSha256' => hash_file('sha256', $this->projectDir.'/docs/evidence/'.$file),
            'before' => $before, 'after' => $after, 'beforeSha256' => hash('sha256', $before), 'afterSha256' => hash('sha256', $after),
            'beforeBytes' => strlen($before), 'afterBytes' => strlen($after)];
    }

    private function read(string $file): array
    {
        return json_decode(file_get_contents($this->projectDir.'/docs/evidence/'.$file), true, 64, JSON_THROW_ON_ERROR);
    }
}
