<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/src/Connection.php';
require_once __DIR__.'/src/Verifier.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__,2).'/.env');
if (is_file(__DIR__.'/.env.local')) { (new Symfony\Component\Dotenv\Dotenv())->load(__DIR__.'/.env.local'); }
