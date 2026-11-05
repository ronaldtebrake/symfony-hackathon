<?php

declare(strict_types=1);

namespace App\Demo\Performance;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/** Development-only instrumentation for the synthetic kernel, never the web workbench. */
final class RequestCollector extends DataCollector implements LoggerInterface
{
    use LoggerTrait;

    public const CANARY = 'PRIVATE_PERFORMANCE_CANARY';
    public const QUERY_DELAY_US = 2000;
    private int $started;

    public function reset(): void
    {
        $this->data = ['queries' => [], 'logs' => []];
        $this->started = hrtime(true);
    }

    public function query(\PDO $db, string $fingerprint, string $sql, array $parameters = []): array
    {
        if (count($this->data['queries']) >= 32) { throw new \RuntimeException('Synthetic query limit reached.'); }
        $start = hrtime(true);
        // Deliberate, disclosed latency makes the N+1 pattern visible on an in-memory database.
        usleep(self::QUERY_DELAY_US);
        $statement = $db->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $this->data['queries'][] = ['fingerprint' => $fingerprint, 'sql' => $sql, 'parameters' => $parameters, 'durationMs' => (hrtime(true) - $start) / 1e6];
        return $rows;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (count($this->data['logs']) >= 16) { throw new \RuntimeException('Synthetic log limit reached.'); }
        $this->data['logs'][] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data += ['route' => $request->attributes->get('_route'), 'durationMs' => (hrtime(true) - $this->started) / 1e6,
            'status' => $response->getStatusCode(), 'headers' => $request->headers->all(),
            'outputSha256' => hash('sha256', $response->getContent())];
    }

    public function snapshot(): array { return $this->data; }
    public function getName(): string { return 'code_mode_performance'; }
}
