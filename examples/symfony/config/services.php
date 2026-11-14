<?php

declare(strict_types=1);

use Adoption\ArticleCatalog;
use Adoption\ArticleTool;
use Adoption\LocalReader;
use Adoption\ReadPolicy;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', ['secret' => '%env(APP_SECRET)%', 'http_method_override' => false]);
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    foreach ([ArticleCatalog::class, ArticleTool::class, ReadPolicy::class, LocalReader::class, Adoption\PublicationVisibility::class, Adoption\ReaderAccessCommand::class, Adoption\ArticleVoter::class] as $class) {
        $services->set($class);
    }
    $services->set(Adoption\ArticleController::class)->tag('controller.service_arguments');
    $container->parameters()->set('env(ADOPTION_MCP_TOKEN)', '');
    $container->parameters()->set('env(ADOPTION_VIEWER_TOKEN)', '');
    $container->parameters()->set('env(ADOPTION_READER_ACCESS_FILE)', '%kernel.project_dir%/var/demo-reader-access');
    $services->set(Adoption\DemoReaderAccess::class)->args(['%env(ADOPTION_READER_ACCESS_FILE)%']);
    $services->set(Adoption\DemoTokenHandler::class)->args([service(Adoption\DemoReaderAccess::class), '%env(ADOPTION_MCP_TOKEN)%', '%env(ADOPTION_VIEWER_TOKEN)%']);
    $container->extension('security', [
        'providers' => ['unused' => ['memory' => null]],
        'firewalls' => ['mcp' => ['pattern' => '^/(mcp$|articles/)', 'stateless' => true,
            'access_token' => ['token_handler' => Adoption\DemoTokenHandler::class]]],
        'access_control' => [['path' => '^/(mcp$|articles/)', 'roles' => 'IS_AUTHENTICATED_FULLY']],
    ]);
    $http = 'php' !== getenv('ADOPTION_MODE') && 'operator' !== $container->env();
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
        'ai_tool_services' => [ArticleTool::class],
        'ai_tools' => ['publication_read'],
        'ai_authorization' => $http ? null : ReadPolicy::class,
        'visibility_policy' => $http ? null : Adoption\PublicationVisibility::class,
        'voter_rules' => $http ? ['publication_read' => ['discover' => Adoption\ArticleVoter::DISCOVER, 'execute' => Adoption\ArticleVoter::READ, 'subject' => 'id']] : [],
        'profiling' => in_array($container->env(), ['dev', 'test'], true),
        'http' => ['enabled' => $http, 'permissions' => ['publication.read_published' => 'ROLE_READER']],
    ]);
    if (in_array($container->env(), ['dev', 'test'], true)) {
        $container->extension('framework', ['profiler' => ['enabled' => true, 'collect' => true], 'test' => 'test' === $container->env()]);
        $container->extension('web_profiler', ['toolbar' => false]);
    }
    if ('dev' === $container->env()) {
        $container->extension('maker', ['root_namespace' => 'Adoption']);
    }
};
