<?= "<?php\n" ?>

declare(strict_types=1);

use <?= $tool_class ?>;
use <?= $voter_class ?>;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()->set(<?= substr($tool_class, strrpos($tool_class, '\\') + 1) ?>::class)->autowire()->autoconfigure();
    $container->services()->set(<?= $voter_short ?>::class)->autowire()->autoconfigure();
    $container->extension('code_mode', [
        'ai_tool_services' => [<?= substr($tool_class, strrpos($tool_class, '\\') + 1) ?>::class],
        'ai_tools' => ['<?= $tool_name ?>'],
        'voter_rules' => ['<?= $tool_name ?>' => [
            'discover' => <?= $voter_short ?>::DISCOVER,
            'execute' => <?= $voter_short ?>::READ,
            'subject' => 'id',
        ]],
    ]);
};
