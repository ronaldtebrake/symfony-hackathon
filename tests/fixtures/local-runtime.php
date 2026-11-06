<?php

declare(strict_types=1);

// Trusted test host with explicit local opt-in. No application configuration is changed.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\CodeMode\{DemoIdentityProvider, ReadArticle};
use CodeMode\Symfony\{CodeModeBundle, SessionFactory};
use CodeMode\Symfony\Command\{DoctorCommand, ServeCommand};
use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ContainerBuilder;

$container = new ContainerBuilder();
$container->setParameter('kernel.environment', 'test');
$container->setParameter('kernel.build_dir', dirname(__DIR__, 2).'/var/cache/local-runtime-test');
$container->register(ReadArticle::class);
$container->register(DemoIdentityProvider::class);
(new CodeModeBundle())->getContainerExtension()->load([[
    'executor' => 'local-unsafe',
    'identity_provider' => DemoIdentityProvider::class,
    'tools' => [ReadArticle::class],
    // These deliberately unresolved settings must never be evaluated or contacted in local mode.
    'image' => '%env(MISSING_LOCAL_TEST_IMAGE)%',
    'docker_command' => '%env(json:MISSING_LOCAL_TEST_DOCKER)%',
]], $container);
foreach ([SessionFactory::class, DoctorCommand::class, ServeCommand::class] as $id) {
    $container->getDefinition($id)->setPublic(true);
}
$container->compile();
$app = new Application();
$app->add($container->get(DoctorCommand::class));
$app->add($container->get(ServeCommand::class));
$app->run();
