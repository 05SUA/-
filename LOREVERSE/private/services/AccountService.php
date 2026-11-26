<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/user-image.php';
require_once dirname(__DIR__) . '/world-cover.php';
require_once __DIR__ . '/FavoriteService.php';

final class AccountService
{
    public const DELETE_CONFIRMATION = '계정 삭제';

    public function __construct(private PDO $pdo, private int $userId)
    {
    }

    public function profile(): array
    {
        $user = $this->ownedRow();
        $counts = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM worlds WHERE user_id = ?) AS worlds,
                    (SELECT COUNT(*) FROM characters WHERE user_id = ?) AS characters,
                    (SELECT COUNT(*) FROM stories WHERE user_id = ?) AS stories'
        );
        $counts->execute([$this->userId, $this->userId, $this->userId]);
        $stats = array_map('intval', $counts->fetch());
        $stats['favorites'] = (new FavoriteService($this->pdo, $this->userId))->list(['limit' => 1])['total'];
        return [
            'profile' => [
                'id' => $this->userId, 'email' => $user['email'], 'nickname' => $user['nickname'],
                'has_image' => $user['profile_image'] !== null,
                'created_at' => $user['created_at'], 'updated_at' => $user['updated_at'],
            ],
            'stats' => $stats,
        ];
    }

    public function update(array $data, mixed $file = null): array
    {
        $nickname = preg_replace('/\A\s+|\s+\z/u', '', loreverseAuthInput($data, 'nickname')) ?? '';
        $length = mb_strlen($nickname, 'UTF-8');
        if ($length < 2 || $length > 30 || preg_match('/[\x00-\x1F\x7F]/u', $nickname)) {
            throw new LoreverseWorldError('NICKNAME_INVALID', '닉네임은 2~30자로 입력해 주세요.');
        }
        $remove = in_array($data['remove_image'] ?? false, [true, '1'], true);
        $hasFile = $file !== null && (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_NO_FILE);
        if ($remove && $hasFile) {
            throw new LoreverseWorldError('IMAGE_ACTION_INVALID', '새 이미지 업로드와 기존 이미지 삭제 중 하나를 선택해 주세요.');
        }
        $newImage = $hasFile ? loreverseStoreUserImage($file) : null;
        try {
            $this->pdo->beginTransaction();
            $oldImage = $this->ownedRow(true)['profile_image'];
            $image = $remove ? null : ($newImage ?? $oldImage);
            $query = $this->pdo->prepare('UPDATE users SET nickname = ?, profile_image = ? WHERE id = ?');
            $query->execute([$nickname, $image, $this->userId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            loreverseRemoveUserImage($newImage);
            throw $error;
        }
        if ($oldImage !== $image) {
            loreverseRemoveUserImage($oldImage);
        }
        return $this->profile();
    }

    public function changePassword(array $data): void
    {
        $current = loreverseAuthInput($data, 'current_password');
        $password = loreverseAuthInput($data, 'new_password');
        if (!mb_check_encoding($password, 'UTF-8') || strlen($password) > 72
            || mb_strlen($password, 'UTF-8') < 8 || str_contains($password, "\0")) {
            throw new LoreverseWorldError('PASSWORD_INVALID', '새 비밀번호는 8자 이상, UTF-8 72바이트 이하로 입력해 주세요.');
        }
        if ($password !== loreverseAuthInput($data, 'password_confirm')) {
            throw new LoreverseWorldError('PASSWORD_MISMATCH', '새 비밀번호 확인이 일치하지 않아요.');
        }
        $this->throttleReauthentication();
        $this->pdo->beginTransaction();
        try {
            $this->verifyPassword($current, $this->ownedRow(true)['password_hash']);
            $statement = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $statement->execute([password_hash($password, PASSWORD_DEFAULT), $this->userId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function delete(array $data): void
    {
        if (loreverseAuthInput($data, 'confirmation') !== self::DELETE_CONFIRMATION) {
            throw new LoreverseWorldError('CONFIRMATION_REQUIRED', '확인 문구에 계정 삭제를 입력해 주세요.');
        }
        require_once dirname(__DIR__) . '/character-image.php';
        $this->throttleReauthentication();
        $this->pdo->beginTransaction();
        try {
            $user = $this->ownedRow(true);
            $this->verifyPassword(loreverseAuthInput($data, 'current_password'), $user['password_hash']);
            $covers = $this->pdo->prepare('SELECT cover_image FROM worlds WHERE user_id = ? FOR UPDATE');
            $covers->execute([$this->userId]);
            $worldImages = $covers->fetchAll(PDO::FETCH_COLUMN);
            $profiles = $this->pdo->prepare('SELECT profile_image FROM characters WHERE user_id = ? FOR UPDATE');
            $profiles->execute([$this->userId]);
            $characterImages = $profiles->fetchAll(PDO::FETCH_COLUMN);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        foreach ($characterImages as $name) {
            loreverseRemoveCharacterImage($name);
        }
        foreach ($worldImages as $name) {
            loreverseRemoveWorldCover($name);
        }
        loreverseRemoveUserImage($user['profile_image']);
    }

    private function ownedRow(bool $lock = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT email, nickname, password_hash, profile_image, created_at, updated_at
             FROM users WHERE id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$this->userId]);
        $row = $statement->fetch();
        if (!$row) {
            throw new LoreverseWorldError('AUTH_REQUIRED', '로그인이 필요해요.', 401);
        }
        return $row;
    }

    private function verifyPassword(string $password, string $hash): void
    {
        if (strlen($password) > 72 || str_contains($password, "\0") || !password_verify($password, $hash)) {
            throw new LoreverseWorldError('PASSWORD_INCORRECT', '현재 비밀번호를 확인해 주세요.', 403);
        }
    }

    private function throttleReauthentication(): void
    {
        $subject = hash('sha256', 'account-reauth:' . $this->userId);
        $start = intdiv(time(), 900) * 900;
        $this->pdo->beginTransaction();
        try {
            $increment = $this->pdo->prepare(
                'INSERT INTO auth_rate_limits (subject_hash, window_start, attempts) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                   attempts = IF(window_start = VALUES(window_start), LEAST(attempts + 1, 9), 1),
                   window_start = VALUES(window_start)'
            );
            $increment->execute([$subject, $start]);
            $read = $this->pdo->prepare('SELECT attempts FROM auth_rate_limits WHERE subject_hash = ?');
            $read->execute([$subject]);
            $blocked = (int) $read->fetchColumn() > 8;
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if ($blocked) {
            throw new LoreverseWorldError('RATE_LIMITED', '요청이 너무 많아요. 잠시 후 다시 시도해 주세요.', 429);
        }
    }
}
