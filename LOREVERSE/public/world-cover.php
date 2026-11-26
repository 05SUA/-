<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once LOREVERSE_PRIVATE_DIR . '/world-cover.php';
loreverseWebStart();
if (!isset($_SESSION['user_id']) || !in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(404);
    exit;
}
$user = (int) $_SESSION['user_id'];
session_write_close();
try {
    $id = loreverseWorldId($_GET['id'] ?? null);
    $row = (new WorldService(loreverseDatabase(loreverseWebConfig()), $user))->ownedRow($id);
    $path = loreverseWorldCoverPath($row['cover_image']);
    $stream = $path === null ? false : @fopen($path, 'rb');
    if ($stream === false) {
        http_response_code(404);
        exit;
    }
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        fpassthru($stream);
    }
    fclose($stream);
} catch (LoreverseWorldError $error) {
    http_response_code($error->status);
} catch (Throwable $error) {
    error_log('LOREVERSE: world cover could not be accessed');
    http_response_code(503);
}
