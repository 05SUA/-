<?php

declare(strict_types=1);

ini_set('display_errors', '0');

$private = dirname(__DIR__) . '/private';
if (PHP_SAPI !== 'cli' && !is_file($private . '/bootstrap.php')) {
    // Fallback for a host that only accepts files inside htdocs.
    $private = __DIR__ . '/private';
}
if (!is_file($private . '/bootstrap.php')) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "PRIVATE_DIRECTORY_MISSING\n");
    } else {
        http_response_code(404);
    }
    exit(1);
}
require $private . '/bootstrap.php';
require $private . '/database.php';
require $private . '/services/GeminiService.php';

final class Stage01Failure extends RuntimeException
{
}

function checkRuntime(): array
{
    $extensions = [];
    foreach (['pdo_mysql', 'curl', 'mbstring', 'fileinfo', 'session'] as $extension) {
        $extensions[$extension] = extension_loaded($extension);
    }
    if (PHP_MAJOR_VERSION !== 8 || in_array(false, $extensions, true)) {
        throw new Stage01Failure('PHP_RUNTIME_UNSUPPORTED');
    }

    return [
        'php_version' => PHP_VERSION,
        'extensions' => $extensions,
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
    ];
}

function checkDatabase(array $config): array
{
    if (!extension_loaded('pdo_mysql')) {
        throw new Stage01Failure('PDO_MYSQL_MISSING');
    }
    try {
        $pdo = loreverseDatabase($config);
        $session = $pdo->query('SELECT @@character_set_connection AS charset, @@session.time_zone AS timezone')
            ->fetch();
        if ($session !== ['charset' => 'utf8mb4', 'timezone' => '+00:00']) {
            throw new Stage01Failure('DATABASE_SESSION_INVALID');
        }
        $tables = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND ENGINE = 'InnoDB' AND TABLE_COLLATION = 'utf8mb4_unicode_ci'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $required = [
            'users', 'worlds', 'characters', 'relationships', 'chat_sessions', 'chat_messages',
            'stories', 'story_turns', 'timeline_events', 'favorites', 'ai_usage_logs',
        ];
        if (array_diff($required, $tables) !== []) {
            throw new Stage01Failure('DATABASE_SCHEMA_INCOMPLETE');
        }

        return [
            'database_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'schema_tables_verified' => count($required),
            'charset' => $session['charset'],
            'timezone' => $session['timezone'],
        ];
    } catch (InvalidArgumentException) {
        throw new Stage01Failure('DATABASE_CONFIG_INVALID');
    } catch (PDOException) {
        throw new Stage01Failure('DATABASE_CONNECTION_OR_QUERY_FAILED');
    }
}

function checkGemini(array $config): array
{
    if (!extension_loaded('mbstring')) {
        throw new Stage01Failure('MBSTRING_MISSING');
    }
    $result = (new GeminiService())->generateStructured(
        $config,
        '당신은 창작 보조 AI입니다. 제공된 가상의 주제를 한국어 한 문장으로 묘사하고 지정된 JSON 형식으로만 반환하세요.',
        ['topic' => '보랏빛 차원 포털'],
        [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string']],
            'required' => ['message'],
        ],
    );
    $message = $result->message ?? null;
    if (!is_string($message) || trim($message) === '' || mb_strlen($message, 'UTF-8') > 300) {
        throw new Stage01Failure('GEMINI_SCHEMA_MISMATCH');
    }

    return ['gemini_json_validated' => true];
}

function runCheck(string $check, array $config): array
{
    return match ($check) {
        'runtime' => checkRuntime(),
        'database' => checkDatabase($config),
        'gemini' => checkGemini($config),
        default => throw new Stage01Failure('UNKNOWN_CHECK'),
    };
}

function checkError(Throwable $error): string
{
    return $error instanceof Stage01Failure || $error instanceof GeminiException
        ? $error->getMessage()
        : 'INTERNAL_ERROR';
}

if (PHP_SAPI === 'cli') {
    try {
        echo json_encode(
            ['ok' => true, 'data' => runCheck($argv[1] ?? 'runtime', loreverseConfig())],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        ), PHP_EOL;
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, checkError($error) . PHP_EOL);
        exit(1);
    }
}

function diagnosticResponse(int $status, array $response): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_THROW_ON_ERROR);
    exit;
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

