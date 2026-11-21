<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use CodeMode\Symfony\CodeModeBundle;
use CodeMode\Symfony\Http\DirectToolsPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DirectToolsConfigurationTest extends TestCase
{
    public static function invalidDirectNames(): array
    {
        return [
            'reserved discovery' => [['search_tools']],
            'reserved execution' => [['execute_tools']],
            'duplicate' => [['publication_read', 'publication_read']],
            'invalid tool name' => [['publication read']],
        ];
    }

    #[DataProvider('invalidDirectNames')]
    public function testRejectsInvalidOptInNames(array $names): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Configure unique HTTP direct_tools');
        (new CodeModeBundle())->getContainerExtension()->load([['http' => ['enabled' => true, 'direct_tools' => $names]]], $container);
    }

    public function testDirectToolsRequireAnHttpCatalogServer(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('code_mode.http.direct_tools', ['publication_read']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires a configured MCP server');
        (new DirectToolsPass())->process($container);
    }
}
