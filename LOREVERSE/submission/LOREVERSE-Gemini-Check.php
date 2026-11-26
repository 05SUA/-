<?php

declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/includes/app.php';
require_once LOREVERSE_PRIVATE_DIR . '/ai-usage.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/GeminiService.php';

loreverseWebStart();
if (!isset($_SESSION['user_id'])) {
    loreverseRedirect(loreverseUrl('login.php'));
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$csrf = loreverseCsrf();
session_write_close();
$result = null;

if ($method === 'POST') {
    $mode = $_POST['mode'] ?? '';
    $token = $_POST['_csrf'] ?? '';
    $requestId = $_POST['request_id'] ?? '';
    if (!is_string($token) || !hash_equals($csrf, $token)) {
        http_response_code(403);
        $result = ['error' => 'CSRF_INVALID'];
    } elseif (!in_array($mode, ['text', 'json'], true) || !is_string($requestId)
        || !preg_match('/\A[0-9a-f]{32}\z/', $requestId)) {
        http_response_code(400);
        $result = ['error' => 'BAD_REQUEST'];
    } else {
        try {
            $config = loreverseWebConfig();
            (new GeminiService())->validateConfig($config);
            $pdo = loreverseDatabase($config);
            $limit = filter_var($config['AI_DAILY_LIMIT'], FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 1000]]) ?: 30;
            loreverseReserveAi($pdo, $userId, $requestId, 'gemini_connection_check', $config['GEMINI_MODEL'], $limit);
            $start = microtime(true);
            $failure = 'CHECK_INTERNAL_ERROR';
            try {
                $result = loreverseGeminiConnectionCheck($config, $mode);
                $failure = $result['valid_result'] ? null : ($result['error'] ?? 'CHECK_INVALID_RESULT');
            } finally {
                $elapsed = (int) round((microtime(true) - $start) * 1000);
                loreverseFinishAi($pdo, $userId, $requestId, $failure, null, $elapsed);
            }
        } catch (LoreverseWorldError | GeminiException $error) {
            $result = ['mode' => $mode, 'error' => $error instanceof LoreverseWorldError
                ? $error->reason : $error->getMessage()];
        } catch (Throwable $error) {
            $result = ['mode' => $mode, 'error' => 'CHECK_INTERNAL_ERROR'];
        }
    }
}

function loreverseGeminiConnectionCheck(array $config, string $mode): array
{
    $prompt = '떠다니는 섬을 오가는 우편 배달부 세계관의 제목과 한 문장 소개를 한국어로 작성해 주세요.';
    $generation = ['maxOutputTokens' => 1024];
    if ($mode === 'json') {
        $generation['responseMimeType'] = 'application/json';
        $generation['responseJsonSchema'] = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string'], 'summary' => ['type' => 'string']],
            'required' => ['name', 'summary'],
        ];
    }

    $payload = json_encode([
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => $generation,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $executionLimit = (int) ini_get('max_execution_time');
    $timeout = $executionLimit > 0 ? max(1, min(25, $executionLimit - 2)) : 25;
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($config['GEMINI_MODEL']) . ':generateContent';
    $curl = curl_init($url);
    if ($curl === false || !curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $config['GEMINI_API_KEY']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ])) {
        throw new GeminiException('GEMINI_CURL_FAILED');
    }

    $raw = curl_exec($curl);
    $http = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $result = ['mode' => $mode, 'http' => $http, 'valid_result' => false];
    if ($raw === false) {
        return $result + ['error' => curl_errno($curl) === CURLE_OPERATION_TIMEDOUT
            ? 'GEMINI_TIMEOUT' : 'GEMINI_NETWORK_ERROR'];
    }

    $response = json_decode($raw, true);
    if ($http !== 200) {
        if (($response['error']['code'] ?? null) === $http && in_array($response['error']['status'] ?? null, [
            'UNAVAILABLE', 'RESOURCE_EXHAUSTED', 'NOT_FOUND', 'PERMISSION_DENIED',
            'INVALID_ARGUMENT', 'UNAUTHENTICATED', 'FAILED_PRECONDITION', 'INTERNAL',
        ], true)) {
            $result['provider_status'] = $response['error']['status'];
        }
        return $result + ['error' => 'GEMINI_HTTP_' . $http];
    }

    $candidate = $response['candidates'][0] ?? [];
    if (($candidate['finishReason'] ?? null) === 'MAX_TOKENS') {
        return $result + ['error' => 'GEMINI_RESPONSE_TRUNCATED'];
    }
    $text = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) {
        if (empty($part['thought']) && is_string($part['text'] ?? null)) {
            $text .= $part['text'];
        }
    }
    $valid = ($candidate['finishReason'] ?? null) === 'STOP' && trim($text) !== '';
    if ($valid && $mode === 'json') {
        $world = json_decode($text);
        $valid = is_string($world->name ?? null) && trim($world->name) !== ''
            && is_string($world->summary ?? null) && trim($world->summary) !== '';
    }
    $result['valid_result'] = $valid;
    return $valid ? $result : $result + ['error' => 'CHECK_INVALID_RESULT'];
}

loreversePageStart('Gemini 연결 점검');
?>
<main id="main" class="site-container workspace-page">
    <section class="surface world-panel">
        <h1>Gemini 연결 점검</h1>
        <?php if ($result !== null): ?>
            <pre class="form-message" role="status"><?= loreverseEscape(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) ?></pre>
        <?php endif; ?>
        <form method="post" class="world-actions">
            <input type="hidden" name="_csrf" value="<?= loreverseEscape($csrf) ?>">
            <input type="hidden" name="request_id" value="<?= bin2hex(random_bytes(16)) ?>">
            <button class="button button--primary" type="submit" name="mode" value="text">짧은 문장 테스트</button>
            <button class="button button--outline" type="submit" name="mode" value="json">짧은 JSON 테스트</button>
        </form>
    </section>
</main>
<?php loreversePageEnd(); ?>
