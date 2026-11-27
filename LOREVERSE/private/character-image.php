<?php

declare(strict_types=1);

require_once __DIR__ . '/services/CharacterService.php';

const LOREVERSE_CHARACTER_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function loreverseCharacterImageUrl(array $character): string
{
    if (empty($character['has_image'])) {
        return loreverseUrl('assets/images/avatar-default.svg');
    }
    return loreverseUrl('character-image.php', ['id' => $character['id'], 'v' => $character['updated_at']]);
}

function loreverseCharacterImagePath(mixed $name): ?string
{
    return is_string($name) && preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $name)
        ? __DIR__ . '/uploads/characters/' . $name
        : null;
}

function loreverseRemoveCharacterImage(?string $name): void
{
    $path = loreverseCharacterImagePath($name);
    if ($path !== null && is_file($path) && !@unlink($path)) {
        error_log('LOREVERSE: character image cleanup failed');
    }
}

function loreverseCheckCharacterUploadRequest(): void
{
    if ($_POST !== [] || $_FILES !== [] || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) <= 0) {
        return;
    }
    if (!preg_match('/\A(\d+)\s*([KMG]?)\z/i', trim((string) ini_get('post_max_size')), $limit)) {
        return;
    }
    $unit = match (strtolower($limit[2])) {
        'k' => 1024,
        'm' => 1048576,
        'g' => 1073741824,
        default => 1,
    };
    $maxBytes = (int) $limit[1] * $unit;
    if ($maxBytes > 0 && (int) $_SERVER['CONTENT_LENGTH'] > $maxBytes) {
        throw new LoreverseWorldError('IMAGE_REQUEST_TOO_LARGE', '첨부 파일이 서버 용량 제한을 넘었어요. 더 작은 이미지로 다시 시도해 주세요.', 413);
    }
}

function loreverseUploadCharacterImage(PDO $pdo, int $userId, int $id, mixed $file): void
{
    $characters = new CharacterService($pdo, $userId);
    $characters->ownedRow($id);
    if (!ini_get('file_uploads')) {
        throw new LoreverseWorldError('IMAGE_STORAGE_ERROR', '서버에서 파일 업로드를 사용할 수 없어요.', 503);
    }
    $uploadError = is_array($file) ? ($file['error'] ?? null) : UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        throw new LoreverseWorldError('IMAGE_REQUIRED', '저장할 이미지 파일을 선택해 주세요.');
    }
    if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new LoreverseWorldError('IMAGE_TOO_LARGE', '파일이 서버 용량 제한을 넘었어요. 더 작은 이미지를 선택해 주세요.');
    }
    if ($uploadError === UPLOAD_ERR_PARTIAL) {
        throw new LoreverseWorldError('IMAGE_UPLOAD_INCOMPLETE', '파일 전송이 끝나지 않았어요. 다시 선택해 주세요.');
    }
    if (in_array($uploadError, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
        throw new LoreverseWorldError('IMAGE_STORAGE_ERROR', '서버에 이미지 파일을 받을 수 없어요. 잠시 후 다시 시도해 주세요.', 503);
    }
    if ($uploadError !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])
        || !is_int($file['size'] ?? null) || $file['size'] < 1 || $file['size'] > 2097152) {
        throw new LoreverseWorldError('IMAGE_INVALID', '2MB 이하의 JPG, PNG, WebP 파일을 선택해 주세요.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $image = @getimagesize($file['tmp_name']);
    if (!isset(LOREVERSE_CHARACTER_IMAGE_TYPES[$mime]) || !is_array($image) || $image['mime'] !== $mime
        || $image[0] < 1 || $image[1] < 1 || $image[0] > 4096 || $image[1] > 4096) {
        throw new LoreverseWorldError('IMAGE_INVALID', '4096×4096 이하의 JPG, PNG, WebP만 올릴 수 있어요.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . LOREVERSE_CHARACTER_IMAGE_TYPES[$mime];
    $path = loreverseCharacterImagePath($filename);
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new LoreverseWorldError('IMAGE_STORAGE_ERROR', '이미지 저장 공간을 확인해 주세요.', 503);
    }
    if (!@move_uploaded_file($file['tmp_name'], $path)) {
        throw new LoreverseWorldError('IMAGE_STORAGE_ERROR', '이미지를 저장할 수 없어요.', 503);
    }
    if (!is_readable($path)) {
        loreverseRemoveCharacterImage($filename);
        throw new LoreverseWorldError('IMAGE_STORAGE_ERROR', '서버에서 저장한 이미지 파일을 읽을 수 없어요.', 503);
    }

    try {
        $pdo->beginTransaction();
        $previous = $characters->ownedRow($id, true);
        $update = $pdo->prepare('UPDATE characters SET profile_image = ?,
            updated_at = GREATEST(UTC_TIMESTAMP(), TIMESTAMPADD(SECOND, 1, updated_at))
            WHERE id = ? AND user_id = ?');
        $update->execute([$filename, $id, $userId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        loreverseRemoveCharacterImage($filename);
        throw $error;
    }
    loreverseRemoveCharacterImage($previous['profile_image']);
}
