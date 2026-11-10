<?php

declare(strict_types=1);

use Adoption\ArticleCatalog;
use Adoption\ArticleTool;
use Adoption\LocalReader;
use Adoption\ReadPolicy;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', ['secret' => '%env(APP_SECRET)%', 'http_method_override' => false]);
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    foreach ([ArticleCatalog::class, ArticleTool::class, ReadPolicy::class, LocalReader::class] as $class) {
        $services->set($class);
    }
    $services->set('app.toolbox', Toolbox::class)->args([
        '$tools' => [service(ArticleTool::class)],
        '$eventDispatcher' => service('event_dispatcher'),
    ]);
    $container->parameters()->set('env(ADOPTION_MCP_TOKEN)', '');
    $container->parameters()->set('env(ADOPTION_VIEWER_TOKEN)', '');
    $services->set(Adoption\DemoTokenHandler::class)->args(['%env(ADOPTION_MCP_TOKEN)%', '%env(ADOPTION_VIEWER_TOKEN)%']);
    $container->extension('security', [
        'providers' => ['unused' => ['memory' => null]],
        'firewalls' => ['mcp' => ['pattern' => '^/mcp$', 'stateless' => true,
            'access_token' => ['token_handler' => Adoption\DemoTokenHandler::class]]],
        'access_control' => [['path' => '^/mcp$', 'roles' => 'IS_AUTHENTICATED_FULLY']],
    ]);
    $http = 'php' !== getenv('ADOPTION_MODE');
    if ($http) {
        $container->extension('mcp', ['servers' => ['catalog' => [
            'name' => 'Symfony approved catalog', 'transports' => ['http' => true],
            'http' => ['path' => '/mcp'],
            'registry' => [CodeMode\Symfony\Http\CatalogTools::class],
        ]]]);
    }
    $container->extension('code_mode', [
        'mode' => getenv('ADOPTION_MODE') ?: 'catalog',
        'identity_provider' => LocalReader::class,
        'ai_toolbox' => 'app.toolbox',
        'ai_tools' => ['publication_read'],
        'ai_authorization' => ReadPolicy::class,
        'http' => ['enabled' => $http, 'permissions' => ['publication.read_published' => 'ROLE_READER']],
    ]);
};
