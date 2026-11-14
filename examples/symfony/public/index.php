<?php

declare(strict_types=1);

use Adoption\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';
if (is_file(dirname(__DIR__).'/.env.local')) {
    (new Dotenv())->load(dirname(__DIR__).'/.env.local');
}
// This demo firewall is stateless. It uses no cookies or signed URLs.
$_SERVER['APP_SECRET'] ??= bin2hex(random_bytes(32));
$environment = $_SERVER['APP_ENV'] ?? (getenv('APP_ENV') ?: 'catalog');
$kernel = new Kernel($environment, 'dev' === $environment || 'test' === $environment);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
