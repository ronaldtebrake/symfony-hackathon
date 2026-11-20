<?php

use Adoption\CapabilityHintController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    if (in_array($routes->env(), ['hint_eval', 'test'], true)) {
        $routes->add('catalog_capability_hint', '/articles/capability-hint')->controller(CapabilityHintController::class)->methods(['GET']);
    }
};
