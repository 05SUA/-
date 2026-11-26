<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/genres.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/WorldService.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/WorldGenerator.php';
require_once LOREVERSE_PRIVATE_DIR . '/world-cover.php';

function loreverseWorldApi(string $action): void
{
    loreverseWebStart();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $expected = in_array($action, ['list', 'get', 'summary'], true) ? 'GET' : 'POST';
        if ($_SERVER['REQUEST_METHOD'] !== $expected) {
            header('Allow: ' . $expected);
            throw new LoreverseWorldError('METHOD_NOT_ALLOWED', '이 요청 방식은 사용할 수 없어요.', 405);
        }
        if (!isset($_SESSION['user_id'])) {
            throw new LoreverseWorldError('AUTH_REQUIRED', '로그인이 필요해요.', 401);
        }
        $user = (int) $_SESSION['user_id'];
        $body = $_GET;
        if ($expected === 'POST') {
            $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
            if ($action === 'image') {
                if ($type !== 'multipart/form-data') {
                    throw new LoreverseWorldError('MULTIPART_REQUIRED', '파일을 첨부해 주세요.', 415);
                }
                $body = $_POST;
            } else {
                if ($type !== 'application/json') {
                    throw new LoreverseWorldError('JSON_REQUIRED', 'JSON 형식으로 요청해 주세요.', 415);
                }
                $raw = file_get_contents('php://input', false, null, 0, 262145);
                $body = is_string($raw) && strlen($raw) <= 262144 ? json_decode($raw, true) : null;
                if (!is_array($body)) {
                    throw new LoreverseWorldError('BAD_REQUEST', '요청 내용을 확인해 주세요.');
                }
            }
            $csrf = $body['_csrf'] ?? null;
            if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
                throw new LoreverseWorldError('CSRF_INVALID', '페이지를 새로고침해 주세요.', 403);
            }
        }
        session_write_close();
        $pdo = loreverseDatabase(loreverseWebConfig());
        $worlds = new WorldService($pdo, $user);
        $result = match ($action) {
            'list' => $worlds->list($body),
            'get' => ['world' => $worlds->get(loreverseWorldId($body['id'] ?? null))],
            'summary' => $worlds->summary(),
            'generate' => loreverseGenerateWorld($pdo, $user, $body, loreverseWebConfig()),
            'recommend-genre' => loreverseRecommendWorldGenre($pdo, $user, $body, loreverseWebConfig(), loreverseGenres()),
            'save' => ['world' => $worlds->save($body)],
            'duplicate' => ['world' => $worlds->duplicate(loreverseWorldId($body['id'] ?? null))],
            'delete' => loreverseApiDeleteWorld($worlds, $body),
            'image' => loreverseApiWorldImage($pdo, $user, $body),
        };
        if ($action === 'duplicate' || ($action === 'save' && !isset($body['id']))) {
            http_response_code(201);
        }
        $response = ['ok' => true, 'data' => $result];
    } catch (Throwable $error) {
        $failure = loreverseWorldFailure($error);
        http_response_code($failure->status);
        $response = ['ok' => false, 'error' => [
            'code' => $failure->reason, 'message' => $failure->getMessage(), 'fields' => (object) [],
        ]];
    }
    echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function loreverseApiDeleteWorld(WorldService $worlds, array $body): array
{
    if (($body['confirmed'] ?? false) !== true) {
        throw new LoreverseWorldError('CONFIRMATION_REQUIRED', '삭제할 세계관을 확인해 주세요.');
    }
    loreverseRemoveWorldCover($worlds->delete(loreverseWorldId($body['id'] ?? null)));
    return ['deleted' => true];
}

function loreverseApiWorldImage(PDO $pdo, int $user, array $body): array
{
    if (($body['type'] ?? '') !== 'world') {
        throw new LoreverseWorldError('IMAGE_TYPE_INVALID', '현재 세계관 커버 이미지를 올릴 수 있어요.');
    }
    $id = loreverseWorldId($body['target_id'] ?? null);
    loreverseUploadWorldCover($pdo, $user, $id, $_FILES['file'] ?? null);
    return ['world' => (new WorldService($pdo, $user))->get($id)];
}

function loreverseWorldFailure(Throwable $error): LoreverseWorldError
{
    if ($error instanceof LoreverseWorldError) {
        return $error;
    }
    error_log('LOREVERSE: world operation failed (' . get_class($error) . ')');
    return new LoreverseWorldError('WORLD_UNAVAILABLE', '세계관에 연결할 수 없어요. 잠시 후 다시 시도해 주세요.', 503);
}
