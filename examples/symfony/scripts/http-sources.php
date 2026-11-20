<?php

declare(strict_types=1);

/** Fingerprint the application and the package source used by its HTTP catalog. */
return static function (string $app, ?string $packages = null): array {
    $packages ??= $app.'/vendor/ronaldtebrake';
    $files = [];
    foreach (['composer.lock', 'public/index.php', 'config/bundles.php', 'config/routes.php', 'config/services.php', 'scripts/http-proof.php', 'scripts/http-sources.php'] as $path) {
        $files[$path] = hash_file('sha256', $app.'/'.$path);
    }
    $directories = ['src' => $app.'/src', 'config/packages' => $app.'/config/packages', 'config/routes' => $app.'/config/routes'];
    foreach (['php-code-mode', 'symfony-code-mode'] as $package) {
        $base = $packages.'/'.$package;
        if (is_link($base)) {
            throw new RuntimeException('The proof requires an independent copy of each package.');
        }
        $prefix = 'vendor/ronaldtebrake/'.$package;
        $files[$prefix.'/composer.json'] = hash_file('sha256', $base.'/composer.json');
        $directories[$prefix.'/src'] = $base.'/src';
    }
    foreach ($directories as $prefix => $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('The proof requires regular source files.');
            }
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $path = $prefix.'/'.str_replace(\DIRECTORY_SEPARATOR, '/', $iterator->getSubPathname());
                $files[$path] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    ksort($files);

    return $files;
};
