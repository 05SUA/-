<?php
declare(strict_types=1);

$code = '';
$origin = 'https://loreverse.42web.io';
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'");

if (strlen($code) < 24 || !in_array($_SERVER['HTTPS'] ?? '', ['on', '1'], true)) {
    http_response_code(404);
    exit('테스트 파일 설정을 확인하세요.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    exit('<form method="post"><label>접근 코드 <input name="code" type="password" required></label> '
        . '<button name="mode" value="plain">짧은 글 생성 테스트</button> '
        . '<button name="mode" value="embedding">장르 추천 연결 테스트</button></form>');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_ORIGIN'] ?? '') !== $origin
    || !is_string($_POST['code'] ?? null) || !hash_equals($code, $_POST['code'])) {
    http_response_code(403);
    exit('접근 코드를 확인하세요.');
}

header('Content-Type: application/json; charset=utf-8');
$mode = $_POST['mode'] ?? '';
if (!in_array($mode, ['plain', 'embedding'], true)) {
    http_response_code(400);
    exit('{"error":"INVALID_TEST_MODE"}');
}

try {
    require dirname(__DIR__) . '/private/bootstrap.php';
    $config = loreverseConfig();
    $key = $config['GEMINI_API_KEY'] ?? '';
    $model = $mode === 'embedding' ? 'gemini-embedding-001' : ($config['GEMINI_MODEL'] ?? '');
    if (!is_string($key) || $key === '' || preg_match('/[\x00-\x20\x7f]/', $key)
        || !is_string($model) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}\z/', $model)) {
        echo json_encode(['mode' => $mode, 'error' => 'GEMINI_CONFIG_INVALID']);
        return;
    }
    if (!function_exists('curl_init')) {
        echo json_encode(['mode' => $mode, 'error' => 'GEMINI_CURL_MISSING']);
        return;
    }
    if ($mode === 'embedding') {
        $method = 'embedContent';
        $payload = [
            'model' => 'models/' . $model,
            'content' => ['parts' => [['text' => '마법사가 사는 공중 왕국의 모험']]],
            'taskType' => 'SEMANTIC_SIMILARITY',
        ];
    } else {
        $method = 'generateContent';
        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => '공중에 떠 있는 마법 도시를 한국어 한 문장으로 소개해 주세요.']]]],
            'generationConfig' => ['maxOutputTokens' => 1024],
        ];
    }

    $executionLimit = (int) ini_get('max_execution_time');
    $timeout = $executionLimit > 0 ? max(1, min(25, $executionLimit - 2)) : 25;
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':' . $method;
    $curl = curl_init($url);
    if ($curl === false || !curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ])) {
        echo json_encode(['mode' => $mode, 'error' => 'GEMINI_CURL_FAILED']);
        return;
    }

    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $result = ['mode' => $mode, 'http' => $status, 'valid_result' => false];
    if ($body === false) {
        $result['error'] = curl_errno($curl) === CURLE_OPERATION_TIMEDOUT ? 'GEMINI_TIMEOUT' : 'GEMINI_NETWORK_ERROR';
    } else {
        $response = json_decode($body, true, 32);
        if ($status !== 200) {
            $providerStatus = $response['error']['status'] ?? '';
            if (($response['error']['code'] ?? null) === $status && in_array($providerStatus, [
                'UNAVAILABLE', 'RESOURCE_EXHAUSTED', 'NOT_FOUND', 'PERMISSION_DENIED',
                'INVALID_ARGUMENT', 'UNAUTHENTICATED', 'FAILED_PRECONDITION', 'INTERNAL',
            ], true)) {
                $result['provider_status'] = $providerStatus;
            }
        } elseif ($mode === 'embedding') {
            $values = $response['embedding']['values'] ?? null;
            if (is_array($values) && $values !== []) {
                $valid = true;
                $nonzero = false;
                foreach ($values as $index => $value) {
                    if (!is_int($index) || (!is_float($value) && !is_int($value)) || !is_finite((float) $value)) {
                        $valid = false;
                        break;
                    }
                    $nonzero = $nonzero || (float) $value !== 0.0;
                }
                $result['valid_result'] = $valid && $nonzero;
            }
        } else {
            $parts = $response['candidates'][0]['content']['parts'] ?? [];
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    $generated = $part['text'] ?? '';
                    if (($part['thought'] ?? false) !== true && is_string($generated) && trim($generated) !== '') {
                        $result['valid_result'] = true;
                        break;
                    }
                }
            }
        }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo json_encode(['mode' => $mode, 'error' => 'GEMINI_TEST_FAILED']);
}
