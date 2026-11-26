<?php

declare(strict_types=1);

function loreverseDatabase(array $config): PDO
{
    $host = $config['DB_HOST'];
    $port = filter_var($config['DB_PORT'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    $name = $config['DB_NAME'];
    $user = $config['DB_USER'];
    $password = $config['DB_PASSWORD'];

    if (
        !is_string($host) || !preg_match('/\A[a-zA-Z0-9.-]+\z/', $host)
        || $port === false
        || !is_string($name) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $name)
        || !is_string($user) || trim($user) === '' || !is_string($password)
    ) {
        throw new InvalidArgumentException('DATABASE_CONFIG_INVALID');
    }

    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}
