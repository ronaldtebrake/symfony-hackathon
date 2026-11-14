<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$mode = $argv[1] ?? 'style';
if (!in_array($mode, ['style', 'phpstan'], true)) {
    throw new InvalidArgumentException('Choose the style or phpstan check.');
}
$directory = sys_get_temp_dir().'/code-mode-maker-style-'.bin2hex(random_bytes(8));
$filesystem = new Filesystem();
$templates = [
    'Tool' => ['src/Tool/InventoryLookupTool.php', 'App\\Tool', 'InventoryLookupTool'],
    'Voter' => ['src/Security/InventoryLookupVoter.php', 'App\\Security', 'InventoryLookupVoter'],
    'Config' => ['config/packages/code_mode_inventory_lookup.php', '', ''],
    'Test' => ['tests/Tool/InventoryLookupVoterTest.php', '', ''],
];

// Render the same templates and variables used by MakeCodeModeTool. This check
// needs no running application, model, credentials or generated files in the repo.
$render = static function (string $template, string $namespace, string $class_name): string {
    $tool_name = 'inventory_lookup';
    $tool_class = 'App\\Tool\\InventoryLookupTool';
    $voter_class = 'App\\Security\\InventoryLookupVoter';
    $voter_short = 'InventoryLookupVoter';
    $attribute = 'INVENTORY_LOOKUP';
    $root_namespace = 'App';
    ob_start();
    try {
        include $template;

        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
};

try {
    foreach ($templates as $name => [$path, $namespace, $className]) {
        $filesystem->dumpFile($directory.'/'.$path, $render($root.'/packages/symfony-code-mode/src/Maker/templates/'.$name.'.tpl.php', $namespace, $className));
    }

    $command = 'phpstan' === $mode ? [
        \PHP_BINARY, $root.'/vendor/bin/phpstan', 'analyse',
        '--configuration='.$root.'/phpstan.dist.neon', '--no-progress', '--memory-limit=1G', $directory,
    ] : [
        \PHP_BINARY, $root.'/vendor/bin/php-cs-fixer', 'check',
        '--config='.$root.'/.php-cs-fixer.dist.php', '--path-mode=override',
        '--using-cache=no', '--sequential', '--diff', $directory,
    ];
    $check = new Process($command, $root, timeout: 120);
    $status = $check->run(static function (string $type, string $output): void {
        fwrite(Process::ERR === $type ? \STDERR : \STDOUT, $output);
    });
} finally {
    $filesystem->remove($directory);
}

exit($status);
