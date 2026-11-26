<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/world-data.php';

final class WorldService
{
    public function __construct(private PDO $pdo, private int $userId)
    {
    }

    public function ownedRow(int $id, bool $lock = false): array
    {
        $query = $this->pdo->prepare('SELECT * FROM worlds WHERE id = ? AND user_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id, $this->userId]);
        $row = $query->fetch();
        if (!$row) {
            throw new LoreverseWorldError('WORLD_NOT_FOUND', '세계관을 찾을 수 없어요.', 404);
        }
        return $row;
    }

    public function get(int $id): array
    {
        $row = $this->ownedRow($id);
        foreach (WORLD_LIST_FIELDS as $field) {
            $row[$field] = json_decode($row[$field], true, 32, JSON_THROW_ON_ERROR);
        }
        return $this->publicRow($row);
    }

    private function publicRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['custom_genre'] ??= '';
        $row['has_cover'] = $row['cover_image'] !== null;
        unset($row['user_id'], $row['cover_image']);
        return $row;
    }

    public function list(array $filters = []): array
    {
        $where = ['user_id = ?'];
        $args = [$this->userId];
        $q = is_string($filters['q'] ?? null) ? mb_substr(trim($filters['q']), 0, 100, 'UTF-8') : '';
        if ($q !== '') {
            $pattern = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where[] = "(name LIKE ? ESCAPE '!' OR description LIKE ? ESCAPE '!')";
            array_push($args, $pattern, $pattern);
        }
        $genre = $filters['genre'] ?? null;
        if (in_array($genre, LOREVERSE_GENRES, true)) {
            $where[] = 'genre = ?';
            $args[] = $genre;
        }
        $columns = ['updated' => 'updated_at DESC', 'created' => 'created_at DESC', 'name' => 'name ASC'];
        $sort = is_string($filters['sort'] ?? null) ? $filters['sort'] : '';
        $order = $columns[$sort] ?? $columns['updated'];
        $limit = filter_var($filters['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]) ?: 20;
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;
        $condition = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM worlds WHERE ' . $condition);
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;
        $query = $this->pdo->prepare(
            'SELECT id, name, genre, custom_genre, LEFT(description, 240) AS description, cover_image, created_at, updated_at
               FROM worlds WHERE ' . $condition . ' ORDER BY ' . $order . ', id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $query->execute($args);
        return [
            'items' => array_map(fn(array $row): array => $this->publicRow($row), $query->fetchAll()),
            'page' => $page, 'pages' => $pages, 'total' => $total,
        ];
    }

    public function summary(): array
    {
        $query = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM worlds WHERE user_id = ?) AS worlds,
                    (SELECT COUNT(*) FROM characters WHERE user_id = ?) AS characters,
                    (SELECT COUNT(*) FROM stories WHERE user_id = ?) AS stories'
        );
        $query->execute([$this->userId, $this->userId, $this->userId]);
        $counts = array_map('intval', $query->fetch());
        return ['counts' => $counts, 'recent' => $this->list(['limit' => 5])['items'],
            'activities' => $this->recentActivity()];
    }

    private function recentActivity(): array
    {
        $query = $this->pdo->prepare("SELECT 'world' AS kind, id, name, updated_at FROM worlds WHERE user_id = ?
            UNION ALL SELECT 'character', id, name, updated_at FROM characters WHERE user_id = ?
            UNION ALL SELECT 'story', id, title, updated_at FROM stories WHERE user_id = ?
            ORDER BY updated_at DESC, id DESC LIMIT 8");
        $query->execute([$this->userId, $this->userId, $this->userId]);
        return $query->fetchAll();
    }

    public function activityCounts(int $id): array
    {
        $query = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM characters WHERE world_id = w.id AND user_id = w.user_id) AS characters,
                    (SELECT COUNT(*) FROM timeline_events WHERE world_id = w.id) AS events
                FROM worlds w WHERE w.id = ? AND w.user_id = ?'
        );
        $query->execute([$id, $this->userId]);
        $counts = $query->fetch();
        if (!$counts) {
            throw new LoreverseWorldError('WORLD_NOT_FOUND', '세계관을 찾을 수 없어요.', 404);
        }
        return array_map('intval', $counts);
    }

    public function save(array $input): array
    {
        $id = isset($input['id']) ? loreverseWorldId($input['id']) : null;
        if ($id !== null) {
            $this->ownedRow($id);
        }
        $data = loreverseWorldData($input, true);
        $data['custom_genre'] = $data['custom_genre'] === '' ? null : $data['custom_genre'];
        foreach (WORLD_LIST_FIELDS as $field) {
            $data[$field] = json_encode($data[$field], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if ($id === null) {
            $this->insert($data);
            return $this->get((int) $this->pdo->lastInsertId());
        }
        $expected = $input['expected_updated_at'] ?? null;
        if (!is_string($expected) || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{6})?\z/', $expected)) {
            throw new LoreverseWorldError('VERSION_REQUIRED', '수정하기 전에 세계관을 다시 열어 주세요.', 409);
        }
        // The field names are built from the validated, fixed world schema.
        $assignments = implode(', ', array_map(static fn(string $key): string => $key . ' = ?', array_keys($data)));
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('UPDATE worlds SET ' . $assignments . ',
                 updated_at = GREATEST(UTC_TIMESTAMP(6), TIMESTAMPADD(MICROSECOND, 1, updated_at))
                 WHERE id = ? AND user_id = ? AND updated_at = ?');
            $query->execute([...array_values($data), $id, $this->userId, $expected]);
            if ($query->rowCount() === 0) {
                $this->ownedRow($id);
                throw new LoreverseWorldError('WORLD_CONFLICT', '다른 화면에서 이 세계관을 수정했어요. 다시 열어 최신 내용을 확인해 주세요.', 409);
            }
            $world = $this->get($id);
            $this->pdo->commit();
            return $world;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function insert(array $data): void
    {
        $fields = 'user_id, ' . implode(', ', array_keys($data));
        $values = implode(', ', array_fill(0, count($data) + 1, '?'));
        $query = $this->pdo->prepare('INSERT INTO worlds (' . $fields . ') VALUES (' . $values . ')');
        $query->execute([$this->userId, ...array_values($data)]);
    }

    public function duplicate(int $id): array
    {
        $source = $this->ownedRow($id);
        $fields = ['genre', ...array_keys(WORLD_TEXT_LIMITS), ...WORLD_LIST_FIELDS];
        $data = array_intersect_key($source, array_flip($fields));
        $suffix = ' (복사본)';
        $data['name'] = mb_substr($data['name'], 0, 100 - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
        $this->insert($data);
        return $this->get((int) $this->pdo->lastInsertId());
    }

    public function delete(int $id): ?string
    {
        require_once dirname(__DIR__) . '/character-image.php';
        $this->pdo->beginTransaction();
        try {
            $row = $this->ownedRow($id, true);
            $files = $this->pdo->prepare('SELECT profile_image FROM characters
                WHERE world_id = ? AND user_id = ? AND profile_image IS NOT NULL FOR UPDATE');
            $files->execute([$id, $this->userId]);
            $images = $files->fetchAll(PDO::FETCH_COLUMN);
            $query = $this->pdo->prepare('DELETE FROM worlds WHERE id = ? AND user_id = ?');
            $query->execute([$id, $this->userId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        foreach ($images as $image) {
            loreverseRemoveCharacterImage($image);
        }
        return $row['cover_image'];
    }
}
