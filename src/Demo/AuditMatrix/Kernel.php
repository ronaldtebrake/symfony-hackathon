<?php

declare(strict_types=1);

namespace App\Demo\AuditMatrix;

use CodeMode\Symfony\Http\SecurityIdentityProvider;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/** Isolated benchmark app; does not replace or reconfigure either existing Symfony demo. */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $directory, private readonly string $surface)
    {
        parent::__construct('test', false);
    }

    public function getProjectDir(): string
    {
        return $this->directory;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass().'_'.substr(hash('sha256', $this->directory.$this->surface), 0, 12);
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'disposable-audit-matrix', 'http_method_override' => false, 'test' => true]);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(Access::class)->args([$this->directory]);
        $services->set(SecurityIdentityProvider::class)->args([service('security.token_storage'), service('security.authorization_checker'), ['content.audit' => 'ROLE_AUDITOR']]);
        $services->set(Gateway::class)->args([$this->directory, $this->surface, service(SecurityIdentityProvider::class), service(Access::class)])->public()->tag('controller.service_arguments');
        $container->extension('security', [
            'providers' => ['unused' => ['memory' => null]],
            'firewalls' => ['mcp' => ['pattern' => '^/mcp$', 'stateless' => true, 'access_token' => ['token_handler' => Access::class]]],
            'access_control' => [['path' => '^/mcp$', 'roles' => 'IS_AUTHENTICATED_FULLY']],
        ]);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('matrix_mcp', '/mcp')->controller(Gateway::class);
    }
}
