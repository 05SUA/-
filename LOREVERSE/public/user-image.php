<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once LOREVERSE_PRIVATE_DIR . '/user-image.php';

loreverseWebStart();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(404);
    exit;
}
$userId = (int) $_SESSION['user_id'];
session_write_close();
if (isset($_GET['id']) && $_GET['id'] !== (string) $userId) {
    http_response_code(404);
    exit;
}
try {
    $pdo = loreverseDatabase(loreverseWebConfig());
    $query = $pdo->prepare('SELECT profile_image FROM users WHERE id = ?');
    $query->execute([$userId]);
    $path = loreverseUserImagePath($query->fetchColumn());
    $stream = $path === null ? false : @fopen($path, 'rb');
    if ($stream === false) {
        http_response_code(404);
        exit;
    }
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
    if ($method === 'GET') {
        fpassthru($stream);
    }
    fclose($stream);
} catch (Throwable $error) {
    error_log('LOREVERSE: user image could not be accessed');
    http_response_code(503);
}
