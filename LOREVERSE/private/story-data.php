<?php

declare(strict_types=1);

require_once __DIR__ . '/world-data.php';

function loreverseStoryInt(mixed $value, int $min, int $max, string $label): int
{
    $parsed = is_int($value) || is_string($value)
        ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]])
        : false;
    if ($parsed === false) {
        loreverseWorldInvalid($label);
    }
    return $parsed;
}

function loreverseStoryId(mixed $value): int
{
    return loreverseStoryInt($value, 1, 4294967295, '스토리 또는 장면 번호');
}

function loreverseStoryIntegerList(mixed $value, int $min, int $max, int $count, string $label): array
{
    if (!is_array($value) || array_values($value) !== $value || count($value) > $count) {
        loreverseWorldInvalid($label);
    }
    $items = array_map(static fn ($item): int => loreverseStoryInt($item, $min, $max, $label), $value);
    if (count(array_unique($items)) !== count($items)) {
        loreverseWorldInvalid($label);
    }
    return $items;
}

function loreverseStoryJson(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

function loreverseStoryDecode(string $value): mixed
{
    return json_decode($value, true, 32, JSON_THROW_ON_ERROR);
}

function loreverseStoryTurn(array $row): array
{
    foreach (['dialogues', 'choices', 'relationship_changes', 'relationship_base', 'timeline_event', 'canon_selection'] as $field) {
        $row[$field] = loreverseStoryDecode($row[$field]);
    }
    $row['id'] = (int) $row['id'];
    $row['turn_no'] = (int) $row['turn_no'];
    $row['source'] = $row['choices'] === [] ? 'manual' : 'gemini';
    return $row;
}

function loreverseStoryPublicTurn(array $turn): array
{
    unset($turn['story_id'], $turn['request_id'], $turn['relationship_base']);
    return $turn;
}

function loreverseStoryModelScene(stdClass $result, array $cast, array $relationships): array
{
    $castNames = array_column($cast, 'name', 'id');
    $relationshipBases = [];
    foreach ($relationships as $relationship) {
        $relationshipBases[$relationship['from_character_id'] . ':' . $relationship['to_character_id']] = $relationship;
    }
    $data = get_object_vars($result);
    $list = static function (mixed $value, int $maximum, string $label): array {
        if (!is_array($value) || array_values($value) !== $value || count($value) > $maximum) {
            loreverseWorldInvalid($label);
        }
        return $value;
    };
    $actor = static function (mixed $id) use ($castNames): int {
        if (!is_int($id) || !isset($castNames[$id])) {
            loreverseWorldInvalid('등장인물');
        }
        return $id;
    };
    try {
        $title = loreverseWorldText($data['scene_title'] ?? null, 200, '장면 제목', true);
        $narration = loreverseWorldText($data['narration'] ?? null, 5000, '장면 서술', true);
        $choices = $list($data['choices'] ?? null, 3, '선택지');
        if (count($choices) !== 3) {
            loreverseWorldInvalid('선택지');
        }
        $choices = array_map(static fn ($item): string => loreverseWorldText($item, 500, '선택지', true), $choices);
        if (count(array_unique($choices)) !== 3) {
            loreverseWorldInvalid('선택지');
        }
        $dialogues = [];
        foreach ($list($data['dialogues'] ?? null, 10, '대사') as $entry) {
            $id = $actor($entry->character_id ?? null);
            $speech = loreverseWorldText($entry->speech ?? null, 500, '대사');
            $action = loreverseWorldText($entry->action ?? null, 500, '행동');
            if ($speech === '' && $action === '') {
                loreverseWorldInvalid('대사 또는 행동');
            }
            $dialogues[] = ['character_id' => $id, 'character_name' => $castNames[$id], 'speech' => $speech, 'action' => $action];
        }
        $changes = [];
        $bases = [];
        foreach ($list($data['relationship_changes'] ?? null, 10, '관계 변화 제안') as $entry) {
            $from = $actor($entry->from_character_id ?? null);
            $to = $actor($entry->to_character_id ?? null);
            $direction = $from . ':' . $to;
            if ($from === $to || !isset($relationshipBases[$direction])) {
                loreverseWorldInvalid('기존 관계 방향');
            }
            foreach (['intimacy_delta', 'trust_delta'] as $field) {
                if (!is_int($entry->{$field} ?? null) || abs($entry->{$field}) > 20) {
                    loreverseWorldInvalid('관계 변화 수치');
                }
            }
            $changes[] = [
                'from_character_id' => $from, 'to_character_id' => $to,
                'from_character_name' => $castNames[$from], 'to_character_name' => $castNames[$to],
                'intimacy_delta' => $entry->intimacy_delta, 'trust_delta' => $entry->trust_delta,
                'reason' => loreverseWorldText($entry->reason ?? null, 500, '관계 변화 이유', true),
            ];
            $bases[] = $relationshipBases[$direction];
            unset($relationshipBases[$direction]);
        }
        if (!array_key_exists('timeline_event', $data)) {
            loreverseWorldInvalid('타임라인 사건 제안');
        }
        $event = null;
        if ($data['timeline_event'] !== null) {
            $suggestion = $data['timeline_event'];
            $ids = array_map($actor, $list($suggestion->character_ids ?? null, 5, '사건 등장인물'));
            if (count(array_unique($ids)) !== count($ids)) {
                loreverseWorldInvalid('사건 등장인물');
            }
            $event = [
                'title' => loreverseWorldText($suggestion->title ?? null, 200, '사건 제목', true),
                'date_label' => loreverseWorldText($suggestion->date_label ?? null, 100, '사건 날짜', true),
                'description' => loreverseWorldText($suggestion->description ?? null, 2000, '사건 설명', true),
                'character_ids' => $ids,
                'character_names' => array_intersect_key($castNames, array_flip($ids)),
            ];
        }
    } catch (LoreverseWorldError) {
        throw new GeminiException('GEMINI_SCHEMA_MISMATCH');
    }
    return [
        'scene_title' => $title, 'narration' => $narration, 'dialogues' => $dialogues, 'choices' => $choices,
        'relationship_changes' => $changes, 'relationship_base' => $bases, 'timeline_event' => $event,
    ];
}
