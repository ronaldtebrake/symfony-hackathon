<?php

declare(strict_types=1);

namespace App\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ReportStore
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir) {}

    public function save(array $report): void
    {
        $this->saveFile('latest.json', $report);
    }

    public function saveSecurityProof(array $report): void
    {
        $this->saveFile('security.json', $report);
    }

    private function saveFile(string $filename, array $report): void
    {
        $directory = $this->projectDir.'/var/demo';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the local report directory.');
        }
        $data = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR, 40);
        if (strlen($data) > 1048576) {
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
        return $this->readFile('latest.json');
    }

    public function readSecurityProof(): ?string
    {
        return $this->readFile('security.json');
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
        return $this->readPath($this->projectDir.'/var/demo/'.$filename);
    }

    private function readPath(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $data = file_get_contents($path, false, null, 0, 1048577);
        if (false === $data) {
            throw new \RuntimeException('Cannot read the local report.');
        }
        if (strlen($data) > 1048576) {
            throw new \RuntimeException('The local report exceeds its size limit.');
        }
        return $data;
    }
}
