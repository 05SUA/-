<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/_stage02') {
    return false;
}

require __DIR__ . '/stage02-ui.php';
