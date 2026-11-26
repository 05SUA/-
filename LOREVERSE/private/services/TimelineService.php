<?php

declare(strict_types=1);

require_once __DIR__ . '/WorldService.php';

final class TimelineService
{
    public const TYPES = ['world' => '세계관', 'character' => '인물', 'story' => '스토리'];

    public function __construct(private PDO $pdo, private int $user)
    {
    }

    public function listing(int $worldId, array $filters = []): array
    {
        (new WorldService($this->pdo, $this->user))->ownedRow($worldId);
        $scope = 'world_id = ?';
        $params = [$worldId];
        $type = $filters['type'] ?? '';
        if (is_string($type) && isset(self::TYPES[$type])) {
            $scope .= ' AND event_type = ?';
            $params[] = $type;
        }
        $size = filter_var($filters['limit'] ?? 20, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 50],
        ]) ?: 20;
        $page = max(1, filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM timeline_events WHERE ' . $scope);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $size));
        $page = min($page, $pages);
        $offset = ($page - 1) * $size;
        $query = $this->pdo->prepare('SELECT * FROM timeline_events WHERE ' . $scope
            . ' ORDER BY sort_order, id LIMIT ' . $size . ' OFFSET ' . $offset);
        $query->execute($params);
        return ['items' => array_map($this->decode(...), $query->fetchAll()), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function characters(int $worldId): array
    {
        $query = $this->pdo->prepare('SELECT id, name FROM characters WHERE world_id = ? AND user_id = ? ORDER BY name, id');
        $query->execute([$worldId, $this->user]);
        return array_column($query->fetchAll(), 'name', 'id');
    }

    private function decode(array $row): array
    {
        $row['character_ids'] = json_decode($row['character_ids'], true, 32, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function get(int $id): array
    {
        $query = $this->pdo->prepare('SELECT e.* FROM timeline_events e JOIN worlds w ON w.id = e.world_id
            WHERE e.id = ? AND w.user_id = ?');
        $query->execute([$id, $this->user]);
        $row = $query->fetch();
        if (!$row) {
            throw new LoreverseWorldError('EVENT_NOT_FOUND', '타임라인 사건을 찾을 수 없어요.', 404);
        }
        return $this->decode($row);
    }

    public function save(array $input): array
    {
        $world = loreverseWorldId($input['world_id'] ?? null);
        $id = ($input['id'] ?? '') === '' ? null : loreverseWorldId($input['id']);
        $order = filter_var($input['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        $type = $input['event_type'] ?? '';
        $ids = $input['character_ids'] ?? [];
        if ($order === false || !is_string($type) || !isset(self::TYPES[$type]) || !is_array($ids) || !array_is_list($ids)) {
            throw new LoreverseWorldError('EVENT_INPUT', '유형과 정렬 순서, 관련 인물을 확인해 주세요.');
        }
        $title = loreverseWorldText($input['title'] ?? '', 200, '사건 제목', true);
        $date = loreverseWorldText($input['date_label'] ?? '', 100, '사건 날짜', true);
        $description = loreverseWorldText($input['description'] ?? '', 5000, '사건 설명');
        $ids = array_values(array_unique(array_map('loreverseWorldId', $ids)));
        $this->pdo->beginTransaction();
        try {
            (new WorldService($this->pdo, $this->user))->ownedRow($world, true);
            if ($ids !== []) {
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                $lock = $this->pdo->prepare('SELECT id FROM characters WHERE world_id = ? AND user_id = ?
                    AND id IN (' . $placeholders . ') ORDER BY id FOR UPDATE');
                $lock->execute([$world, $this->user, ...$ids]);
                if (count($lock->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) {
                    throw new LoreverseWorldError('EVENT_CHARACTER', '이 세계관에 있는 인물을 선택해 주세요.');
                }
            }
            $args = [$title, $date, $order, $type, $description, json_encode($ids, JSON_THROW_ON_ERROR)];
            if ($id === null) {
                $query = $this->pdo->prepare('INSERT INTO timeline_events
                    (title, date_label, sort_order, event_type, description, character_ids, world_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)');
                $query->execute([...$args, $world]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $previous = $this->get($id);
                if ((int) $previous['world_id'] !== $world) {
                    throw new LoreverseWorldError('EVENT_NOT_FOUND', '타임라인 사건을 찾을 수 없어요.', 404);
                }
                if ($previous['source_turn_id'] !== null) {
                    $args[3] = 'story';
                }
                $query = $this->pdo->prepare('UPDATE timeline_events SET title = ?, date_label = ?, sort_order = ?,
                    event_type = ?, description = ?, character_ids = ? WHERE world_id = ? AND id = ?');
                $query->execute([...$args, $world, $id]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->get($id);
    }

    public function delete(array $input): array
    {
        if (!in_array($input['confirmed'] ?? false, [true, '1'], true)) {
            throw new LoreverseWorldError('CONFIRMATION_REQUIRED', '타임라인 사건 삭제를 확인해 주세요.');
        }
        $id = loreverseWorldId($input['id'] ?? null);
        $event = $this->get($id);
        if (isset($input['world_id']) && (int) $event['world_id'] !== loreverseWorldId($input['world_id'])) {
            throw new LoreverseWorldError('EVENT_NOT_FOUND', '타임라인 사건을 찾을 수 없어요.', 404);
        }
        $query = $this->pdo->prepare('DELETE FROM timeline_events WHERE id = ? AND world_id = ?
            AND EXISTS (SELECT 1 FROM worlds WHERE id = ? AND user_id = ?)');
        $query->execute([$id, $event['world_id'], $event['world_id'], $this->user]);
        if ($query->rowCount() !== 1) {
            throw new LoreverseWorldError('EVENT_NOT_FOUND', '타임라인 사건을 찾을 수 없어요.', 404);
        }
        return ['deleted' => true];
    }
}
