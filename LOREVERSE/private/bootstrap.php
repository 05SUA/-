<?php

declare(strict_types=1);

ini_set('display_errors', '0');

function loreverseConfig(): array
{
    $config = require __DIR__ . '/config.example.php';
    $localPath = __DIR__ . '/config.local.php';

    if (is_file($localPath)) {
        $local = require $localPath;
        if (!is_array($local)) {
            throw new RuntimeException('INVALID_CONFIG');
        }
        $config = array_replace($config, array_intersect_key($local, $config));
    }

    // Supports an injected key for local CLI checks without copying it to disk.
    foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'GEMINI_IMAGE_MODEL'] as $name) {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            $config[$name] = $value;
        }
    }

    return $config;
}
