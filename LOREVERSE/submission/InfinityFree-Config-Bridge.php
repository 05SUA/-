<?php

declare(strict_types=1);

$source = dirname(__DIR__, 2) . '/loreverse-stage01/private/config.local.php';
$existing = is_file($source) ? require $source : [];
$allowed = require __DIR__ . '/config.example.php';
unset($allowed['STAGE01_DIAGNOSTIC_TOKEN']);

return [
    'APP_ENV' => 'production',
    'APP_BASE_URL' => 'https://loreverse.42web.io/loreverse/public',
] + array_intersect_key(is_array($existing) ? $existing : [], $allowed);
