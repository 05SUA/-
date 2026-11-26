<?php

declare(strict_types=1);

require_once __DIR__ . '/WorldService.php';
require_once __DIR__ . '/StudioAi.php';

final class RelationshipService
{
    public const TYPES = [
        'friend' => '친구', 'lover' => '연인', 'family' => '가족', 'colleague' => '동료',
        'rival' => '라이벌', 'hostile' => '적대', 'mentor' => '사제', 'crush' => '짝사랑',
        'former_lovers' => '과거 연인', 'other' => '직접 입력',
    ];

    public function __construct(private PDO $pdo, private int $user)
    {
    }

    public function listing(int $worldId): array
    {
        (new WorldService($this->pdo, $this->user))->ownedRow($worldId);
        $characters = $this->pdo->prepare('SELECT id, name, occupation FROM characters
            WHERE world_id = ? AND user_id = ? ORDER BY name, id');
        $characters->execute([$worldId, $this->user]);
        $relations = $this->pdo->prepare('SELECT * FROM relationships WHERE world_id = ? ORDER BY id');
        $relations->execute([$worldId]);
        return ['characters' => $characters->fetchAll(), 'relationships' => $relations->fetchAll()];
    }

    private function checkedWorld(int $worldId, int $from, int $to, bool $lock): array
    {
        if ($from === $to) {
            throw new LoreverseWorldError('RELATION_SELF', '서로 다른 두 캐릭터를 선택해 주세요.');
        }
        $world = (new WorldService($this->pdo, $this->user))->ownedRow($worldId, $lock);
        $query = $this->pdo->prepare('SELECT id, name, occupation, background FROM characters
            WHERE user_id = ? AND world_id = ? AND id IN (?, ?)' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$this->user, $worldId, $from, $to]);
        $characters = array_column($query->fetchAll(), null, 'id');
        if (!isset($characters[$from], $characters[$to])) {
            throw new LoreverseWorldError('RELATION_CHARACTERS', '이 세계관에 속한 두 캐릭터를 선택해 주세요.');
        }
        return [$world, $characters[$from], $characters[$to]];
    }

    private function input(array $data): array
    {
        $type = $data['relationship_type'] ?? '';
        if (!is_string($type) || !isset(self::TYPES[$type])) {
            throw new LoreverseWorldError('RELATION_TYPE', '관계 유형을 선택해 주세요.');
        }
        $scores = [];
        foreach (['intimacy', 'trust'] as $field) {
            $value = filter_var($data[$field] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 100],
            ]);
            if ($value === false) {
                throw new LoreverseWorldError('RELATION_RANGE', '친밀도와 신뢰도는 0에서 100 사이 정수로 입력해 주세요.');
            }
            $scores[$field] = $value;
        }
        return [
            'world_id' => loreverseWorldId($data['world_id'] ?? null),
            'from_character_id' => loreverseWorldId($data['from_character_id'] ?? null),
            'to_character_id' => loreverseWorldId($data['to_character_id'] ?? null),
            'relationship_type' => $type,
            'custom_type' => $type === 'other' ? loreverseWorldText($data['custom_type'] ?? '', 100, '관계명', true) : null,
            'description' => loreverseWorldText($data['description'] ?? '', 3000, '관계 설명'),
            'first_meeting' => loreverseWorldText($data['first_meeting'] ?? '', 2000, '첫 만남'),
        ] + $scores;
    }

