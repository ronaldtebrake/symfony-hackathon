<?php

use Adoption\CapabilityHintController;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    if ('php' !== getenv('ADOPTION_MODE') && in_array($container->env(), ['hint_eval', 'test'], true)) {
        $container->services()->set(CapabilityHintController::class)->autowire()->autoconfigure()->tag('controller.service_arguments');
    }
};
