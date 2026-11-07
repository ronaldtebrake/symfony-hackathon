<?php

use App\Kernel;

// PHP's development server uses this file as its router. Serve only these public assets directly.
if ('cli-server' === PHP_SAPI && in_array(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), ['/demo.css', '/demo.js', '/docs.js'], true)) {
    return false;
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
