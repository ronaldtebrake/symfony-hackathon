<?php

declare(strict_types=1);

use Adoption\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';
if (is_file(dirname(__DIR__).'/.env.local')) { (new Dotenv())->load(dirname(__DIR__).'/.env.local'); }
// This demo firewall is stateless. It uses no cookies or signed URLs.
$_SERVER['APP_SECRET'] ??= bin2hex(random_bytes(32));
$kernel = new Kernel('catalog', false);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
