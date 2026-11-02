<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
if (getenv('DDEV_PROJECT') !== 'symfony-code-mode-drupal' || getcwd() !== '/var/www/html') {
    throw new RuntimeException('This installer runs only inside the dedicated Drupal DDEV project.');
}
$database = new PDO('mysql:host=db;dbname=db', 'db', 'db', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($database->query('SHOW TABLES')->fetchColumn() !== false) {
    // Never reinstall or drop an existing database, even if Drupal cannot bootstrap.
    echo "Database already contains tables; skipping installation.\n";
    exit(0);
}
$install = new Process(['vendor/bin/drush', 'site:install', 'minimal', '--yes', '--no-interaction',
    '--site-name=Symfony Code Mode — Drupal proof', '--account-name=demo-admin',
    '--account-pass='.bin2hex(random_bytes(32))], dirname(__DIR__), timeout: 180);
$install->run();
if (!$install->isSuccessful()) {
    // Process exceptions include argv. Do not print the generated password.
    fwrite(STDERR, "Drupal installation failed. Inspect the local DDEV database before retrying.\n");
    exit(1);
}
echo "Drupal installed with a random administrator password. Use ddev drush uli for local access.\n";
