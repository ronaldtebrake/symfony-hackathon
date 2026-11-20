<?php

declare(strict_types=1);

namespace App\Demo\Refund;

use CodeMode\Symfony\Profiler\CatalogDataCollector;
use CodeMode\Symfony\Profiler\CatalogTrace;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/** An in-process viewer for existing profile files. The MCP HTTP kernel has no profiler route. */
final class ProfilerViewerKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $directory)
    {
        parent::__construct('viewer', false);
    }

    public function getProjectDir(): string
    {
        return $this->directory;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new WebProfilerBundle();
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass().'_'.substr(hash('sha256', $this->directory), 0, 12);
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'offline-profile-viewer',
            'profiler' => ['enabled' => true, 'collect' => false, 'dsn' => 'file:'.$this->directory.'/profiles']]);
        $container->extension('web_profiler', ['toolbar' => false]);
        $container->extension('twig', ['cache' => false, 'paths' => [\dirname(__DIR__, 3).'/packages/symfony-code-mode/templates' => 'CodeMode', __DIR__.'/views' => 'Refund']]);
        $container->services()->set(CatalogTrace::class);
        $container->services()->set(CatalogDataCollector::class)->args([service(CatalogTrace::class)])
            ->tag('data_collector', ['id' => 'code_mode', 'template' => '@Refund/profile.html.twig']);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')->prefix('/_profiler');
    }
}
