<?php

declare(strict_types=1);

namespace SurfaceProof;

use CodeMode\ExecutionIdentity;
use CodeMode\Mcp\McpGateway;
use CodeMode\Provider\{McpProvider, ProviderRegistry};
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Symfony\Component\Process\{ExecutableFinder, Process};

/** All endpoints, account selection and operator actions are host configuration. */
final readonly class Connection
{
    public const WIRE_TOOL = 'tool_api__code_mode_surface_search';
    private string $ddev;
    private array $environment;
    public function __construct(private string $site)
    {
        if (!is_file($site.'/.ddev/config.yaml')) { throw new \RuntimeException('Select the existing Rosetta DDEV site with SURFACE_PROOF_SITE.'); }
        $this->ddev = (new ExecutableFinder())->find('ddev') ?? throw new \RuntimeException('DDEV is required.');
        $environment = [];
        foreach (['PATH','HOME','DOCKER_HOST','DOCKER_CONTEXT','DOCKER_CONFIG','TMPDIR'] as $name) {
            if (false !== ($value = getenv($name))) { $environment[$name] = $value; }
        }
        $this->environment = $environment;
    }
    public function connect(): Client
    {
        $client = Client::builder()->setClientInfo('surface-query-proof','1')->setInitTimeout(2)->setRequestTimeout(2)->setMaxRetries(0)->build();
        try {
            $client->connect(new StdioTransport($this->ddev, ['--skip-hooks','exec','--raw','--','vendor/bin/drush','--quiet','mcp:server','code_mode_surface_reader'], $this->site, $this->environment, maxBufferSize:65536));
            return $client;
        } catch (\Throwable) { $client->disconnect(); throw new \RuntimeException('Configured Drupal MCP connection unavailable.'); }
    }
    public function providers(): ProviderRegistry
    {
        return new ProviderRegistry([$this->provider()]);
    }
    public function provider(): McpProvider
    {
        $gateway = new McpGateway($this->connect(...), [self::WIRE_TOOL => static fn (ExecutionIdentity $identity): bool => 'surface-proof-reader' === $identity->subject && $identity->allows('published.search')]);
        return new McpProvider('drupal', $gateway, ['search'=>self::WIRE_TOOL]);
    }
    /** Not exposed to the agent. Controls only this example's own fixtures. */
    public function control(string $action): array
    {
        if (!in_array($action, ['snapshot','direct','revoke','restore'], true)) { throw new \InvalidArgumentException('Unknown fixture control.'); }
        $environment = array_replace(array_fill_keys(array_keys(getenv()), false), $this->environment);
        $process = new Process([$this->ddev,'--skip-hooks','exec','--raw','--','env','SURFACE_PROOF_ACTION='.$action,'vendor/bin/drush','--quiet','php:script','/var/www/html/web/modules/custom/code_mode_surface_proof/proof-control.php'], $this->site, $environment, timeout:15);
        if (0 !== $process->run()) { throw new \RuntimeException('The operator fixture control failed.'); }
        return json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
    }
    public function mcp(array $values): array
    {
        $client = $this->connect(); $start = hrtime(true);
        try {
            $result = $client->callTool(self::WIRE_TOOL, ['values'=>$values]);
            // Do not publish upstream exception text, which may contain private diagnostics.
            return ['tool'=>self::WIRE_TOOL, 'isError'=>$result->isError, 'value'=>$result->isError ? null : $result->structuredContent, 'elapsedMs'=>round((hrtime(true)-$start)/1e6,2)];
        } finally { $client->disconnect(); }
    }
}
