<?php

declare(strict_types=1);

require_once __DIR__ . '/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/AccountService.php';

function loreverseAccountChangePassword(PDO $pdo, int $userId, array $data): array
{
    (new AccountService($pdo, $userId))->changePassword($data);
    // The previous session becomes anonymous before introducing the replacement.
    $_SESSION = [];
    if (!session_regenerate_id(false)) {
        throw new LoreverseWorldError('SESSION_UNAVAILABLE', '비밀번호가 변경됐어요. 다시 로그인해 주세요.', 503);
    }
    $_SESSION = ['user_id' => $userId, 'last_seen' => time()];
    $result = ['changed' => true, 'csrf' => loreverseCsrf()];
    session_write_close();
    return $result;
}

function loreverseAccountDelete(PDO $pdo, int $userId, array $data): array
{
    (new AccountService($pdo, $userId))->delete($data);
    return ['deleted' => true] + loreverseAuthAttempt('logout', $data);
}
