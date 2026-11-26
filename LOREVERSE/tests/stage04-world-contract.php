<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/private/services/WorldGenerator.php';

function checkWorldContract(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

// Local fixtures exercise validation and merge behavior only. They are not API responses.
$fullInput = loreverseWorldAiInput([
    'genre' => 'fantasy', 'name' => '고정된 이름', 'features' => '밤과 낮이 바뀌지 않는다',
    'keywords' => ['실험'],
]);
$result = (object) [
    'genre' => 'modern', 'custom_genre' => '', 'name' => '모델이 제안한 이름',
    'era' => '제국 시대', 'mood' => '고요한', 'description' => '테스트 소개', 'history' => '테스트 역사',
    'regions' => [(object) ['name' => '지역', 'description' => '설명']],
    'factions' => [], 'rules' => ['초기 규칙'], 'conflicts' => [], 'warnings' => [],
];
$draft = loreverseMergeWorldDraft($fullInput, $result)['world'];
checkWorldContract($draft['genre'] === 'fantasy' && $draft['name'] === '고정된 이름', 'genre and seeded name must survive');
checkWorldContract($draft['description'] === '테스트 소개' && $draft['keywords'] === ['실험'], 'merge only validated generated fields');

$partialInput = loreverseWorldAiInput([
    'genre' => 'fantasy', 'name' => '고정된 이름', 'features' => $fullInput['features'], 'keywords' => ['실험'],
    'current_data' => $draft, 'target_field' => 'history', 'locked_fields' => ['rules'],
]);
$partial = loreverseMergeWorldDraft($partialInput, (object) [
    'history' => '새 역사', 'description' => '변경하면 안 됨', 'warnings' => ['검토하세요.'],
]);
checkWorldContract($partial['world'] === array_replace($draft, ['history' => '새 역사']), 'partial regeneration changed other fields');
checkWorldContract($partial['warnings'] === ['검토하세요.'], 'warnings did not survive validation');

try {
    loreverseMergeWorldDraft($partialInput, (object) ['history' => [], 'warnings' => []]);
    throw new RuntimeException('invalid result was accepted');
} catch (GeminiException $error) {
    checkWorldContract($error->getMessage() === 'GEMINI_SCHEMA_MISMATCH', 'invalid output was not classified');
}

try {
    loreverseWorldAiInput(['genre' => 'fantasy', 'name' => '고정', 'current_data' => $draft, 'target_field' => 'name']);
    throw new RuntimeException('the API allowed a locked field to be regenerated');
} catch (LoreverseWorldError $error) {
    checkWorldContract($error->reason === 'FIELD_LOCKED', 'locked target error mismatch');
}

try {
    loreverseWorldData(['genre' => 'custom', 'name' => '입력만 있음'], true);
    throw new RuntimeException('missing custom genre accepted');
} catch (LoreverseWorldError $error) {
    checkWorldContract($error->reason === 'VALIDATION_ERROR', 'invalid custom genre error mismatch');
}

echo "World contract: fixed fields, targeted merge, warnings, malformed response and custom input passed.\n";
