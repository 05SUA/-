<?php

declare(strict_types=1);

require_once __DIR__ . '/WorldGenerator.php';

function loreverseStudioAi(
    PDO $pdo,
    int $user,
    mixed $requestId,
    string $feature,
    string $instruction,
    array $input,
    array $schema,
    callable $validate,
    int $maxOutputTokens = 1800
): array {
    $requestId = loreverseWorldAiRequestId($requestId);
    $config = loreverseConfig();
    $gemini = new GeminiService();
    try {
        $gemini->validateConfig($config);
    } catch (GeminiException $error) {
        throw loreverseWorldGeminiError($error);
    }
    $limit = filter_var($config['AI_DAILY_LIMIT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) ?: 30;
    loreverseReserveAi($pdo, $user, $requestId, $feature, $config['GEMINI_MODEL'], $limit);
    $started = microtime(true);
    $usage = null;
    $failure = null;
    try {
        return $validate($gemini->generateStructured($config, $instruction, $input, $schema, $maxOutputTokens, $usage));
    } catch (GeminiException $error) {
        $failure = $error->getMessage();
        throw loreverseWorldGeminiError($error);
    } catch (Throwable $error) {
        $failure = $error instanceof LoreverseWorldError ? $error->reason : 'INTERNAL_ERROR';
        throw $error;
    } finally {
        loreverseFinishAi($pdo, $user, $requestId, $failure, $usage, (int) round((microtime(true) - $started) * 1000));
    }
}
