<?php

declare(strict_types=1);

final class LoreverseAuthError extends RuntimeException
{
    public function __construct(
        public string $reason,
        string $message,
        public int $status,
        public array $fields = []
    ) {
        parent::__construct($message);
    }
}

function loreverseAuthThrottle(PDO $pdo, string $action, string $email): void
{
    $now = time();
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $limits = $action === 'register'
        ? [['register-ip:' . $ip, 8, 1800]]
        : [['login-ip:' . $ip, 25, 900], ['login-email:' . $email, 8, 900]];

    if (random_int(1, 50) === 1) {
        $prune = $pdo->prepare('DELETE FROM auth_rate_limits WHERE window_start < ? LIMIT 200');
        $prune->execute([$now - 86400]);
    }
    $increment = $pdo->prepare(
        'INSERT INTO auth_rate_limits (subject_hash, window_start, attempts) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE
           attempts = IF(window_start = VALUES(window_start), LEAST(attempts + 1, ?), 1),
           window_start = VALUES(window_start)'
    );
    $read = $pdo->prepare('SELECT attempts FROM auth_rate_limits WHERE subject_hash = ?');
    $blocked = false;
    $pdo->beginTransaction();
    try {
        foreach ($limits as [$subject, $maximum, $seconds]) {
            $key = hash('sha256', $subject);
            $start = intdiv($now, $seconds) * $seconds;
            $increment->execute([$key, $start, $maximum + 1]);
            $read->execute([$key]);
            if ((int) $read->fetchColumn() > $maximum) {
                $blocked = true;
                break;
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    if ($blocked) {
        throw new LoreverseAuthError('RATE_LIMITED', '요청이 너무 많아요. 잠시 후 다시 시도해 주세요.', 429);
    }
}

function loreverseAuthInput(array $data, string $name): string
{
    $value = $data[$name] ?? '';
    return is_string($value) ? $value : '';
}

function loreverseAuthRegister(PDO $pdo, array $data, string $email): array
{
    $nicknameInput = loreverseAuthInput($data, 'nickname');
    $nickname = preg_replace('/\A\s+|\s+\z/u', '', $nicknameInput) ?? '';
    $password = loreverseAuthInput($data, 'password');
    $fields = [];
    if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $fields['email'] = '이메일 주소를 확인해 주세요.';
    }
    if (mb_strlen($nickname, 'UTF-8') < 2 || mb_strlen($nickname, 'UTF-8') > 30
        || preg_match('/[\x00-\x1F\x7F]/u', $nickname)) {
        $fields['nickname'] = '닉네임은 2~30자로 입력해 주세요.';
    }
    if (!mb_check_encoding($password, 'UTF-8') || strlen($password) > 72
        || mb_strlen($password, 'UTF-8') < 8 || str_contains($password, "\0")) {
        $fields['password'] = '비밀번호는 8자 이상, UTF-8 72바이트 이하로 입력해 주세요.';
    }
    if ($password !== loreverseAuthInput($data, 'password_confirm')) {
        $fields['password_confirm'] = '비밀번호가 일치하지 않아요.';
    }
    if ($fields !== []) {
        throw new LoreverseAuthError('VALIDATION_ERROR', '입력 내용을 확인해 주세요.', 400, $fields);
    }

    $insert = $pdo->prepare('INSERT INTO users (email, nickname, password_hash) VALUES (?, ?, ?)');
    try {
        $insert->execute([$email, $nickname, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new LoreverseAuthError('EMAIL_IN_USE', '이미 등록된 이메일이에요.', 409, [
                'email' => '다른 이메일을 사용하거나 로그인해 주세요.',
            ]);
        }
        throw $error;
    }
    return ['id' => (int) $pdo->lastInsertId(), 'nickname' => $nickname];
}

function loreverseAuthLogin(PDO $pdo, array $data, string $email): array
{
    $password = loreverseAuthInput($data, 'password');
    $find = $pdo->prepare('SELECT id, nickname, password_hash FROM users WHERE email = ?');
    $find->execute([$email]);
    $user = $find->fetch();
    // Equalizes password verification work for unknown and known email addresses.
    $dummyHash = PHP_VERSION_ID >= 80400
        ? '$2y$12$XTu9Jv5Z.ZkLD8crfD6qxOMI8Rxw5zssm8XYd5Z7WvtRw2KUmxAEa'
        : '$2y$10$xhXcVBsU1Cd1lKxoIcx7jeg.OnyPq45zchSAgmPL4JQ/3sajTuHPO';
    $hash = $user['password_hash'] ?? $dummyHash;
    $bounded = strlen($password) <= 72 && !str_contains($password, "\0");
    $matches = password_verify($bounded ? $password : '', $hash);
    if (!$user || !$matches || !$bounded) {
        throw new LoreverseAuthError('INVALID_CREDENTIALS', '이메일 또는 비밀번호를 확인해 주세요.', 401);
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND password_hash = ?');
        $update->execute([password_hash($password, PASSWORD_DEFAULT), $user['id'], $hash]);
    }
    return ['id' => (int) $user['id'], 'nickname' => $user['nickname']];
}

function loreverseAuthAttempt(string $action, array $data): array
{
    $csrf = loreverseAuthInput($data, '_csrf');
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
        throw new LoreverseAuthError('CSRF_INVALID', '페이지를 새로고침한 후 다시 시도해 주세요.', 403);
    }
    if ($action === 'logout') {
        if (!isset($_SESSION['user_id'])) {
            throw new LoreverseAuthError('AUTH_REQUIRED', '로그인이 필요해요.', 401);
        }
        $_SESSION = [];
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600, 'path' => $cookie['path'],
            'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_destroy();
        return ['redirect' => loreverseUrl('index.php')];
    }

    $email = strtolower(trim(loreverseAuthInput($data, 'email')));
    $pdo = loreverseDatabase(loreverseWebConfig());
    loreverseAuthThrottle($pdo, $action, $email);
    $user = $action === 'register'
        ? loreverseAuthRegister($pdo, $data, $email)
        : loreverseAuthLogin($pdo, $data, substr($email, 0, 256));
    // Keep the old session anonymous so overlapping requests cannot replace the new cookie.
    $_SESSION = [];
    if (!session_regenerate_id(false)) {
        throw new RuntimeException('SESSION_REGENERATION_FAILED');
    }
    $_SESSION = ['user_id' => $user['id'], 'last_seen' => time()];
    session_write_close();
    return ['redirect' => loreverseSafeNext($data['next'] ?? null)];
}

function loreverseAuthFailure(Throwable $error): LoreverseAuthError
{
    if ($error instanceof LoreverseAuthError) {
        return $error;
    }
    error_log('LOREVERSE: authentication operation failed (' . get_class($error) . ')');
    return new LoreverseAuthError('AUTH_UNAVAILABLE', '계정 서비스에 연결할 수 없어요. 잠시 후 다시 시도해 주세요.', 503);
}
