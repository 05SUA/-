<?php

declare(strict_types=1);

require_once __DIR__ . '/CharacterService.php';
require_once __DIR__ . '/StudioAi.php';
require_once dirname(__DIR__) . '/prompts/chat.php';

function loreverseChatId(mixed $value): int
{
    if (!is_int($value) && !is_string($value)) {
        $value = null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ($id === false) {
        throw new LoreverseWorldError('CHAT_NOT_FOUND', '대화 세션을 찾을 수 없어요.', 404);
    }
    return $id;
}

final class ChatService
{
    private CharacterService $characters;
    private WorldService $worlds;

    public function __construct(private PDO $pdo, private int $userId)
    {
        $this->characters = new CharacterService($pdo, $userId);
        $this->worlds = new WorldService($pdo, $userId);
    }

    public function sessions(array $filters = []): array
    {
        $condition = 's.user_id = ?';
        $parameters = [$this->userId];
        if (isset($filters['character_id'])) {
            $characterId = loreverseCharacterId($filters['character_id']);
            $this->characters->ownedRow($characterId);
            $condition .= ' AND s.character_id = ?';
            $parameters[] = $characterId;
        }
        if (isset($filters['world_id'])) {
            $worldId = loreverseWorldId($filters['world_id']);
            $this->worlds->ownedRow($worldId);
            $condition .= ' AND c.world_id = ?';
            $parameters[] = $worldId;
        }
        $from = ' FROM chat_sessions s JOIN characters c ON c.id = s.character_id AND c.user_id = s.user_id WHERE ' . $condition;
        [$pagination, $limit, $offset] = $this->paginate($from, $parameters, $filters);
        $query = $this->pdo->prepare(
            'SELECT s.id, s.character_id, s.title, s.mode, s.updated_at, c.name AS character_name, c.world_id'
            . $from . ' ORDER BY s.updated_at DESC, s.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $query->execute($parameters);
        return ['items' => array_map($this->sessionData(...), $query->fetchAll())] + $pagination;
    }

    public function messages(int $sessionId, array $filters = []): array
    {
        $session = $this->ownedSession($sessionId);
        $latest = $this->latestMessageId($sessionId);
        $from = ' FROM chat_messages WHERE session_id = ? AND id <= ?';
        [$pagination, $limit, $offset] = $this->paginate($from, [$sessionId, $latest], $filters);
        $query = $this->pdo->prepare(
            'SELECT id, role, content, action, created_at' . $from . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $query->execute([$sessionId, $latest]);
        return [
            'session' => $this->sessionData($session),
            'items' => array_map($this->messageData(...), array_reverse($query->fetchAll())),
            'latest_message_id' => $latest,
        ] + $pagination;
    }

    public function create(array $body): array
    {
        $characterId = loreverseCharacterId($body['character_id'] ?? null);
        $character = $this->characters->ownedRow($characterId);
        if (isset($body['world_id']) && (int) $character['world_id'] !== loreverseWorldId($body['world_id'])) {
            throw new LoreverseWorldError('CHARACTER_NOT_FOUND', '이 세계관의 캐릭터를 찾을 수 없어요.', 404);
        }
        $title = loreverseWorldText($body['title'] ?? null, 100, '대화 이름', true);
        $mode = $body['mode'] ?? null;
        if (!is_string($mode) || !isset(LOREVERSE_CHAT_MODES[$mode])) {
            loreverseWorldInvalid('대화 모드');
        }
        $role = loreverseWorldText($body['user_role'] ?? '', 500, '사용자 역할과 관계');
        $situation = loreverseWorldText($body['situation'] ?? '', 1000, '대화 상황');
        // Scope the insertion again so character deletion or a scope change cannot race the check above.
        $query = $this->pdo->prepare(
            "INSERT INTO chat_sessions (user_id, character_id, title, mode, user_role, situation, context_summary)
             SELECT user_id, id, ?, ?, ?, ?, '' FROM characters
             WHERE id = ? AND user_id = ? AND world_id <=> ?"
        );
        $query->execute([$title, $mode, $role, $situation, $characterId, $this->userId, $character['world_id']]);
        if ($query->rowCount() !== 1) {
            throw new LoreverseWorldError('CHARACTER_NOT_FOUND', '캐릭터를 찾을 수 없어요.', 404);
        }
        return $this->sessionData($this->ownedSession((int) $this->pdo->lastInsertId()));
    }

    public function rename(int $sessionId, mixed $value): array
    {
        $this->ownedSession($sessionId);
        $title = loreverseWorldText($value, 100, '대화 이름', true);
        $query = $this->pdo->prepare('UPDATE chat_sessions SET title = ? WHERE id = ? AND user_id = ?');
        $query->execute([$title, $sessionId, $this->userId]);
        return $this->sessionData($this->ownedSession($sessionId));
    }

    public function delete(int $sessionId, mixed $confirmed): void
    {
        $this->ownedSession($sessionId);
        if ($confirmed !== true && $confirmed !== '1') {
            throw new LoreverseWorldError('CONFIRMATION_REQUIRED', '삭제할 대화를 확인해 주세요.');
        }
        $query = $this->pdo->prepare('DELETE FROM chat_sessions WHERE id = ? AND user_id = ?');
        $query->execute([$sessionId, $this->userId]);
    }

    public function send(array $body): array
    {
        $sessionId = loreverseChatId($body['session_id'] ?? null);
        $session = $this->ownedSession($sessionId);
        $requestId = loreverseWorldAiRequestId($body['request_id'] ?? null);
        $message = loreverseWorldText($body['message'] ?? null, 2000, '보낼 메시지', true);
        $stored = $this->storedPair($sessionId, $requestId, $message);
        if ($stored !== null) {
            return $stored;
        }
        $latest = $this->latestMessageId($sessionId);
        if (array_key_exists('expected_last_id', $body)) {
            $value = $body['expected_last_id'];
            $expected = filter_var(is_int($value) || is_string($value) ? $value : null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 4294967295],
            ]);
            if ($expected === false || $expected !== $latest) {
                $this->changed();
            }
        }
        $input = $this->promptInput($session, $latest, $message);
        try {
            return loreverseStudioAi($this->pdo, $this->userId, $requestId, 'chat_send',
                loreverseChatPrompt(), $input, loreverseChatSchema(),
                fn (stdClass $result): array => $this->storePair($sessionId, $latest, $requestId, $message, loreverseChatResult($result))
            );
        } catch (LoreverseWorldError $error) {
            if ($error->reason !== 'DUPLICATE_REQUEST') {
                throw $error;
            }
            $stored = $this->storedPair($sessionId, $requestId, $message);
            if ($stored !== null) {
                return $stored;
            }
            $pending = $this->pdo->prepare("SELECT 1 FROM ai_usage_logs
                WHERE user_id = ? AND request_id = ? AND feature = 'chat_send' AND status = 'pending'");
            $pending->execute([$this->userId, $requestId]);
            if ($pending->fetchColumn()) {
                throw new LoreverseWorldError('AI_IN_PROGRESS', '캐릭터가 아직 답변을 작성하는 중이에요. 잠시 후 다시 시도해 주세요.', 409);
            }
            throw $error;
        }
    }

    private function storePair(int $sessionId, int $latest, string $requestId, string $message, array $reply): array
    {
        // Commit both messages while the shared AI lease is still held.
        $this->pdo->beginTransaction();
        try {
            $this->ownedSession($sessionId, true);
            $stored = $this->storedPair($sessionId, $requestId, $message);
            if ($stored !== null) {
                $this->pdo->commit();
                return $stored;
            }
            if ($this->latestMessageId($sessionId, true) !== $latest) {
                $this->changed();
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO chat_messages (session_id, role, content, action, request_id) VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([$sessionId, 'user', $message, '', $requestId]);
            $insert->execute([$sessionId, 'assistant', $reply['speech'], $reply['action'], $requestId]);
            $touch = $this->pdo->prepare('UPDATE chat_sessions SET updated_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?');
            $touch->execute([$sessionId, $this->userId]);
            $pair = $this->storedPair($sessionId, $requestId, $message);
            $this->pdo->commit();
            return $pair + ['warnings' => $reply['warnings']];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function ownedSession(int $id, bool $lock = false): array
    {
        $query = $this->pdo->prepare(
            'SELECT s.id, s.character_id, s.title, s.mode, s.user_role, s.situation, s.context_summary,
                    s.created_at, s.updated_at, c.name AS character_name, c.world_id
             FROM chat_sessions s JOIN characters c ON c.id = s.character_id AND c.user_id = s.user_id
             WHERE s.id = ? AND s.user_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute([$id, $this->userId]);
        $row = $query->fetch();
        if (!$row) {
            throw new LoreverseWorldError('CHAT_NOT_FOUND', '대화 세션을 찾을 수 없어요.', 404);
        }
        return $row;
    }

    private function latestMessageId(int $id, bool $lock = false): int
    {
        $query = $this->pdo->prepare('SELECT id FROM chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT 1'
            . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        return (int) $query->fetchColumn();
    }

    private function storedPair(int $sessionId, string $requestId, string $message): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT id, role, content, action, created_at FROM chat_messages WHERE session_id = ? AND request_id = ? ORDER BY id'
        );
        $query->execute([$sessionId, $requestId]);
        $pair = $query->fetchAll();
        if ($pair === []) {
            return null;
        }
        if (array_column($pair, 'role') !== ['user', 'assistant'] || $pair[0]['content'] !== $message) {
            throw new LoreverseWorldError('DUPLICATE_REQUEST', '이미 사용한 요청이에요. 새로 시도해 주세요.', 409);
        }
        return [
            'messages' => array_map($this->messageData(...), $pair),
            'latest_message_id' => (int) $pair[1]['id'],
        ];
    }

    private function promptInput(array $session, int $latest, string $message): array
    {
        $character = $this->characters->get((int) $session['character_id']);
        if (($session['world_id'] === null ? null : (int) $session['world_id']) !== $character['world_id']) {
            $this->changed();
        }
        $world = $character['world_id'] === null ? null : $this->worlds->get($character['world_id']);
        $profileFields = ['name', 'age', 'species', 'occupation', 'affiliation', 'personality', 'values', 'goal', 'background',
            'speech_style', 'voice_style', 'habits', 'abilities', 'limitations', 'secret'];
        $worldFields = ['name', 'genre', 'custom_genre', 'era', 'mood', 'description', 'regions', 'factions', 'rules', 'conflicts'];
        $query = $this->pdo->prepare(
            'SELECT role, content, action FROM chat_messages WHERE session_id = ? AND id <= ? ORDER BY id DESC LIMIT 20'
        );
        $query->execute([(int) $session['id'], $latest]);
        return [
            'character' => array_intersect_key($character, array_flip($profileFields)),
            'world' => $world === null ? null : array_intersect_key($world, array_flip($worldFields)),
            'mode' => LOREVERSE_CHAT_MODES[$session['mode']] ?? LOREVERSE_CHAT_MODES['casual'],
            'user_role' => $session['user_role'],
            'situation' => $session['situation'],
            'context_summary' => $session['context_summary'],
            'recent_messages' => array_reverse($query->fetchAll()),
            'message' => $message,
        ];
    }

    private function sessionData(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['character_id'] = (int) $row['character_id'];
        $row['world_id'] = $row['world_id'] === null ? null : (int) $row['world_id'];
        unset($row['context_summary']);
        return $row;
    }

    private function messageData(array $row): array
    {
        $row['id'] = (int) $row['id'];
        return $row;
    }

    private function paginate(string $from, array $parameters, array $filters): array
    {
        $query = $this->pdo->prepare('SELECT COUNT(*)' . $from);
        $query->execute($parameters);
        $total = (int) $query->fetchColumn();
        $limit = filter_var($filters['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]) ?: 20;
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        return [['page' => $page, 'pages' => $pages, 'total' => $total], $limit, ($page - 1) * $limit];
    }

    private function changed(): never
    {
        throw new LoreverseWorldError('CHAT_CHANGED', '다른 곳에서 대화가 바뀌었어요. 최근 대화를 다시 열어 확인해 주세요.', 409);
    }
}
