<?php

declare(strict_types=1);

require_once __DIR__ . '/GeminiService.php';
require_once dirname(__DIR__) . '/world-data.php';
require_once dirname(__DIR__) . '/prompts/world.php';
require_once dirname(__DIR__) . '/ai-usage.php';

function loreverseWorldAiInput(array $request): array
{
    $seed = loreverseWorldData($request);
    $current = $request['current_data'] ?? null;
    if ($current !== null && !is_array($current)) {
        loreverseWorldInvalid('current_data');
    }
    if ($current !== null) {
        $current = loreverseWorldData(array_replace($current, [
            'genre' => $seed['genre'], 'custom_genre' => $seed['custom_genre'],
            'features' => $seed['features'], 'keywords' => $seed['keywords'],
        ]));
    }
    $locks = $request['locked_fields'] ?? [];
    if (!is_array($locks) || array_values($locks) !== $locks || count($locks) > count(WORLD_AI_FIELDS)
        || array_filter($locks, static fn($field) => !in_array($field, WORLD_AI_FIELDS, true)) !== []) {
        loreverseWorldInvalid('locked_fields');
    }
    foreach (['name', 'era', 'mood'] as $field) {
        if ($seed[$field] !== '') {
            $locks[] = $field;
        }
    }
    $locks = array_values(array_unique($locks));
    $target = $request['target_field'] ?? null;
    if ($target !== null && ($current === null || !in_array($target, WORLD_AI_FIELDS, true))) {
        loreverseWorldInvalid('target_field');
    }
    if (in_array($target, $locks, true)) {
        throw new LoreverseWorldError('FIELD_LOCKED', '고정한 항목은 재생성할 수 없어요.');
    }
    return $seed + [
        'faction_hints' => loreverseWorldText($request['faction_hints'] ?? '', 1000, 'faction_hints'),
        'locked_fields' => $locks, 'current_data' => $current, 'target_field' => $target,
    ];
}

function loreverseMergeWorldDraft(array $input, stdClass $result): array
{
    $target = $input['target_field'];
    $data = $input['current_data'] ?? array_intersect_key($input, array_flip(['genre', ...array_keys(WORLD_TEXT_LIMITS), ...WORLD_LIST_FIELDS]));
    $warnings = [];
    try {
        foreach (loreverseWorldSchema($target)['required'] as $field) {
            if (!property_exists($result, $field)) {
                loreverseWorldInvalid($field);
            }
            $value = $result->{$field};
            if ($field === 'genre') {
                if (!in_array($value, LOREVERSE_GENRES, true)) {
                    loreverseWorldInvalid($field);
                }
                continue;
            }
            if ($field === 'warnings') {
                $warnings = loreverseWorldList($value, $field);
                continue;
            }
            $clean = in_array($field, WORLD_LIST_FIELDS, true)
                ? loreverseWorldList($value, $field)
                : loreverseWorldText($value, WORLD_TEXT_LIMITS[$field], $field, $field === 'name');
            if ($field !== 'custom_genre' && !in_array($field, $input['locked_fields'], true)) {
                $data[$field] = $clean;
            }
        }
    } catch (LoreverseWorldError) {
        throw new GeminiException('GEMINI_SCHEMA_MISMATCH');
    }
    return ['world' => $data, 'warnings' => $warnings];
}

function loreverseWorldGeminiError(GeminiException $error): LoreverseWorldError
{
    $code = $error->getMessage();
    [$message, $status] = match ($code) {
        'GEMINI_CONFIG_INVALID', 'GEMINI_CURL_MISSING' => ['서버에 Gemini 연결 설정이 필요해요.', 503],
        'GEMINI_AUTH_ERROR', 'GEMINI_MODEL_UNAVAILABLE' => ['서버의 Gemini 키 또는 모델 설정을 확인해 주세요.', 503],
        'GEMINI_RATE_LIMIT' => ['Gemini 사용 한도에 도달했어요. 잠시 후 다시 시도해 주세요.', 429],
        'GEMINI_SERVICE_UNAVAILABLE' => ['Gemini 서버에서 지금 답변을 생성할 수 없어요. 입력을 유지했으니 잠시 후 다시 시도해 주세요.', 503],
        'GEMINI_TIMEOUT' => ['Gemini 응답이 늦어졌어요. 잠시 후 다시 시도해 주세요.', 503],
        'GEMINI_RESPONSE_BLOCKED' => ['Gemini가 이 요청에 답변하지 않았어요. 입력을 확인해 주세요.', 502],
        'GEMINI_SCHEMA_MISMATCH', 'GEMINI_INVALID_JSON', 'GEMINI_EMPTY_RESPONSE',
        'GEMINI_INCOMPLETE_RESPONSE', 'GEMINI_RESPONSE_TRUNCATED' => ['Gemini 응답 형식을 확인할 수 없었어요. 다시 시도해 주세요.', 502],
        default => ['Gemini에 연결하거나 요청을 처리할 수 없었어요. 잠시 후 다시 시도해 주세요.', 503],
    };
    return new LoreverseWorldError($code, $message, $status);
}

