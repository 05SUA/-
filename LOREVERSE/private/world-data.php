<?php

declare(strict_types=1);

const LOREVERSE_GENRES = ['fantasy', 'cyberpunk', 'modern', 'apocalypse', 'dark_fantasy', 'historical', 'custom'];
const WORLD_TEXT_LIMITS = [
    'name' => 100, 'era' => 100, 'mood' => 100, 'description' => 2000,
    'history' => 5000, 'features' => 2000, 'custom_genre' => 100,
];
const WORLD_LIST_FIELDS = ['keywords', 'regions', 'factions', 'rules', 'conflicts'];
const WORLD_AI_FIELDS = ['name', 'era', 'mood', 'description', 'history', 'regions', 'factions', 'rules', 'conflicts'];

final class LoreverseWorldError extends RuntimeException
{
    public function __construct(public string $reason, string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

function loreverseWorldInvalid(string $field): void
{
    $labels = [
        'genre' => '장르', 'custom_genre' => '직접 입력 장르', 'name' => '세계관 이름',
        'era' => '시대', 'mood' => '분위기', 'description' => '소개', 'history' => '역사',
        'features' => '특징', 'faction_hints' => '주요 세력 힌트', 'keywords' => '키워드',
        'regions' => '주요 지역', 'factions' => '주요 세력', 'rules' => '세계 규칙', 'conflicts' => '핵심 갈등',
        'locked_fields' => '고정 항목', 'current_data' => '현재 초안', 'target_field' => '재생성 대상',
        'image' => '이미지', 'request_id' => '요청 번호',
    ];
    $label = $labels[$field] ?? $field;
    throw new LoreverseWorldError('VALIDATION_ERROR', $label . ' 입력을 확인해 주세요.');
}

function loreverseWorldText(mixed $value, int $limit, string $field, bool $required = false): string
{
    if (!is_string($value) || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8')) {
        loreverseWorldInvalid($field);
    }
    $value = trim($value);
    if (mb_strlen($value, 'UTF-8') > $limit || ($required && $value === '')) {
        loreverseWorldInvalid($field);
    }
    return $value;
}

function loreverseWorldList(mixed $value, string $field): array
{
    if (!is_array($value) || count($value) > 10 || array_values($value) !== $value) {
        loreverseWorldInvalid($field);
    }
    $items = [];
    foreach ($value as $item) {
        if (in_array($field, ['regions', 'factions'], true)) {
            if ($item instanceof stdClass) {
                $item = get_object_vars($item);
            }
            if (!is_array($item)) {
                loreverseWorldInvalid($field);
            }
            $items[] = [
                'name' => loreverseWorldText($item['name'] ?? null, 100, $field, true),
                'description' => loreverseWorldText($item['description'] ?? null, 500, $field),
            ];
        } else {
            $items[] = loreverseWorldText($item, $field === 'keywords' ? 80 : 500, $field, true);
        }
    }
    return $items;
}

function loreverseWorldData(array $input, bool $saving = false): array
{
    $genre = $input['genre'] ?? '';
    if (!in_array($genre, LOREVERSE_GENRES, true)) {
        loreverseWorldInvalid('genre');
    }
    $data = ['genre' => $genre];
    foreach (WORLD_TEXT_LIMITS as $field => $limit) {
        if ($field === 'custom_genre' && $genre !== 'custom') {
            $data[$field] = '';
            continue;
        }
        $required = $field === 'custom_genre' || ($saving && $field === 'name');
        $data[$field] = loreverseWorldText($input[$field] ?? '', $limit, $field, $required);
    }
    foreach (WORLD_LIST_FIELDS as $field) {
        $data[$field] = loreverseWorldList($input[$field] ?? [], $field);
    }
    return $data;
}

function loreverseWorldId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ($id === false) {
        throw new LoreverseWorldError('WORLD_NOT_FOUND', '세계관을 찾을 수 없어요.', 404);
    }
    return $id;
}