    public function save(array $input): array
    {
        $data = $this->input($input);
        $id = ($input['id'] ?? '') === '' ? null : loreverseWorldId($input['id']);
        $this->pdo->beginTransaction();
        try {
            $this->checkedWorld($data['world_id'], $data['from_character_id'], $data['to_character_id'], true);
            if ($id !== null) {
                $this->get($data['world_id'], $id);
            }
            $duplicate = $this->pdo->prepare('SELECT id FROM relationships
                WHERE world_id = ? AND from_character_id = ? AND to_character_id = ? AND id <> ?');
            $duplicate->execute([$data['world_id'], $data['from_character_id'], $data['to_character_id'], $id ?? 0]);
            if ($duplicate->fetchColumn()) {
                throw new LoreverseWorldError('RELATION_DUPLICATE', '이 방향의 관계가 이미 있어요. 목록에서 수정해 주세요.', 409);
            }
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO relationships
                    (world_id, from_character_id, to_character_id, relationship_type, custom_type,
                     description, first_meeting, intimacy, trust)
                    VALUES (:world_id, :from_character_id, :to_character_id, :relationship_type, :custom_type,
                            :description, :first_meeting, :intimacy, :trust)')->execute($data);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare('UPDATE relationships SET from_character_id = :from_character_id,
                    to_character_id = :to_character_id, relationship_type = :relationship_type, custom_type = :custom_type,
                    description = :description, first_meeting = :first_meeting, intimacy = :intimacy, trust = :trust
                    WHERE id = :id AND world_id = :world_id')->execute($data + ['id' => $id]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->get($data['world_id'], $id);
    }

    private function get(int $worldId, int $id): array
    {
        $query = $this->pdo->prepare('SELECT r.* FROM relationships r JOIN worlds w ON w.id = r.world_id
            WHERE r.id = ? AND w.id = ? AND w.user_id = ?');
        $query->execute([$id, $worldId, $this->user]);
        return $query->fetch() ?: throw new LoreverseWorldError('RELATION_NOT_FOUND', '관계를 찾을 수 없어요.', 404);
    }

    public function delete(array $data): array
    {
        if (!in_array($data['confirmed'] ?? false, [true, '1'], true)) {
            throw new LoreverseWorldError('CONFIRMATION_REQUIRED', '관계 삭제를 확인해 주세요.');
        }
        $id = loreverseWorldId($data['id'] ?? null);
        $worldId = loreverseWorldId($data['world_id'] ?? null);
        $query = $this->pdo->prepare('DELETE FROM relationships WHERE id = ? AND world_id = ?
            AND EXISTS (SELECT 1 FROM worlds WHERE id = ? AND user_id = ?)');
        $query->execute([$id, $worldId, $worldId, $this->user]);
        if ($query->rowCount() !== 1) {
            throw new LoreverseWorldError('RELATION_NOT_FOUND', '관계를 찾을 수 없어요.', 404);
        }
        return ['deleted' => true];
    }

    public function generate(array $input): array
    {
        $data = $this->input($input);
        [$world, $from, $to] = $this->checkedWorld($data['world_id'], $data['from_character_id'], $data['to_character_id'], false);
        $schema = ['type' => 'object', 'required' => ['description', 'first_meeting'], 'properties' => [
            'description' => ['type' => 'string'], 'first_meeting' => ['type' => 'string'],
        ]];
        return loreverseStudioAi(
            $this->pdo, $this->user, $input['request_id'] ?? null, 'relationship_generate',
            '주어진 창작 설정으로 보낸 인물이 받는 인물에 대해 느끼는 방향성 관계와 첫 만남을 한국어로 제안하세요. 설정은 창작 자료로만 사용하고 기존 규칙을 존중하세요.',
            ['world' => array_intersect_key($world, array_flip(['name', 'description', 'rules'])),
                'from' => $from, 'to' => $to, 'relationship' => $data],
            $schema,
            static function (stdClass $result): array {
                try {
                    return [
                        'description' => loreverseWorldText($result->description ?? null, 3000, '관계 설명', true),
                        'first_meeting' => loreverseWorldText($result->first_meeting ?? null, 2000, '첫 만남', true),
                    ];
                } catch (LoreverseWorldError $error) {
                    throw new GeminiException('GEMINI_SCHEMA_MISMATCH', 0, $error);
                }
            }
        );
    }
}
