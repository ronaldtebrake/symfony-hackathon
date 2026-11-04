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
    $container->extension('code_mode', [
        'identity_provider' => LocalReader::class,
        'ai_toolbox' => 'app.toolbox',
        'ai_tools' => ['publication_read'],
        'ai_authorization' => ReadPolicy::class,
    ]);
};
