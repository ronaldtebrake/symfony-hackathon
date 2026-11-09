<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use CodeMode\{BatchSession, ExecutionIdentity, ExecutionLimits};
use CodeMode\Symfony\BatchMcpServerFactory;
use Mcp\Server\Transport\StdioTransport;
use SurfaceProof\Connection;

$site = (string) ($_SERVER['SURFACE_PROOF_SITE'] ?? $_ENV['SURFACE_PROOF_SITE'] ?? getenv('SURFACE_PROOF_SITE') ?: '');
$providers = (new Connection($site))->providers();
$session = new BatchSession($providers->catalog(), $providers->broker(), new ExecutionIdentity('surface-proof-reader', ['published.search']),
    new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: 2));
(new BatchMcpServerFactory())->create($session, catalog: in_array('--catalog', $argv, true))->run(new StdioTransport());
