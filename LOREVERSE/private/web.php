<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/database.php';

function loreverseWebConfig(): array
{
    static $config;
    return $config ??= loreverseConfig();
}

function loreverseBasePath(): string
{
    $path = parse_url(loreverseWebConfig()['APP_BASE_URL'], PHP_URL_PATH);
    return is_string($path) ? rtrim($path, '/') : '';
}

function loreverseUrl(string $route = '', array $query = []): string
{
    return loreverseBasePath() . '/' . $route . ($query === [] ? '' : '?' . http_build_query($query));
}

function loreverseChatUrl(array $query = []): string
{
    return loreverseUrl('workspace.php', ['tab' => 'conversation'] + $query);
}

function loreverseHeroVideo(): ?array
{
    $url = loreverseWebConfig()['HERO_VIDEO_URL'];
    if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
        || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }

    $host = strtolower($parts['host']);
    if (!in_array($host, ['youtu.be', 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com'], true)) {
        return ['type' => 'file', 'url' => $url];
    }

    $path = $parts['path'] ?? '';
    $videoId = null;
    if ($host === 'youtu.be') {
        $videoId = substr($path, 1);
    } elseif ($path === '/watch') {
        parse_str($parts['query'] ?? '', $query);
        $videoId = $query['v'] ?? null;
    } elseif (str_starts_with($path, '/embed/')) {
        $videoId = substr($path, strlen('/embed/'));
    }

    return is_string($videoId) && preg_match('/\A[A-Za-z0-9_-]{11}\z/', $videoId)
        ? ['type' => 'youtube', 'url' => 'https://www.youtube-nocookie.com/embed/' . $videoId]
        : null;
}

function loreverseWebStart(): void
{
    $production = loreverseWebConfig()['APP_ENV'] === 'production';
    $https = in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true);
    if ($production && !$https) {
        http_response_code(400);
        exit('HTTPS 연결을 사용해 주세요.');
    }

    $video = loreverseHeroVideo();
    $media = "'self'";
    if (($video['type'] ?? null) === 'file') {
        $origin = parse_url($video['url']);
        $media .= ' https://' . $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '');
    }
    $frames = ($video['type'] ?? null) === 'youtube' ? 'frame-src https://www.youtube-nocookie.com; ' : '';
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
        . "img-src 'self' blob:; font-src 'self'; media-src {$media}; connect-src 'self'; "
        . $frames . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: no-store');
    if ($production) {
        header('Strict-Transport-Security: max-age=31536000');
    }

    session_name('LOREVERSE_SESSION');
    if (!session_start([
        'use_strict_mode' => true,
        'use_only_cookies' => true,
        'cookie_path' => loreverseBasePath() . '/',
        'cookie_secure' => $https,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'gc_maxlifetime' => 7200,
    ])) {
        http_response_code(503);
        exit('세션을 시작할 수 없습니다. 잠시 후 다시 시도해 주세요.');
    }

    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] >= 7200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
}

function loreverseCsrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function loreverseSafeNext(mixed $value): string
{
    $fallback = loreverseUrl('workspace.php');
    if (!is_string($value) || strlen($value) > 200) {
        return $fallback;
    }
    $parts = explode('?', $value, 2);
    $path = substr($parts[0], strlen(loreverseBasePath()) + 1);
    $allowed = [
        'workspace.php' => ['create', 'genre', 'tab', 'type'],
        'world-create.php' => ['id', 'genre'],
        'world.php' => ['id'],
        'characters.php' => ['world_id'],
        'character-create.php' => ['id', 'world_id'],
        'character.php' => ['id', 'tab'],
        'relationships.php' => ['world_id', 'id', 'character_id'],
        'chat.php' => ['world_id', 'character_id', 'session_id'],
        'story.php' => ['world_id', 'id', 'story_id'],
        'timeline.php' => ['world_id', 'id'],
        'mypage.php' => ['tab', 'type'],
    ];
    if (!isset($allowed[$path]) || $parts[0] !== loreverseUrl($path)) {
        return $fallback;
    }
    parse_str($parts[1] ?? '', $query);
    $fields = $path === 'workspace.php' && ($query['tab'] ?? null) === 'conversation'
        ? ['tab', 'world_id', 'character_id', 'session_id'] : $allowed[$path];
    $genres = ['fantasy', 'cyberpunk', 'modern', 'apocalypse', 'dark_fantasy', 'historical', 'custom'];
    $safe = [];
    foreach ($fields as $field) {
        $value = $query[$field] ?? null;
        if ($field === 'genre' && in_array($value, $genres, true)) {
            $safe[$field] = $value;
        } elseif ($field === 'create' && in_array($value, ['world', 'character'], true)) {
            $safe[$field] = $value;
        } elseif ($field === 'tab' && is_string($value) && preg_match('/\A[a-z-]{1,20}\z/', $value)) {
            $safe[$field] = $value;
        } elseif ($field === 'type' && in_array($value, ['world', 'character', 'story'], true)) {
            $safe[$field] = $value;
        } elseif (str_ends_with($field, 'id')) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            if ($id !== false) {
                $safe[$field] = $id;
            }
        }
    }
    if (in_array($path, ['world.php', 'character.php'], true) && !isset($safe['id'])) {
        return $fallback;
    }
    return loreverseUrl($path, $safe);
}

function loreversePageUser(string $next): int
{
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit('이 페이지에서 사용할 수 없는 요청입니다.');
    }
    if (!isset($_SESSION['user_id'])) {
        loreverseRedirect(loreverseUrl('login.php', ['next' => loreverseSafeNext($next)]));
    }
    return (int) $_SESSION['user_id'];
}

function loreverseRedirect(string $path): void
{
    header('Location: ' . $path, true, 303);
    exit;
}