function loreverseWorldAiRequestId(mixed $requestId): string
{
    if (!is_string($requestId) || !preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $requestId)) {
        loreverseWorldInvalid('request_id');
    }
    return strtolower($requestId);
}

function loreverseGenerateWorld(PDO $pdo, int $userId, array $request, array $config): array
{
    $requestId = loreverseWorldAiRequestId($request['request_id'] ?? null);
    $input = loreverseWorldAiInput($request);
    $gemini = new GeminiService();
    try {
        $gemini->validateConfig($config);
    } catch (GeminiException $error) {
        throw loreverseWorldGeminiError($error);
    }
    $limit = filter_var($config['AI_DAILY_LIMIT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) ?: 30;
    loreverseReserveAi($pdo, $userId, $requestId, 'world_generate', $config['GEMINI_MODEL'], $limit);
    $started = microtime(true);
    $usage = null;
    $failure = null;
    try {
        $target = $input['target_field'];
        $result = $gemini->generateStructured($config, loreverseWorldPrompt(), $input, loreverseWorldSchema($target),
            $target === null ? 4000 : 2200, $usage);
        return loreverseMergeWorldDraft($input, $result);
    } catch (GeminiException $error) {
        $failure = $error->getMessage();
        throw loreverseWorldGeminiError($error);
    } catch (Throwable $error) {
        $failure = 'INTERNAL_ERROR';
        throw $error;
    } finally {
        $duration = (int) round((microtime(true) - $started) * 1000);
        loreverseFinishAi($pdo, $userId, $requestId, $failure, $usage, $duration, $config);
    }
}

function loreverseUnitEmbedding(array $vector): array
{
    $scale = max(array_map('abs', $vector));
    $scaled = array_map(static fn (float $value): float => $value / $scale, $vector);
    $length = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $scaled)));
    return array_map(static fn (float $value): float => $value / $length, $scaled);
}

function loreverseRecommendWorldGenre(PDO $pdo, int $userId, array $request, array $config, array $genres): array
{
    $requestId = loreverseWorldAiRequestId($request['request_id'] ?? null);
    $idea = loreverseWorldText($request['idea'] ?? null, WORLD_TEXT_LIMITS['features'], 'features', true);
    $descriptions = array_map(static fn (array $genre): string => $genre[0] . ' 세계관. ' . $genre[2], array_values($genres));
    $model = $config['GEMINI_EMBEDDING_MODEL'] ?? GeminiService::DEFAULT_EMBEDDING_MODEL;
    $gemini = new GeminiService();
    try {
        $gemini->validateConfig(['GEMINI_MODEL' => $model] + $config);
    } catch (GeminiException $error) {
        throw loreverseWorldGeminiError($error);
    }
    $limit = filter_var($config['AI_DAILY_LIMIT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) ?: 30;
    loreverseReserveAi($pdo, $userId, $requestId, 'genre_recommend', $model, $limit);
    $started = microtime(true);
    $failure = null;
    try {
        $vectors = $gemini->embedForSimilarity($config, [$idea, ...$descriptions]);
        $ideaVector = loreverseUnitEmbedding(array_shift($vectors));
        $genreIds = array_keys($genres);
        $bestGenre = $genreIds[0];
        $bestSimilarity = -INF;
        foreach ($vectors as $index => $vector) {
            $genreVector = loreverseUnitEmbedding($vector);
            $similarity = array_sum(array_map(static fn (float $left, float $right): float => $left * $right,
                $ideaVector, $genreVector));
            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestGenre = $genreIds[$index];
            }
        }
        return ['genre' => $bestGenre, 'label' => $genres[$bestGenre][0]];
    } catch (GeminiException $error) {
        $failure = $error->getMessage();
        throw loreverseWorldGeminiError($error);
    } catch (Throwable $error) {
        $failure = 'INTERNAL_ERROR';
        throw $error;
    } finally {
        $duration = (int) round((microtime(true) - $started) * 1000);
        loreverseFinishAi($pdo, $userId, $requestId, $failure, null, $duration, $config);
    }
}
