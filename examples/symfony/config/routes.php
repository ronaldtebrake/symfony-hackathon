<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('.', 'mcp');
    $routes->add('article_read', '/articles/{id}')->controller(Adoption\ArticleController::class)->methods(['GET'])->requirements(['id' => '\\d+']);
    if (in_array($routes->env(), ['dev', 'test'], true)) {
        $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')->prefix('/_profiler');
    }
};
