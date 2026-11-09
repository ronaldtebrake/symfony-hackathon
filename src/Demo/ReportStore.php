<?php

declare(strict_types=1);

namespace App\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ReportStore
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir, private bool $reviewedOnly = false) {}

    public function save(array $report): void
    {
        $this->saveFile('latest.json', $report);
    }

    public function saveSecurityProof(array $report): void
    {
        $this->saveFile('security.json', $report);
    }

    public function saveAdoptionProof(array $report): void
    {
        $this->saveFile('adoption.json', $report);
    }

    public function saveDiscoveryComparison(array $report): void { $this->saveFile('discovery.json', $report, 4 * 1048576); }

    public function readDiscoveryComparison(): ?string
    {
        $local = $this->projectDir.'/var/demo/discovery.json';
        return $this->readPath(!$this->reviewedOnly && is_file($local) ? $local : $this->projectDir.'/docs/evidence/compact-discovery.json', 4 * 1048576);
    }

    public function saveEvaluation(array $report): void
    {
        $this->saveFile('evaluation.json', $report, 8 * 1048576);
    }

    public function savePerformanceProof(array $report): void
    {
        $this->saveFile('performance.json', $report);
    }

    public function savePerformanceConversation(array $report): void { $this->saveFile('performance-conversation.json', $report, 4 * 1048576); }

    public function savePerformanceBaseline(array $report): void { $this->saveFile('performance-baseline.json', $report, 4 * 1048576); }

    public function readPerformanceBaseline(): ?string
    {
        $local = $this->projectDir.'/var/demo/performance-baseline.json';
        return $this->readPath(!$this->reviewedOnly && is_file($local) ? $local : $this->projectDir.'/docs/evidence/performance-baseline.json', 4 * 1048576);
    }

    public function readPerformanceConversation(): ?string
    {
        return $this->readLocalPerformanceConversation() ?? $this->readPath($this->projectDir.'/docs/evidence/performance-conversation.json', 4 * 1048576);
    }

    public function readLocalPerformanceConversation(): ?string
    {
        return $this->reviewedOnly ? null : $this->readPath($this->projectDir.'/var/demo/performance-conversation.json', 4 * 1048576);
    }

    public function readPerformanceProof(): ?string
    {
        return $this->readLocalPerformanceProof() ?? $this->readPath($this->projectDir.'/docs/evidence/performance-proof.json');
    }

    public function readLocalPerformanceProof(): ?string { return $this->readFile('performance.json'); }

    public function readEvaluation(): ?string
    {
        $local = $this->projectDir.'/var/demo/evaluation.json';
        return $this->readPath(!$this->reviewedOnly && is_file($local) ? $local : $this->projectDir.'/docs/evidence/evaluation.json', 8 * 1048576);
    }

    public function readAdoptionProof(): ?string
    {
        return $this->readFile('adoption.json') ?? $this->readPath($this->projectDir.'/docs/evidence/adoption-proof.json');
    }

    public function saveHuggingFaceProof(array $report): void
    {
        $this->saveFile('huggingface.json', $report);
    }

    public function readHuggingFaceProof(): ?string
    {
        if (null !== $pair = $this->readHuggingFacePair()) {
            return null === ($pair['code'] ?? null) ? json_encode(['status' => $pair['status'], 'result' => null], JSON_THROW_ON_ERROR) : HuggingFaceComparison::encodeCapture($pair['code']);
        }
        return $this->readFile('huggingface.json') ?? $this->readPath($this->projectDir.'/docs/evidence/huggingface-proof.json');
    }

    public function saveHuggingFacePair(array $pair): void
    {
        $this->saveFile('huggingface-pair.json', $pair);
    }

    public function readHuggingFacePair(): ?array
    {
        $bytes = $this->readFile('huggingface-pair.json') ?? $this->readPath($this->projectDir.'/docs/evidence/huggingface-pair.json');
        return null === $bytes ? null : json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }

    public function saveHuggingFaceBaseline(array $report): void
    {
        $this->saveFile('huggingface-baseline.json', $report);
    }

    public function readHuggingFaceBaseline(): ?string
    {
        return $this->readFile('huggingface-baseline.json') ?? $this->readPath($this->projectDir.'/docs/evidence/huggingface-baseline.json');
    }

    public function readHuggingFaceComparison(): string
    {
        if (null !== $pair = $this->readHuggingFacePair()) {
            if (null === ($pair['code'] ?? null) || null === ($pair['direct'] ?? null)) {
                return json_encode(['status' => $pair['status'], 'verifiedComparison' => false], JSON_THROW_ON_ERROR);
            }
            return json_encode((new HuggingFaceComparison())->build(HuggingFaceComparison::encodeCapture($pair['code']), $pair['direct']), JSON_THROW_ON_ERROR);
        }
        $code = $this->readPath($this->projectDir.'/docs/evidence/huggingface-proof.json');
        $direct = $this->readHuggingFaceBaseline();
        if (null === $code || null === $direct) {
            return '{"status":"empty","verifiedComparison":false}';
        }
        return json_encode((new HuggingFaceComparison())->build($code, json_decode($direct, true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
    }

    public function readThreeWayAudit(): array
    {
        $reviewed = $this->readPath($this->projectDir.'/docs/evidence/audit-three-way.json', 16 * 1048576);
        $reviewedReport = null === $reviewed ? [] : json_decode($reviewed, true, 64, JSON_THROW_ON_ERROR);
        $attempts = [];
        foreach ($reviewedReport['attempts'] ?? [] as $attempt) {
            $attempts[$attempt['id']] = $attempt;
        }
        // Fixed host paths only. Unreviewed local attempts never enter a Pages build.
        if (!$this->reviewedOnly) {
            $paths = glob($this->projectDir.'/var/demo/audit-three-way/*.json');
            if (count($paths) > 100) { throw new \RuntimeException('Too many audit captures to display together.'); }
            $size = 0;
            foreach ($paths as $path) {
                if (!preg_match('/\A\d{8}-\d{6}-[a-f0-9]{8}\.json\z/D', basename($path))) { continue; }
                $data = $this->readPath($path, 8 * 1048576);
                $size += strlen($data);
                if ($size > 16 * 1048576) { throw new \RuntimeException('Audit captures exceed the viewer size limit.'); }
                $attempt = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
                $attempts[$attempt['id']] = $attempt;
            }
        }
        ksort($attempts);
        return ['version' => 1, 'kind' => ThreeWayAuditRunner::PROTOCOL, 'attempts' => array_values($attempts), 'diagnostics' => $reviewedReport['diagnostics'] ?? []];
    }

    public function readCatalogProof(): array
    {
        $source = $this->readPath($this->projectDir.'/docs/evidence/catalog-proof.json', 8 * 1048576);
        $report = null === $source ? ['version' => 1, 'kind' => 'catalog-proof', 'attempts' => []] : json_decode($source, true, 64, JSON_THROW_ON_ERROR);
        $attempts = array_column($report['attempts'], null, 'id');
        if (!$this->reviewedOnly) {
            $paths = glob($this->projectDir.'/var/demo/catalog-proof/*.json');
            if (count($paths) > 40) { throw new \RuntimeException('Too many catalog captures.'); }
            $bytes = 0;
            foreach ($paths as $path) {
                if (!preg_match('/\A\d{8}-\d{6}-[a-f0-9]{8}\.json\z/D', basename($path))) { continue; }
                $source = $this->readPath($path, 4 * 1048576); $bytes += strlen($source);
                if ($bytes > 8 * 1048576) { throw new \RuntimeException('Catalog evidence exceeds the viewer limit.'); }
                $attempt = json_decode($source, true, 64, JSON_THROW_ON_ERROR);
                // Reviewed annotations resolve interrupted setup status without changing the raw observations.
                if (!isset($attempts[$attempt['id']])) { $attempts[$attempt['id']] = $attempt; }
            }
        }
        ksort($attempts); $report['attempts'] = array_values($attempts);
        return $report;
    }

    private function saveFile(string $filename, array $report, int $maxBytes = 1048576): void
    {
        $directory = $this->projectDir.'/var/demo';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the local report directory.');
        }
        $data = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR, 40);
        if (strlen($data) > $maxBytes) {
            throw new \RuntimeException('The demo report exceeds its size limit.');
        }
        $temporary = tempnam($directory, 'report-');
        try {
            chmod($temporary, 0600);
            if (false === file_put_contents($temporary, $data, LOCK_EX) || !rename($temporary, $directory.'/'.$filename)) {
                throw new \RuntimeException('Cannot save the local report.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function read(): ?string
    {
        // Preserve local failures/running reports; use reviewed evidence only when the file is absent.
        return $this->readFile('latest.json') ?? $this->readPath($this->projectDir.'/docs/evidence/reference-comparison-2026-10-31.json');
    }

    public function readSecurityProof(): ?string
    {
        return $this->readFile('security.json') ?? $this->readPath($this->projectDir.'/docs/evidence/security-proof-2026-11-01.json');
    }

    public function readCodexProof(): ?string
    {
        // A reviewed, checked-in fixture capture. No caller can select a path or import a conversation.
        return $this->readPath($this->projectDir.'/docs/evidence/codex-conversation-2026-11-01.json');
    }

    public function readBoundaryRecovery(): ?string
    {
        return $this->readPath($this->projectDir.'/docs/evidence/boundary-recovery-2026-11-02.json');
    }

    private function readFile(string $filename): ?string
    {
        return $this->reviewedOnly ? null : $this->readPath($this->projectDir.'/var/demo/'.$filename);
    }

    private function readPath(string $path, int $maxBytes = 1048576): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $data = file_get_contents($path, false, null, 0, $maxBytes + 1);
        if (false === $data) {
            throw new \RuntimeException('Cannot read the local report.');
        }
        if (strlen($data) > $maxBytes) {
            throw new \RuntimeException('The local report exceeds its size limit.');
        }
        return $data;
    }
}
