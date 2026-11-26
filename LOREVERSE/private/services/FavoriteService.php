<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/world-data.php';

final class FavoriteService
{
    private const TARGETS = [
        'world' => ['worlds', 'world_id', 'name'],
        'character' => ['characters', 'character_id', 'name'],
        'story' => ['stories', 'story_id', 'title'],
    ];

    public function __construct(private PDO $pdo, private int $userId)
    {
    }

    public function list(array $filters = []): array
    {
        $type = $filters['type'] ?? '';
        $target = $type === '' ? null : $this->target($type);
        $targets = $target === null ? self::TARGETS : [$type => $target];
        $queries = [];
        $args = [];
        foreach ($targets as $key => [$table, $field, $label]) {
            $queries[] = "SELECT '$key' AS type, target.id AS target_id, target.$label AS name,
                    f.id AS favorite_id, f.created_at
                FROM favorites f JOIN $table target ON target.id = f.$field AND target.user_id = f.user_id
                WHERE f.user_id = ?";
            $args[] = $this->userId;
        }
        $union = implode(' UNION ALL ', $queries);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM ($union) listed");
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $limit = filter_var($filters['limit'] ?? 20, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 50],
        ]) ?: 20;
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100000],
        ]) ?: 1;
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;
        $query = $this->pdo->prepare(
            "SELECT type, target_id, name, created_at FROM ($union) listed
             ORDER BY created_at DESC, favorite_id DESC LIMIT $limit OFFSET $offset"
        );
        $query->execute($args);
        $items = $query->fetchAll();
        foreach ($items as &$item) {
            $item['target_id'] = (int) $item['target_id'];
        }
        unset($item);
        return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function contains(string $type, int $id): bool
    {
        [$table, $field] = $this->target($type);
        $query = $this->pdo->prepare(
            "SELECT 1 FROM favorites f JOIN $table target ON target.id = f.$field AND target.user_id = f.user_id
             WHERE f.user_id = ? AND f.$field = ?"
        );
        $query->execute([$this->userId, $id]);
        return $query->fetchColumn() !== false;
    }

    public function toggle(array $input): array
    {
        $type = $input['type'] ?? null;
        [$table, $field] = $this->target($type);
        $rawId = $input['target_id'] ?? null;
        $id = filter_var(is_string($rawId) || is_int($rawId) ? $rawId : null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4294967295],
        ]);
        if ($id === false) {
            throw new LoreverseWorldError('FAVORITE_TARGET_INVALID', '즐겨찾기 대상을 확인해 주세요.');
        }
        $desired = match ($input['desired_state'] ?? null) {
            true, '1' => true,
            false, '0' => false,
            default => throw new LoreverseWorldError('FAVORITE_STATE_INVALID', '저장할 즐겨찾기 상태를 확인해 주세요.'),
        };

        $this->pdo->beginTransaction();
        try {
            // Keep the same user→resource lock order as account deletion.
            $user = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $user->execute([$this->userId]);
            if ($user->fetchColumn() === false) {
                throw new LoreverseWorldError('AUTH_REQUIRED', '로그인이 필요해요.', 401);
            }
            $ownership = $this->pdo->prepare("SELECT id FROM $table WHERE id = ? AND user_id = ? FOR UPDATE");
            $ownership->execute([$id, $this->userId]);
            if ($ownership->fetchColumn() === false) {
                throw new LoreverseWorldError('FAVORITE_TARGET_NOT_FOUND', '즐겨찾기 대상을 찾을 수 없어요.', 404);
            }
            $update = $desired
                ? "INSERT INTO favorites (user_id, $field) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id = user_id"
                : "DELETE FROM favorites WHERE user_id = ? AND $field = ?";
            $this->pdo->prepare($update)->execute([$this->userId, $id]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return ['type' => $type, 'target_id' => $id, 'favorited' => $desired];
    }

    private function target(mixed $type): array
    {
        if (!is_string($type) || !isset(self::TARGETS[$type])) {
            throw new LoreverseWorldError('FAVORITE_TYPE_INVALID', '세계관, 캐릭터, 스토리 중에서 선택해 주세요.');
        }
        return self::TARGETS[$type];
    }
}