try {
    $config = loreverseConfig();
} catch (Throwable) {
    http_response_code(404);
    exit;
}
$url = is_string($config['APP_BASE_URL']) ? parse_url($config['APP_BASE_URL']) : false;
$scheme = $url['scheme'] ?? null;
$host = $url['host'] ?? null;
$token = $config['STAGE01_DIAGNOSTIC_TOKEN'];
$isProduction = $config['APP_ENV'] === 'production';
$isHttps = in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true);
if (
    !is_string($token) || !preg_match('/\A[0-9a-f]{64}\z/', $token)
    || !is_string($host) || isset($url['user']) || isset($url['pass'])
    || !in_array($scheme, ['http', 'https'], true)
    || ($isProduction && (!$isHttps || $scheme !== 'https'))
    || (!$isProduction && ($config['APP_ENV'] !== 'local'
        || !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)))
) {
    http_response_code(404);
    exit;
}
$port = $url['port'] ?? null;
$origin = $scheme . '://' . $host;
if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
    $origin .= ':' . $port;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    diagnosticResponse(405, ['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
}
session_name('LOREVERSE_STAGE01');
if (!session_start([
    'use_strict_mode' => 1,
    'cookie_httponly' => true,
    'cookie_secure' => $isHttps,
    'cookie_samesite' => 'Strict',
])) {
    diagnosticResponse(503, ['ok' => false, 'error' => 'SESSION_UNAVAILABLE']);
}

if ($method === 'POST') {
    $allowedOrigin = ($_SERVER['HTTP_ORIGIN'] ?? '') === $origin;
    if (!$allowedOrigin) {
        diagnosticResponse(403, ['ok' => false, 'error' => 'ORIGIN_REJECTED']);
    }
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
    if ($contentType !== 'application/json') {
        diagnosticResponse(415, ['ok' => false, 'error' => 'JSON_REQUIRED']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    $body = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, true) : null;
    $csrf = $_SESSION['csrf'] ?? null;
    session_write_close();
    if (
        !is_array($body) || !is_string($body['csrf'] ?? null) || !is_string($csrf)
        || !hash_equals($csrf, $body['csrf'])
        || !is_string($body['token'] ?? null) || !hash_equals($token, $body['token'])
    ) {
        diagnosticResponse(403, ['ok' => false, 'error' => 'DIAGNOSTIC_AUTH_REQUIRED']);
    }
    $check = $body['check'] ?? null;
    if (!in_array($check, ['post', 'runtime', 'database', 'gemini'], true)) {
        diagnosticResponse(400, ['ok' => false, 'error' => 'UNKNOWN_CHECK']);
    }
    try {
        $data = $check === 'post' ? ['same_origin_post' => true] : runCheck($check, $config);
        diagnosticResponse(200, ['ok' => true, 'data' => $data]);
    } catch (Throwable $error) {
        diagnosticResponse(503, ['ok' => false, 'error' => checkError($error)]);
    }
}

$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];
session_write_close();
$nonce = base64_encode(random_bytes(18));
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; connect-src 'self'; "
    . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="ko">
<meta charset="utf-8">
<title>LOREVERSE 01단계 진단</title>
<h1>LOREVERSE 01단계 진단</h1>
<form id="diagnostic" method="post">
    <label>임시 진단 토큰 <input id="token" type="password" required autocomplete="off"></label>
    <label>점검
        <select id="check">
            <option value="post">동일 호스트 POST</option>
            <option value="runtime">PHP 환경</option>
            <option value="database">PDO와 DB 스키마</option>
            <option value="gemini">Gemini 실제 JSON 요청</option>
        </select>
    </label>
    <button type="submit">실행</button>
</form>
<pre id="result" aria-live="polite"></pre>
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
const form = document.querySelector('#diagnostic');
const button = form.querySelector('button');
const result = document.querySelector('#result');
const csrf = <?= json_encode($csrf, JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?>;

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    button.disabled = true;
    result.textContent = '점검 중';
    try {
        const response = await fetch(location.pathname, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf,
                token: document.querySelector('#token').value,
                check: document.querySelector('#check').value,
            }),
        });
        result.textContent = JSON.stringify(await response.json(), null, 2);
    } catch {
        result.textContent = '서버의 진단 응답을 읽을 수 없습니다.';
    } finally {
        button.disabled = false;
    }
});
</script>
</html>
