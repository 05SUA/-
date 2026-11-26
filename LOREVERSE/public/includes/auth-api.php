<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function loreverseAuthApi(string $action): void
{
    loreverseWebStart();
    $media = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
    $browserLogout = $action === 'logout' && $media === 'application/x-www-form-urlencoded';
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            throw new LoreverseAuthError('METHOD_NOT_ALLOWED', '이 요청 방식은 사용할 수 없어요.', 405);
        }
        if ($browserLogout) {
            $data = $_POST;
        } else {
            if ($media !== 'application/json') {
                throw new LoreverseAuthError('JSON_REQUIRED', 'JSON 형식으로 요청해 주세요.', 415);
            }
            $raw = file_get_contents('php://input', false, null, 0, 8193);
            $data = is_string($raw) && strlen($raw) <= 8192 ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                throw new LoreverseAuthError('BAD_REQUEST', '요청 내용을 확인해 주세요.', 400);
            }
        }
        $result = loreverseAuthAttempt($action, $data);
        if ($browserLogout) {
            loreverseRedirect($result['redirect']);
        }
        http_response_code($action === 'register' ? 201 : 200);
        $response = ['ok' => true, 'data' => $result];
    } catch (Throwable $error) {
        $failure = loreverseAuthFailure($error);
        http_response_code($failure->status);
        $response = ['ok' => false, 'error' => [
            'code' => $failure->reason, 'message' => $failure->getMessage(), 'fields' => (object) $failure->fields,
        ]];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
