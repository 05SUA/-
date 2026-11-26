<?php

declare(strict_types=1);

require_once __DIR__ . '/world-data.php';

const CHARACTER_TEXT_LIMITS = [
    'name' => 100, 'age' => 50, 'gender' => 50, 'species' => 50, 'occupation' => 100,
    'affiliation' => 100, 'background' => 5000, 'goal' => 500, 'secret' => 500,
    'speech_style' => 500, 'voice_style' => 500,
];
const CHARACTER_LIST_FIELDS = ['personality', 'values', 'strengths', 'weaknesses', 'habits', 'abilities', 'limitations'];
const CHARACTER_APPEARANCE_FIELDS = ['hair', 'eyes', 'body', 'outfit', 'features'];
const CHARACTER_LABELS = [
    'name' => '이름', 'age' => '나이', 'gender' => '성별', 'species' => '종족', 'occupation' => '직업',
    'affiliation' => '소속', 'appearance' => '외형', 'hair' => '머리', 'eyes' => '눈', 'body' => '체형',
    'outfit' => '의상', 'features' => '외형 특징', 'personality' => '성격', 'values' => '가치관',
    'strengths' => '장점', 'weaknesses' => '단점', 'background' => '과거', 'goal' => '목표', 'secret' => '비밀',
    'speech_style' => '말투', 'voice_style' => '목소리 특징', 'habits' => '습관', 'abilities' => '능력',
    'limitations' => '약점', 'keywords' => '키워드', 'world_id' => '세계관', 'mode' => '생성 방식',
    'fields' => '캐릭터 설정', 'locked_fields' => '고정 항목', 'current_data' => '현재 초안', 'target_field' => '재생성 항목',
];

function loreverseCharacterInvalid(string $field): void
{
    throw new LoreverseWorldError('VALIDATION_ERROR', (CHARACTER_LABELS[$field] ?? '캐릭터') . ' 입력을 확인해 주세요.');
}

function loreverseCharacterId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ($id === false) {
        throw new LoreverseWorldError('CHARACTER_NOT_FOUND', '캐릭터를 찾을 수 없어요.', 404);
    }
    return $id;
}

function loreverseCharacterText(mixed $value, string $field, int $limit, bool $required = false): string
{
    if (!is_string($value) || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8')) {
        loreverseCharacterInvalid($field);
    }
    $value = trim($value);
    if (mb_strlen($value, 'UTF-8') > $limit || ($required && $value === '')) {
        loreverseCharacterInvalid($field);
    }
    return $value;
}

function loreverseCharacterList(mixed $value, string $field, int $limit = 500): array
{
    if (!is_array($value) || array_values($value) !== $value || count($value) > 10) {
        loreverseCharacterInvalid($field);
    }
    return array_map(static fn ($item): string => loreverseCharacterText($item, $field, $limit, true), $value);
}

function loreverseCharacterAppearance(mixed $value, bool $complete = false): array
{
    if ($value instanceof stdClass) {
        $value = get_object_vars($value);
    }
    if (!is_array($value)) {
        loreverseCharacterInvalid('appearance');
    }
    $appearance = [];
    foreach (CHARACTER_APPEARANCE_FIELDS as $field) {
        $appearance[$field] = loreverseCharacterText($complete ? ($value[$field] ?? null) : ($value[$field] ?? ''), $field, 500);
    }
    return $appearance;
}

function loreverseCharacterData(array $input, bool $saving = false): array
{
    $world = $input['world_id'] ?? null;
    $data = ['world_id' => $world === null || $world === '' ? null : loreverseWorldId($world)];
    foreach (CHARACTER_TEXT_LIMITS as $field => $limit) {
        $data[$field] = loreverseCharacterText($input[$field] ?? '', $field, $limit, $saving && $field === 'name');
    }
    $data['appearance'] = loreverseCharacterAppearance($input['appearance'] ?? []);
    foreach (CHARACTER_LIST_FIELDS as $field) {
        $data[$field] = loreverseCharacterList($input[$field] ?? [], $field);
    }
    return $data;
}
