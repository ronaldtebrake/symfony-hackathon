<?php

declare(strict_types=1);

// PHPStan's directory scan selects .php files. Pass PHP CLI entry points as
// explicit files so existing and new extensionless commands are analysed too.
$root = dirname(__DIR__);
$paths = [];
foreach (['/bin/*', '/examples/*/bin/*', '/packages/*/bin/*'] as $pattern) {
    foreach (glob($root.$pattern) ?: [] as $path) {
        if (is_file($path) && str_contains(file_get_contents($path, length: 128), '<?php')) {
            $paths[] = $path;
        }
    }
}

return ['parameters' => ['paths' => $paths]];
