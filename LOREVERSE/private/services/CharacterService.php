<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/character-data.php';
require_once __DIR__ . '/WorldService.php';

final class CharacterService
{
    public function __construct(private PDO $pdo, private int $userId)
    {
    }

    public function ownedRow(int $id, bool $lock = false): array
    {
        $query = $this->pdo->prepare('SELECT * FROM characters WHERE id = ? AND user_id = ?'
            . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id, $this->userId]);
        $row = $query->fetch();
        if (!$row) {
            throw new LoreverseWorldError('CHARACTER_NOT_FOUND', '캐릭터를 찾을 수 없어요.', 404);
        }
        return $row;
    }

    public function get(int $id): array
    {
        return $this->detail($this->ownedRow($id));
    }

    public function worldOptions(): array
    {
        $query = $this->pdo->prepare('SELECT id, name FROM worlds WHERE user_id = ? ORDER BY name, id');
        $query->execute([$this->userId]);
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => $row['name']], $query->fetchAll());
    }

    public function list(array $filters = []): array
    {
        $where = ['c.user_id = ?'];
        $args = [$this->userId];
        $world = $filters['world_id'] ?? '';
        if ($world === 'independent') {
            $where[] = 'c.world_id IS NULL';
        } elseif ($world !== '') {
            $world = loreverseWorldId($world);
            $this->world($world);
            $where[] = 'c.world_id = ?';
            $args[] = $world;
        }

        $search = $this->searchPattern($filters['q'] ?? null);
        if ($search !== null) {
            $where[] = "(c.name LIKE ? ESCAPE '!' OR c.occupation LIKE ? ESCAPE '!' OR c.affiliation LIKE ? ESCAPE '!')";
            array_push($args, $search, $search, $search);
        }
        foreach (['occupation', 'affiliation'] as $field) {
            $search = $this->searchPattern($filters[$field] ?? null);
            if ($search !== null) {
                $where[] = "c.{$field} LIKE ? ESCAPE '!'";
                $args[] = $search;
            }
        }

        $orders = ['updated' => 'c.updated_at DESC', 'created' => 'c.created_at DESC', 'name' => 'c.name ASC'];
        $sort = $filters['sort'] ?? '';
        $order = is_string($sort) ? ($orders[$sort] ?? $orders['updated']) : $orders['updated'];
        $limit = filter_var($filters['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]) ?: 20;
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;
        $condition = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM characters c WHERE ' . $condition);
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;
        $query = $this->pdo->prepare(
            'SELECT c.id, c.world_id, c.name, c.age, c.gender, c.species, c.occupation, c.affiliation,
                    LEFT(c.background, 240) AS background, c.profile_image, c.created_at, c.updated_at, w.name AS world_name
               FROM characters c LEFT JOIN worlds w ON w.id = c.world_id AND w.user_id = c.user_id
              WHERE ' . $condition . ' ORDER BY ' . $order . ', c.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $query->execute($args);
        return [
            'items' => array_map(fn(array $row): array => $this->publicRow($row), $query->fetchAll()),
            'page' => $page, 'pages' => $pages, 'total' => $total,
        ];
    }

    public function save(array $input): array
    {
        $id = isset($input['id']) ? loreverseCharacterId($input['id']) : null;
        $this->pdo->beginTransaction();
        try {
            $row = $id === null ? null : $this->ownedRow($id, true);
            if ($row !== null) {
                $this->checkVersion($input['expected_updated_at'] ?? null, $row['updated_at']);
                $input += $this->decodeFields($row);
            }
            $data = loreverseCharacterData($input, true);
            $worldId = $data['world_id'];
            $worldName = $worldId === null ? null : $this->world($worldId)['name'];
            $data = $this->encodeFields($data);

            if ($row === null) {
                $id = $this->insert($data);
            } else {
                $previousWorld = $row['world_id'] === null ? null : (int) $row['world_id'];
                if ($previousWorld !== $worldId) {
                    $this->checkMove($id, $previousWorld);
                    $this->removeTimelineReference($id, $previousWorld);
                }
                $assignments = implode(', ', array_map(static fn(string $key): string => $key . ' = ?', array_keys($data)));
                // Existing deployments use second precision. Always advance the edit token.
                $query = $this->pdo->prepare('UPDATE characters SET ' . $assignments . ',
                    updated_at = GREATEST(UTC_TIMESTAMP(), TIMESTAMPADD(SECOND, 1, updated_at))
                    WHERE id = ? AND user_id = ?');
                $query->execute([...array_values($data), $id, $this->userId]);
            }
            $character = $this->publicRow($this->decodeFields($this->ownedRow($id)) + ['world_name' => $worldName]);
            $this->pdo->commit();
            return $character;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function duplicate(int $id): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->ownedRow($id, true);
            $columns = ['world_id', ...array_keys(CHARACTER_TEXT_LIMITS), ...CHARACTER_LIST_FIELDS, 'appearance'];
            $data = array_intersect_key($this->decodeFields($row), array_flip($columns));
            $suffix = ' (복사본)';
            $data['name'] = mb_substr($data['name'], 0, CHARACTER_TEXT_LIMITS['name'] - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
            $character = $this->get($this->insert($this->encodeFields($data)));
            $this->pdo->commit();
            return $character;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function delete(int $id): ?string
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->ownedRow($id, true);
            $worldId = $row['world_id'] === null ? null : (int) $row['world_id'];
            if ($this->hasStory($id, $worldId, true)) {
                throw new LoreverseWorldError('CHARACTER_ACTIVE_STORY', '진행 중인 스토리에 등장하는 캐릭터예요. 스토리를 종료하거나 삭제한 후 다시 시도해 주세요.', 409);
            }
            $this->removeTimelineReference($id, $worldId);
            $query = $this->pdo->prepare('DELETE FROM characters WHERE id = ? AND user_id = ?');
            $query->execute([$id, $this->userId]);
            $this->pdo->commit();
            return $row['profile_image'];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function detail(array $row): array
    {
        $world = $row['world_id'] === null ? null : $this->world((int) $row['world_id'])['name'];
        return $this->publicRow($this->decodeFields($row) + ['world_name' => $world]);
    }

    private function world(int $id): array
    {
        return (new WorldService($this->pdo, $this->userId))->ownedRow($id);
    }

    private function publicRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['world_id'] = $row['world_id'] === null ? null : (int) $row['world_id'];
        $row['has_image'] = $row['profile_image'] !== null;
        unset($row['user_id'], $row['profile_image']);
        return $row;
    }

    private function searchPattern(mixed $filter): ?string
    {
        $text = is_string($filter) ? mb_substr(trim($filter), 0, 100, 'UTF-8') : '';
        return $text === '' ? null : '%' . strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    private function decodeFields(array $row): array
    {
        $row['values'] = $row['values_json'];
        unset($row['values_json']);
        foreach (['appearance', ...CHARACTER_LIST_FIELDS] as $field) {
            $row[$field] = json_decode($row[$field], true, 32, JSON_THROW_ON_ERROR);
        }
        return $row;
    }

    private function encodeFields(array $data): array
    {
        $data['appearance'] = json_encode((object) $data['appearance'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (CHARACTER_LIST_FIELDS as $field) {
            $data[$field] = json_encode($data[$field], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $data['values_json'] = $data['values'];
        unset($data['values']);
        return $data;
    }

    private function insert(array $data): int
    {
        $columns = 'user_id, ' . implode(', ', array_keys($data));
        $values = implode(', ', array_fill(0, count($data) + 1, '?'));
        $query = $this->pdo->prepare('INSERT INTO characters (' . $columns . ') VALUES (' . $values . ')');
        $query->execute([$this->userId, ...array_values($data)]);
        return (int) $this->pdo->lastInsertId();
    }

    private function checkVersion(mixed $expected, string $current): void
    {
        if ($expected === null) {
            return;
        }
        if (!is_string($expected) || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{6})?\z/', $expected)) {
            throw new LoreverseWorldError('VERSION_REQUIRED', '수정하기 전에 캐릭터를 다시 열어 주세요.', 409);
        }
        if ($expected !== $current) {
            throw new LoreverseWorldError('CHARACTER_CONFLICT', '다른 화면에서 이 캐릭터를 수정했어요. 다시 열어 최신 내용을 확인해 주세요.', 409);
        }
    }

    private function checkMove(int $id, ?int $worldId): void
    {
        $query = $this->pdo->prepare('SELECT
            EXISTS(SELECT 1 FROM relationships WHERE world_id = ? AND (from_character_id = ? OR to_character_id = ?)) OR
            EXISTS(SELECT 1 FROM chat_sessions WHERE character_id = ?)');
        $query->execute([$worldId, $id, $id, $id]);
        if ((int) $query->fetchColumn() !== 0 || $this->hasStory($id, $worldId, false)) {
            throw new LoreverseWorldError('CHARACTER_MOVE_BLOCKED', '관계·대화·스토리에 연결된 캐릭터는 세계관을 옮길 수 없어요.', 409);
        }
    }

    private function hasStory(int $id, ?int $worldId, bool $activeOnly): bool
    {
        if ($worldId === null) {
            return false;
        }
        $query = $this->pdo->prepare('SELECT character_ids FROM stories WHERE world_id = ? AND user_id = ?'
            . ($activeOnly ? " AND status = 'active'" : '') . ' FOR UPDATE');
        $query->execute([$worldId, $this->userId]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $encoded) {
            $ids = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
            if (in_array($id, $ids, true) || in_array((string) $id, $ids, true)) {
                return true;
            }
        }
        return false;
    }

    private function removeTimelineReference(int $id, ?int $worldId): void
    {
        if ($worldId === null) {
            return;
        }
        $query = $this->pdo->prepare('SELECT id, character_ids FROM timeline_events WHERE world_id = ? FOR UPDATE');
        $query->execute([$worldId]);
        $update = $this->pdo->prepare('UPDATE timeline_events SET character_ids = ? WHERE id = ? AND world_id = ?');
        foreach ($query->fetchAll() as $event) {
            $ids = json_decode($event['character_ids'], true, 32, JSON_THROW_ON_ERROR);
            $remaining = array_values(array_filter($ids, static fn(mixed $value): bool => $value !== $id && $value !== (string) $id));
            if (count($remaining) !== count($ids)) {
                $update->execute([json_encode($remaining, JSON_THROW_ON_ERROR), $event['id'], $worldId]);
            }
        }
    }
}
