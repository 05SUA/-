<?php

declare(strict_types=1);

require_once __DIR__ . '/world-data.php';

function loreverseUserImagePath(mixed $name): ?string
{
    return is_string($name) && preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $name)
        ? __DIR__ . '/uploads/users/' . $name
        : null;
}

function loreverseRemoveUserImage(?string $name): void
{
    $path = loreverseUserImagePath($name);
    if ($path !== null && is_file($path) && !unlink($path)) {
        error_log('LOREVERSE: user image cleanup failed');
    }
}

function loreverseStoreUserImage(mixed $file): string
{
    if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK
        || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])
        || !is_int($file['size'] ?? null) || $file['size'] < 1 || $file['size'] > 2097152) {
        throw new LoreverseWorldError('IMAGE_INVALID', '2MB 이하의 JPG, PNG, WebP 파일을 선택해 주세요.');
    }
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $image = @getimagesize($file['tmp_name']);
    if (!isset($types[$mime]) || !is_array($image) || $image['mime'] !== $mime
        || $image[0] < 1 || $image[1] < 1 || $image[0] > 4096 || $image[1] > 4096) {
        throw new LoreverseWorldError('IMAGE_INVALID', '4096×4096 이하의 JPG, PNG, WebP만 올릴 수 있어요.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    $path = loreverseUserImagePath($filename);
    $directory = dirname($path);
    if ((!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))
        || !move_uploaded_file($file['tmp_name'], $path)) {
        throw new LoreverseWorldError('IMAGE_STORAGE_ERROR', '프로필 이미지를 저장할 수 없어요.', 503);
    }
    return $filename;
}
