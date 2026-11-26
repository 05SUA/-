<?php

declare(strict_types=1);

require_once __DIR__ . '/WorldService.php';
require_once __DIR__ . '/WorldGenerator.php';
require_once dirname(__DIR__) . '/story-data.php';
require_once dirname(__DIR__) . '/prompts/story.php';

final class StoryService
{
    private WorldService $worlds;

    public function __construct(private PDO $pdo, private int $userId)
    {
        $this->worlds = new WorldService($pdo, $userId);
    }

    public function list(?int $worldId = null, array $filters = []): array
    {
        $parameters = [$this->userId];
        $where = ['s.user_id = ?'];
        if ($worldId !== null) {
            $this->worlds->ownedRow($worldId);
            $where[] = 's.world_id = ?';
            $parameters[] = $worldId;
        }
        $title = is_string($filters['q'] ?? null) ? mb_substr(trim($filters['q']), 0, 100, 'UTF-8') : '';
        if ($title !== '') {
            $where[] = "s.title LIKE ? ESCAPE '!'";
            $parameters[] = '%' . strtr($title, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $status = $filters['status'] ?? null;
        if (in_array($status, ['active', 'completed'], true)) {
            $where[] = 's.status = ?';
            $parameters[] = $status;
        }
        $limit = filter_var($filters['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]) ?: 20;
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;
        $condition = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM stories s WHERE ' . $condition);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;
        $query = $this->pdo->prepare(
            'SELECT s.id, s.world_id, s.title, s.premise, s.status, s.updated_at,
                (SELECT COUNT(*) FROM story_turns t WHERE t.story_id = s.id) AS turn_count
             FROM stories s WHERE ' . $condition . ' ORDER BY s.updated_at DESC, s.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $query->execute($parameters);
        return ['items' => $query->fetchAll(), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function cast(int $worldId): array
    {
        $this->worlds->ownedRow($worldId);
        $query = $this->pdo->prepare('SELECT id, name FROM characters WHERE user_id = ? AND world_id = ? ORDER BY name, id');
        $query->execute([$this->userId, $worldId]);
        return $query->fetchAll();
    }

    public function get(int $storyId, ?int $worldId = null): array
    {
        $story = $this->ownedRow($storyId);
        if ($worldId !== null && $story['world_id'] !== $worldId) {
            $this->missing();
        }
        $query = $this->pdo->prepare('SELECT * FROM story_turns WHERE story_id = ? ORDER BY turn_no');
        $query->execute([$storyId]);
        $turns = array_map(static fn (array $row): array => loreverseStoryPublicTurn(loreverseStoryTurn($row)), $query->fetchAll());
        $currentNames = array_column($this->selectedCast($story['world_id'], $story['character_ids']), 'name', 'id');
        $snapshotNames = [];
        foreach ($turns as $turn) {
            foreach ($turn['dialogues'] as $dialogue) {
                if (isset($dialogue['character_name'])) {
                    $snapshotNames[$dialogue['character_id']] = $dialogue['character_name'];
                }
            }
            foreach ($turn['relationship_changes'] as $change) {
                if (isset($change['from_character_name'], $change['to_character_name'])) {
                    $snapshotNames[$change['from_character_id']] = $change['from_character_name'];
                    $snapshotNames[$change['to_character_id']] = $change['to_character_name'];
                }
            }
            $snapshotNames = array_replace($snapshotNames, $turn['timeline_event']['character_names'] ?? []);
        }
        $cast = [];
        foreach ($story['character_ids'] as $id) {
            $cast[] = ['id' => $id, 'name' => $currentNames[$id] ?? $snapshotNames[$id] ?? '삭제된 캐릭터',
                'inactive' => !isset($currentNames[$id])];
        }
        unset($story['user_id'], $story['context_summary']);
        return $story + ['cast' => $cast, 'turns' => $turns, 'latest_turn' => $turns[array_key_last($turns)]['turn_no'] ?? 0];
    }

    public function create(array $body): array
    {
        $worldId = loreverseWorldId($body['world_id'] ?? null);
        $title = loreverseWorldText($body['title'] ?? null, 100, '스토리 제목', true);
        $premise = loreverseWorldText($body['premise'] ?? null, 2000, '시작 사건', true);
        $location = loreverseWorldText($body['location'] ?? '', 200, '장소');
        $mood = loreverseWorldText($body['mood'] ?? '', 100, '분위기');
        $role = loreverseWorldText($body['user_role'] ?? '', 500, '사용자 역할');
        $ids = loreverseStoryIntegerList($body['character_ids'] ?? null, 1, 4294967295, 5, '등장인물');
        if ($ids === []) {
            loreverseWorldInvalid('등장인물 한 명 이상');
        }
        return $this->transaction(function () use ($worldId, $title, $premise, $location, $mood, $role, $ids): array {
            $this->worlds->ownedRow($worldId, true);
            $this->requireCast($worldId, $ids);
            $insert = $this->pdo->prepare(
                "INSERT INTO stories (user_id, world_id, title, premise, location, mood, user_role, character_ids, context_summary, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', 'active')"
            );
            $insert->execute([$this->userId, $worldId, $title, $premise, $location, $mood, $role, loreverseStoryJson($ids)]);
            return ['id' => (int) $this->pdo->lastInsertId(), 'world_id' => $worldId, 'title' => $title, 'status' => 'active'];
        });
    }

    public function advance(array $body, array $config): array
    {
        $id = loreverseStoryId($body['story_id'] ?? null);
        $expected = loreverseStoryInt($body['expected_turn'] ?? null, 0, 4294967294, '마지막 장면 번호');
        $requestId = loreverseWorldAiRequestId($body['request_id'] ?? null);
        $type = $body['action_type'] ?? null;
        if (!in_array($type, ['start', 'choice', 'free', 'manual'], true)) {
            loreverseWorldInvalid('장면 진행 방식');
        }
        $story = $this->ownedRow($id);
        $latest = $this->latest($id);
        $latestNumber = $latest['turn_no'] ?? 0;
        $stored = $this->turnByRequest($id, $requestId);
        if ($stored !== null) {
            return ['turn' => loreverseStoryPublicTurn($stored), 'latest_turn' => max($latestNumber, $stored['turn_no'])];
        }
        $this->requireActiveTurn($story, $latestNumber, $expected);
        $this->requireCast($story['world_id'], $story['character_ids']);
        $action = '';
        if ($type === 'manual') {
            $scene = [
                'scene_title' => loreverseWorldText($body['scene_title'] ?? null, 200, '장면 제목', true),
                'narration' => loreverseWorldText($body['narration'] ?? null, 5000, '장면 서술', true),
                'dialogues' => [], 'choices' => [], 'relationship_changes' => [], 'relationship_base' => [], 'timeline_event' => null,
            ];
        } else {
            if (($type === 'start') !== ($expected === 0)) {
                loreverseWorldInvalid('장면 진행 방식');
            }
            if ($type === 'choice') {
                $index = loreverseStoryInt($body['choice_index'] ?? null, 0, 2, '선택지 번호');
                $action = $latest['choices'][$index] ?? '';
                if ($action === '') {
                    loreverseWorldInvalid('선택지');
                }
            } elseif ($type === 'free') {
                $action = loreverseWorldText($body['action_text'] ?? null, 1000, '직접 행동', true);
            }
            $scene = $this->generate($story, $expected, $type, $action, $requestId, $config);
        }
        return $this->storeTurn($story, $expected, $requestId, $action, $scene);
    }

    public function complete(array $body): array
    {
        $id = loreverseStoryId($body['id'] ?? null);
        $status = $body['status'] ?? 'completed';
        if (!in_array($status, ['active', 'completed'], true)) {
            loreverseWorldInvalid('스토리 상태');
        }
        $story = $this->ownedRow($id);
        return $this->transaction(function () use ($story, $id, $status): array {
            $this->worlds->ownedRow($story['world_id'], true);
            $locked = $this->ownedRow($id, true);
            if ($status === 'active') {
                $this->requireCast($locked['world_id'], $locked['character_ids']);
            }
            if ($status !== $locked['status']) {
                $update = $this->pdo->prepare('UPDATE stories SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
                $update->execute([$status, $id]);
            }
            return ['id' => $id, 'status' => $status];
        });
    }

    public function delete(array $body): array
    {
        $id = loreverseStoryId($body['id'] ?? null);
        if (($body['confirmed'] ?? false) !== true) {
            throw new LoreverseWorldError('CONFIRMATION_REQUIRED', '삭제할 스토리를 확인해 주세요.');
        }
        $story = $this->ownedRow($id);
        return $this->transaction(function () use ($id, $story): array {
            $this->worlds->ownedRow($story['world_id'], true);
            $this->ownedRow($id, true);
            $delete = $this->pdo->prepare('DELETE FROM stories WHERE id = ? AND user_id = ?');
            $delete->execute([$id, $this->userId]);
            return ['deleted' => true];
        });
    }

    public function applyCanon(array $body): array
    {
        $storyId = loreverseStoryId($body['story_id'] ?? null);
        $turnId = loreverseStoryId($body['turn_id'] ?? null);
        $indices = loreverseStoryIntegerList($body['selected_relationship_indices'] ?? [], 0, 9, 10, '관계 변화 선택');
        $includeEvent = $body['include_event'] ?? false;
        if (!is_bool($includeEvent) || ($indices === [] && !$includeEvent)) {
            loreverseWorldInvalid('공식 설정으로 반영할 항목');
        }
        $order = isset($body['sort_order']) ? loreverseStoryInt($body['sort_order'], PHP_INT_MIN, PHP_INT_MAX, '사건 정렬값') : null;
        $worldId = $this->ownedRow($storyId)['world_id'];
        return $this->transaction(function () use ($storyId, $turnId, $worldId, $indices, $includeEvent, $order): array {
            $this->worlds->ownedRow($worldId, true);
            $story = $this->ownedRow($storyId, true);
            $query = $this->pdo->prepare('SELECT * FROM story_turns WHERE id = ? AND story_id = ? FOR UPDATE');
            $query->execute([$turnId, $storyId]);
            $row = $query->fetch();
            if (!$row) {
                $this->missing();
            }
            $turn = loreverseStoryTurn($row);
            if ($turn['canon_applied_at'] !== null) {
                throw new LoreverseWorldError('CANON_ALREADY_APPLIED', '이미 공식 설정 검토를 마친 장면이에요.', 409);
            }
            foreach ($indices as $index) {
                $change = $turn['relationship_changes'][$index] ?? null;
                $base = $turn['relationship_base'][$index] ?? null;
                if ($change === null || $base === null) {
                    loreverseWorldInvalid('관계 변화 선택');
                }
                $this->applyRelationship($worldId, $story['character_ids'], $change, $base);
            }
            $eventId = null;
            if ($includeEvent) {
                if ($turn['timeline_event'] === null) {
                    loreverseWorldInvalid('타임라인 사건 제안');
                }
                $eventId = $this->applyEvent($worldId, $turnId, $story['character_ids'], $turn['timeline_event'], $order);
            }
            $selection = ['selected_relationship_indices' => $indices, 'include_event' => $includeEvent, 'timeline_event_id' => $eventId];
            $update = $this->pdo->prepare(
                'UPDATE story_turns SET canon_applied_at = UTC_TIMESTAMP(), canon_selection = ? WHERE id = ?'
            );
            $update->execute([loreverseStoryJson($selection), $turnId]);
            return ['applied' => true, 'canon_selection' => $selection];
        });
    }

    private function ownedRow(int $storyId, bool $lock = false): array
    {
        $query = $this->pdo->prepare('SELECT * FROM stories WHERE id = ? AND user_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$storyId, $this->userId]);
        $row = $query->fetch();
        if (!$row) {
            $this->missing();
        }
        $row['id'] = (int) $row['id'];
        $row['world_id'] = (int) $row['world_id'];
        $row['character_ids'] = loreverseStoryDecode($row['character_ids']);
        return $row;
    }

    private function selectedCast(int $worldId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $parameters = implode(', ', array_fill(0, count($ids), '?'));
        $query = $this->pdo->prepare(
            'SELECT id, name, species, occupation, affiliation, speech_style, LEFT(background, 800) AS background,
                LEFT(goal, 400) AS goal FROM characters WHERE user_id = ? AND world_id = ? AND id IN (' . $parameters . ') ORDER BY id'
        );
        $query->execute([$this->userId, $worldId, ...$ids]);
        return $query->fetchAll();
    }

    private function requireCast(int $worldId, array $ids): array
    {
        $cast = $this->selectedCast($worldId, $ids);
        if (count($cast) !== count($ids)) {
            throw new LoreverseWorldError('STORY_CAST_CHANGED', '이 세계관에서 사용할 수 없는 등장인물이 있어요.', 409);
        }
        return $cast;
    }

    private function latest(int $storyId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM story_turns WHERE story_id = ? ORDER BY turn_no DESC LIMIT 1');
        $query->execute([$storyId]);
        $row = $query->fetch();
        return $row ? loreverseStoryTurn($row) : null;
    }

    private function turnByRequest(int $storyId, string $requestId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM story_turns WHERE story_id = ? AND request_id = ?');
        $query->execute([$storyId, $requestId]);
        $row = $query->fetch();
        return $row ? loreverseStoryTurn($row) : null;
    }

    private function requireActiveTurn(array $story, int $latest, int $expected): void
    {
        if ($story['status'] !== 'active') {
            throw new LoreverseWorldError('STORY_COMPLETED', '종료한 스토리는 다시 연 뒤 진행할 수 있어요.', 409);
        }
        if ($latest !== $expected) {
            throw new LoreverseWorldError('STORY_TURN_CHANGED', '다른 창에서 장면이 바뀌었어요. 새로고침한 뒤 이어서 진행해 주세요.', 409);
        }
    }

    private function generate(array $story, int $expected, string $type, string $action, string $requestId, array $config): array
    {
        $world = $this->worlds->get($story['world_id']);
        $cast = $this->requireCast($story['world_id'], $story['character_ids']);
        $relations = $this->relationships($story['world_id'], $story['character_ids']);
        $query = $this->pdo->prepare(
            'SELECT turn_no, scene_title, LEFT(narration, 1200) AS narration, user_action, dialogues
               FROM story_turns WHERE story_id = ? AND turn_no <= ? ORDER BY turn_no DESC LIMIT 5'
        );
        $query->execute([$story['id'], $expected]);
        $recent = array_reverse($query->fetchAll());
        foreach ($recent as &$turn) {
            $turn['dialogues'] = loreverseStoryDecode($turn['dialogues']);
        }
        unset($turn);
        $query = $this->pdo->prepare(
            'SELECT title, date_label, LEFT(description, 600) AS description FROM timeline_events
             WHERE world_id = ? ORDER BY sort_order DESC, id DESC LIMIT 5'
        );
        $query->execute([$story['world_id']]);
        $input = [
            'world' => array_intersect_key($world, array_flip(['name', 'genre', 'era', 'description', 'rules', 'regions', 'conflicts'])),
            'setup' => array_intersect_key($story, array_flip(['title', 'premise', 'location', 'mood', 'user_role', 'context_summary'])),
            'characters' => $cast, 'relationships' => $relations, 'events' => $query->fetchAll(), 'recent_turns' => $recent,
            'action_type' => $type, 'user_action' => $action,
        ];
        $gemini = new GeminiService();
        try {
            $gemini->validateConfig($config);
        } catch (GeminiException $error) {
            throw loreverseWorldGeminiError($error);
        }
        $limit = filter_var($config['AI_DAILY_LIMIT'] ?? 30, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000]]) ?: 30;
        loreverseReserveAi($this->pdo, $this->userId, $requestId, 'story_advance', $config['GEMINI_MODEL'], $limit);
        $start = microtime(true);
        $failure = null;
        $usage = null;
        try {
            $raw = $gemini->generateStructured($config, loreverseStoryPrompt(), $input, loreverseStorySchema(), 3500, $usage);
            return loreverseStoryModelScene($raw, $cast, $relations);
        } catch (GeminiException $error) {
            $failure = $error->getMessage();
            throw loreverseWorldGeminiError($error);
        } catch (Throwable $error) {
            $failure = 'INTERNAL_ERROR';
            throw $error;
        } finally {
            loreverseFinishAi($this->pdo, $this->userId, $requestId, $failure, $usage, (int) round((microtime(true) - $start) * 1000));
        }
    }

    private function relationships(int $worldId, array $castIds): array
    {
        if (count($castIds) < 2) {
            return [];
        }
        $parameters = implode(', ', array_fill(0, count($castIds), '?'));
        $query = $this->pdo->prepare(
            'SELECT id, from_character_id, to_character_id, relationship_type, intimacy, trust, updated_at,
                LEFT(description, 400) AS description FROM relationships WHERE world_id = ? AND from_character_id IN (' . $parameters
            . ') AND to_character_id IN (' . $parameters . ') ORDER BY id'
        );
        $query->execute([$worldId, ...$castIds, ...$castIds]);
        return $query->fetchAll();
    }

    private function storeTurn(array $story, int $expected, string $requestId, string $action, array $scene): array
    {
        return $this->transaction(function () use ($story, $expected, $requestId, $action, $scene): array {
            $this->worlds->ownedRow($story['world_id'], true);
            $locked = $this->ownedRow($story['id'], true);
            $latest = $this->latest($story['id'])['turn_no'] ?? 0;
            $stored = $this->turnByRequest($story['id'], $requestId);
            if ($stored !== null) {
                return ['turn' => loreverseStoryPublicTurn($stored), 'latest_turn' => $latest];
            }
            $this->requireActiveTurn($locked, $latest, $expected);
            $this->requireCast($locked['world_id'], $locked['character_ids']);
            $number = $latest + 1;
            $insert = $this->pdo->prepare(
                'INSERT INTO story_turns (story_id, turn_no, request_id, user_action, scene_title, narration,
                    dialogues, choices, relationship_changes, relationship_base, timeline_event, canon_selection)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $story['id'], $number, $requestId, $action, $scene['scene_title'], $scene['narration'],
                loreverseStoryJson($scene['dialogues']), loreverseStoryJson($scene['choices']), loreverseStoryJson($scene['relationship_changes']),
                loreverseStoryJson($scene['relationship_base']), loreverseStoryJson($scene['timeline_event']), '[]',
            ]);
            $summary = (string) $locked['context_summary'];
            if ($number > 5) {
                $past = $this->pdo->prepare('SELECT scene_title, narration FROM story_turns WHERE story_id = ? AND turn_no = ?');
                $past->execute([$story['id'], $number - 5]);
                $entry = $past->fetch();
                if ($entry) {
                    $summary = trim($summary . "\n" . $entry['scene_title'] . ' — ' . mb_substr($entry['narration'], 0, 200, 'UTF-8'));
                    $summary = mb_substr($summary, max(0, mb_strlen($summary, 'UTF-8') - 1800), null, 'UTF-8');
                }
            }
            $update = $this->pdo->prepare('UPDATE stories SET context_summary = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $update->execute([$summary, $story['id']]);
            return ['turn' => loreverseStoryPublicTurn($this->turnByRequest($story['id'], $requestId)), 'latest_turn' => $number];
        });
    }

    private function applyRelationship(int $worldId, array $castIds, array $change, array $base): void
    {
        $from = $change['from_character_id'];
        $to = $change['to_character_id'];
        if (!in_array($from, $castIds, true) || !in_array($to, $castIds, true)) {
            loreverseWorldInvalid('관계 등장인물');
        }
        $query = $this->pdo->prepare(
            'SELECT id, intimacy, trust, updated_at FROM relationships WHERE world_id = ?
             AND from_character_id = ? AND to_character_id = ? FOR UPDATE'
        );
        $query->execute([$worldId, $from, $to]);
        $current = $query->fetch();
        foreach (['id', 'intimacy', 'trust', 'updated_at'] as $key) {
            if (!$current || (string) $current[$key] !== (string) $base[$key]) {
                throw new LoreverseWorldError('STORY_CANON_CONFLICT', '관계가 장면 작성 이후 바뀌었어요. 현재 관계를 확인하고 이 제안을 제외하거나 새 장면을 진행해 주세요.', 409);
            }
        }
        $update = $this->pdo->prepare('UPDATE relationships SET intimacy = ?, trust = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $update->execute([
            max(0, min(100, $current['intimacy'] + $change['intimacy_delta'])),
            max(0, min(100, $current['trust'] + $change['trust_delta'])), $current['id'],
        ]);
    }

    private function applyEvent(int $worldId, int $turnId, array $castIds, array $event, ?int $order): int
    {
        if (array_diff($event['character_ids'], $castIds) !== []) {
            loreverseWorldInvalid('사건 등장인물');
        }
        $this->requireCast($worldId, $event['character_ids']);
        if ($order === null) {
            $last = $this->pdo->prepare('SELECT MAX(sort_order) FROM timeline_events WHERE world_id = ?');
            $last->execute([$worldId]);
            $number = (int) $last->fetchColumn();
            $order = $number === PHP_INT_MAX ? $number : $number + 1;
        }
        $insert = $this->pdo->prepare(
            "INSERT INTO timeline_events (world_id, source_turn_id, title, date_label, sort_order, event_type, description, character_ids)
             VALUES (?, ?, ?, ?, ?, 'story', ?, ?)"
        );
        $insert->execute([$worldId, $turnId, $event['title'], $event['date_label'], $order, $event['description'], loreverseStoryJson($event['character_ids'])]);
        return (int) $this->pdo->lastInsertId();
    }

    private function transaction(callable $operation): array
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function missing(): void
    {
        throw new LoreverseWorldError('STORY_NOT_FOUND', '스토리나 장면을 찾을 수 없어요.', 404);
    }
}
