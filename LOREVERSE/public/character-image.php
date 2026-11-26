<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once LOREVERSE_PRIVATE_DIR . '/character-image.php';

loreverseWebStart();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(404);
    exit;
}
$userId = (int) $_SESSION['user_id'];
session_write_close();

try {
    $id = loreverseCharacterId($_GET['id'] ?? null);
    $characters = new CharacterService(loreverseDatabase(loreverseWebConfig()), $userId);
    $path = loreverseCharacterImagePath($characters->ownedRow($id)['profile_image']);
    $stream = $path === null ? false : @fopen($path, 'rb');
    if ($stream === false) {
        http_response_code(404);
        exit;
    }

    $mimeTypes = array_flip(LOREVERSE_CHARACTER_IMAGE_TYPES);
    header('Content-Type: ' . $mimeTypes[pathinfo($path, PATHINFO_EXTENSION)]);
    $metadata = fstat($stream);
    if ($metadata !== false) {
        header('Content-Length: ' . $metadata['size']);
    }
    if ($method === 'GET') {
        fpassthru($stream);
    }
    fclose($stream);
} catch (LoreverseWorldError $error) {
    http_response_code($error->status);
} catch (Throwable $error) {
    error_log('LOREVERSE: character image could not be accessed');
    http_response_code(503);
}
