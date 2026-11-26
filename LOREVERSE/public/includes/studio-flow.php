<?php

declare(strict_types=1);

require_once __DIR__ . '/world-ui.php';
require_once __DIR__ . '/world-api.php';
if (is_file(__DIR__ . '/favorite-ui.php')) {
    require_once __DIR__ . '/favorite-ui.php';
}

function loreverseStudioUser(string $returnUrl): int
{
    loreverseWebStart();
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD', 'POST'], true)) {
        header('Allow: GET, HEAD, POST');
        http_response_code(405);
        exit('이 페이지에서 사용할 수 없는 요청입니다.');
    }
    if (!isset($_SESSION['user_id'])) {
        loreverseRedirect(loreverseUrl('login.php', ['next' => loreverseSafeNext($returnUrl)]));
    }
    return (int) $_SESSION['user_id'];
}

function loreverseStudioCsrf(array $body): void
{
    $token = $body['_csrf'] ?? null;
    if (!is_string($token) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        throw new LoreverseWorldError('CSRF_INVALID', '페이지를 새로고침해 주세요.', 403);
    }
}

function loreverseStudioForm(bool $release = true): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new LoreverseWorldError('METHOD_NOT_ALLOWED', '요청 방식을 확인해 주세요.', 405);
    }
    loreverseStudioCsrf($_POST);
    if ($release) {
        session_write_close();
    }
    return $_POST;
}

function loreverseStudioApi(string $method, callable $operation, bool $multipart = false, bool $release = true): void
{
    loreverseWebStart();
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            header('Allow: ' . $method);
            throw new LoreverseWorldError('METHOD_NOT_ALLOWED', '요청 방식을 확인해 주세요.', 405);
        }
        if (!isset($_SESSION['user_id'])) {
            throw new LoreverseWorldError('AUTH_REQUIRED', '로그인이 필요해요.', 401);
        }
        $user = (int) $_SESSION['user_id'];
        $body = $_GET;
        if ($method === 'POST') {
            $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
            if ($multipart) {
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
            loreverseStudioCsrf($body);
        }
        if ($release) {
            session_write_close();
        }
        $pdo = loreverseDatabase(loreverseWebConfig());
        $response = ['ok' => true, 'data' => $operation($pdo, $user, $body)];
    } catch (Throwable $error) {
        $failure = loreverseWorldFailure($error);
        http_response_code($failure->status);
        $response = ['ok' => false, 'error' => [
            'code' => $failure->reason, 'message' => $failure->getMessage(), 'fields' => (object) [],
        ]];
    }
    echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function loreverseStudioRequestId(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
