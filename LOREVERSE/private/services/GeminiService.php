<?php

declare(strict_types=1);

final class GeminiException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $quotaMessage = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

final class GeminiService
{
    public const DEFAULT_EMBEDDING_MODEL = 'gemini-embedding-001';

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const EMBEDDING_DIMENSIONS = 768;
    private const MAX_IMAGE_BYTES = 2097152;
    private const MAX_IMAGE_RESPONSE_BYTES = 3145728;
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function validateConfig(array $config): void
    {
        $this->validateConnection($config['GEMINI_API_KEY'] ?? null, $config['GEMINI_MODEL'] ?? null);
    }

    public function validateImageConfig(array $config): void
    {
        $this->validateConnection($config['GEMINI_API_KEY'] ?? null, $config['GEMINI_IMAGE_MODEL'] ?? null);
    }

    public function generateStructured(
        array $config,
        string $systemInstruction,
        array $input,
        array $schema,
        int $maxOutputTokens = 1024,
        ?array &$usage = null
    ): stdClass {
        $this->validateConfig($config);
        $key = $config['GEMINI_API_KEY'];
        $model = $config['GEMINI_MODEL'];
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => [['role' => 'user', 'parts' => [[
                'text' => json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $schema,
                'maxOutputTokens' => max(1, min(4000, $maxOutputTokens)),
            ],
        ];
        $timeoutMs = $this->requestTimeoutMs();
        $started = hrtime(true);
        $usage = ['model' => $model];
        try {
            $body = $this->request($key, $model, 'generateContent', $payload, $timeoutMs);
        } catch (GeminiException $error) {
            $backup = $config['GEMINI_FALLBACK_MODEL'] ?? '';
            $remainingMs = $timeoutMs - (int) ceil((hrtime(true) - $started) / 1000000);
            if (!in_array($error->getCode(), [404, 503], true) || !$this->validModel($backup)
                || $backup === $model || $remainingMs < 1000) {
                throw $error;
            }
            $usage['model'] = $backup;
            $body = $this->request($key, $backup, 'generateContent', $payload, $remainingMs);
        }
        $response = json_decode($body, true);
        if (!is_array($response)) {
            throw new GeminiException('GEMINI_INVALID_JSON');
        }
        $metadata = $response['usageMetadata'] ?? [];
        $usage += [
            'input_tokens' => filter_var($metadata['promptTokenCount'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 4294967295]]) === false ? null : (int) $metadata['promptTokenCount'],
            'output_tokens' => filter_var($metadata['candidatesTokenCount'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 4294967295]]) === false ? null : (int) $metadata['candidatesTokenCount'],
        ];

        $candidate = $response['candidates'][0] ?? [];
        $finishReason = $candidate['finishReason'] ?? '';
        if (
            !empty($response['promptFeedback']['blockReason'])
            || in_array($finishReason, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'MODEL_ARMOR'], true)
        ) {
            throw new GeminiException('GEMINI_RESPONSE_BLOCKED');
        }
        if ($finishReason === 'MAX_TOKENS') {
            throw new GeminiException('GEMINI_RESPONSE_TRUNCATED');
        }

        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (empty($part['thought']) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }
        if (trim($text) === '') {
            throw new GeminiException('GEMINI_EMPTY_RESPONSE');
        }
        if ($finishReason !== 'STOP') {
            throw new GeminiException('GEMINI_INCOMPLETE_RESPONSE');
        }

        $result = json_decode($text);
        if (!$result instanceof stdClass) {
            throw new GeminiException('GEMINI_INVALID_JSON');
        }

        return $result;
    }

    /** @return array{mime_type: string, base64: string} */
    public function generatePortrait(array $config, string $prompt, ?array &$usage = null): array
    {
        $this->validateImageConfig($config);
        $key = $config['GEMINI_API_KEY'];
        $model = $config['GEMINI_IMAGE_MODEL'];
        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseModalities' => ['IMAGE'], 'imageConfig' => ['aspectRatio' => '3:4']],
        ];
        $usage = ['model' => $model];
        $raw = $this->request($key, $model, 'generateContent', $payload, maxResponseBytes: self::MAX_IMAGE_RESPONSE_BYTES);
        $response = json_decode($raw, true, 32);
        if (!is_array($response)) {
            throw new GeminiException('GEMINI_INVALID_JSON');
        }

        $candidate = $response['candidates'][0] ?? [];
        $finish = $candidate['finishReason'] ?? '';
        if (!empty($response['promptFeedback']['blockReason']) || in_array($finish, [
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'MODEL_ARMOR',
            'IMAGE_SAFETY', 'IMAGE_PROHIBITED_CONTENT', 'IMAGE_RECITATION',
        ], true)) {
            throw new GeminiException('GEMINI_RESPONSE_BLOCKED');
        }
        if ($finish === 'NO_IMAGE') {
            throw new GeminiException('GEMINI_IMAGE_EMPTY');
        }
        if ($finish !== 'STOP') {
            throw new GeminiException('GEMINI_INCOMPLETE_RESPONSE');
        }

        $metadata = $response['usageMetadata'] ?? [];
        foreach (['promptTokenCount' => 'input_tokens', 'candidatesTokenCount' => 'output_tokens'] as $source => $field) {
            $count = filter_var($metadata[$source] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 4294967295]]);
            $usage[$field] = $count === false ? null : $count;
        }
        $parts = $candidate['content']['parts'] ?? null;
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && empty($part['thought']) && isset($part['inlineData'])) {
                    return $this->decodePortrait($part['inlineData']);
                }
            }
        }
        throw new GeminiException('GEMINI_IMAGE_EMPTY');
    }

    private function decodePortrait(mixed $part): array
    {
        $mime = $part['mimeType'] ?? null;
        $encoded = $part['data'] ?? null;
        if (!is_array($part) || !in_array($mime, self::IMAGE_TYPES, true) || !is_string($encoded) || $encoded === '') {
            throw new GeminiException('GEMINI_IMAGE_INVALID');
        }
        if (strlen($encoded) > 4 * intdiv(self::MAX_IMAGE_BYTES + 2, 3)) {
            throw new GeminiException('GEMINI_IMAGE_TOO_LARGE');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '') {
            throw new GeminiException('GEMINI_IMAGE_INVALID');
        }
        if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new GeminiException('GEMINI_IMAGE_TOO_LARGE');
        }
        $dimensions = @getimagesizefromstring($bytes);
        if (!is_array($dimensions) || $dimensions['mime'] !== $mime
            || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 4096 || $dimensions[1] > 4096
            || (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $mime) {
            throw new GeminiException('GEMINI_IMAGE_INVALID');
        }
        return ['mime_type' => $mime, 'base64' => base64_encode($bytes)];
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedForSimilarity(array $config, array $texts): array
    {
        $key = $config['GEMINI_API_KEY'] ?? null;
        $model = $config['GEMINI_EMBEDDING_MODEL'] ?? self::DEFAULT_EMBEDDING_MODEL;
        $this->validateConnection($key, $model);
        if ($texts === [] || array_values($texts) !== $texts) {
            throw new GeminiException('GEMINI_EMBEDDING_INPUT_INVALID');
        }

        $requests = [];
        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '' || preg_match('//u', $text) !== 1) {
                throw new GeminiException('GEMINI_EMBEDDING_INPUT_INVALID');
            }
            $requests[] = [
                'model' => 'models/' . $model,
                'content' => ['parts' => [['text' => $text]]],
                'taskType' => 'SEMANTIC_SIMILARITY',
                'outputDimensionality' => self::EMBEDDING_DIMENSIONS,
            ];
        }

        $response = json_decode($this->request($key, $model, 'batchEmbedContents', ['requests' => $requests]));
        if (!$response instanceof stdClass) {
            throw new GeminiException('GEMINI_INVALID_JSON');
        }
        $embeddings = $response->embeddings ?? null;
        if (!is_array($embeddings) || count($embeddings) !== count($texts)) {
            throw new GeminiException('GEMINI_SCHEMA_MISMATCH');
        }

        $vectors = [];
        foreach ($embeddings as $embedding) {
            $values = $embedding->values ?? null;
            if (!is_array($values) || count($values) !== self::EMBEDDING_DIMENSIONS) {
                throw new GeminiException('GEMINI_SCHEMA_MISMATCH');
            }
            $vector = [];
            $hasNonzeroValue = false;
            foreach ($values as $value) {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                    throw new GeminiException('GEMINI_SCHEMA_MISMATCH');
                }
                $component = (float) $value;
                $vector[] = $component;
                $hasNonzeroValue = $hasNonzeroValue || $component !== 0.0;
            }
            if (!$hasNonzeroValue) {
                throw new GeminiException('GEMINI_SCHEMA_MISMATCH');
            }
            $vectors[] = $vector;
        }
        return $vectors;
    }

    private function validateConnection(mixed $key, mixed $model): void
    {
        if (
            !is_string($key) || trim($key) === '' || preg_match('/[\x00-\x20\x7f]/', $key)
            || !$this->validModel($model)
        ) {
            throw new GeminiException('GEMINI_CONFIG_INVALID');
        }
        if (!extension_loaded('curl')) {
            throw new GeminiException('GEMINI_CURL_MISSING');
        }
    }

    private function validModel(mixed $model): bool
    {
        return is_string($model) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}\z/', $model) === 1;
    }

    private function requestTimeoutMs(): int
    {
        $executionLimit = (int) ini_get('max_execution_time');
        return 1000 * ($executionLimit > 0 ? max(1, min(25, $executionLimit - 2)) : 25);
    }

    private function quotaMessage(string $model, mixed $provider): string
    {
        $prefix = "Gemini 사용 한도에 도달했어요. 요청 모델은 {$model}입니다. ";
        $details = $provider['details'] ?? null;
        if (($provider['status'] ?? null) !== 'RESOURCE_EXHAUSTED' || !is_array($details)) {
            return $prefix . 'Google AI Studio에서 이 모델의 할당량을 확인해 주세요.';
        }

        $seconds = null;
        $limits = [];
        foreach ($details as $detail) {
            $type = $detail['@type'] ?? null;
            if ($type === 'type.googleapis.com/google.rpc.RetryInfo') {
                $delay = $detail['retryDelay'] ?? null;
                if (is_string($delay) && preg_match('/\A([0-9]{1,3}(?:\.[0-9]{1,9})?)s\z/', $delay, $match)) {
                    $seconds = (int) ceil((float) $match[1]);
                }
            }
            $violations = $detail['violations'] ?? null;
            if ($type !== 'type.googleapis.com/google.rpc.QuotaFailure' || !is_array($violations)) {
                continue;
            }
            foreach ($violations as $violation) {
                $value = $violation['quotaValue'] ?? null;
                if ($value === 0 || $value === '0') {
                    return $prefix . 'Google이 이 요청에 적용한 할당량이 0이에요. 프로젝트에서 이 모델의 사용 조건을 확인해 주세요.';
                }
                $id = $violation['quotaId'] ?? null;
                if (!is_string($id)) {
                    continue;
                }
                $id = strtolower(str_replace('_', '', $id));
                if (str_contains($id, 'perday')) {
                    $limits['daily'] = true;
                } elseif (str_contains($id, 'perminute') && str_contains($id, 'token')) {
                    $limits['tokens'] = true;
                } elseif (str_contains($id, 'perminute') && str_contains($id, 'request')) {
                    $limits['requests'] = true;
                }
            }
        }

        if (isset($limits['daily'])) {
            return $prefix . '일일 한도에 걸렸어요. Google의 할당량 초기화 시각을 확인해 주세요.';
        }
        $minute = match (true) {
            isset($limits['tokens'], $limits['requests']) => '분당 요청 수와 토큰 한도에 걸렸어요.',
            isset($limits['tokens']) => '분당 토큰 한도에 걸렸어요.',
            isset($limits['requests']) => '분당 요청 수 한도에 걸렸어요.',
            default => 'Google AI Studio에서 이 모델의 할당량을 확인해 주세요.',
        };
        return $prefix . $minute . ($seconds > 0 ? " Google이 안내한 최소 대기 시간은 {$seconds}초예요." : '');
    }

    private function request(
        string $key,
        string $model,
        string $method,
        array $payload,
        ?int $timeoutMs = null,
        ?int $maxResponseBytes = null
    ): string {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $timeoutMs ??= $this->requestTimeoutMs();
        $curl = curl_init(self::ENDPOINT . rawurlencode($model) . ':' . $method);
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $buffer = '';
        $tooLarge = false;
        if ($maxResponseBytes !== null) {
            $options[CURLOPT_WRITEFUNCTION] = static function (CurlHandle $handle, string $chunk) use (&$buffer, &$tooLarge, $maxResponseBytes): int {
                if (strlen($buffer) + strlen($chunk) > $maxResponseBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            };
        }
        if ($curl === false || !curl_setopt_array($curl, $options)) {
            throw new GeminiException('GEMINI_NETWORK_ERROR');
        }

        $response = curl_exec($curl);
        if ($tooLarge) {
            throw new GeminiException('GEMINI_IMAGE_TOO_LARGE');
        }
        if ($response === false) {
            throw new GeminiException(curl_errno($curl) === CURLE_OPERATION_TIMEDOUT
                ? 'GEMINI_TIMEOUT'
                : 'GEMINI_NETWORK_ERROR');
        }
        if ($maxResponseBytes !== null) {
            $response = $buffer;
        }
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status !== 200) {
            $error = json_decode($response, true);
            $provider = $error['error'] ?? [];
            $reason = $provider['details'][0]['reason'] ?? null;
            $confirmedUnavailable = $status === 503 && ($provider['code'] ?? null) === 503
                && ($provider['status'] ?? null) === 'UNAVAILABLE';
            $confirmedMissing = $status === 404 && ($provider['code'] ?? null) === 404
                && ($provider['status'] ?? null) === 'NOT_FOUND';
            $code = match (true) {
                $status === 401, $status === 403, $reason === 'API_KEY_INVALID' => 'GEMINI_AUTH_ERROR',
                $status === 429 => 'GEMINI_RATE_LIMIT',
                $status === 404 => 'GEMINI_MODEL_UNAVAILABLE',
                $confirmedUnavailable => 'GEMINI_SERVICE_UNAVAILABLE',
                $status === 400 => 'GEMINI_REQUEST_REJECTED',
                default => 'GEMINI_UPSTREAM_ERROR',
            };
            // Acknowledged provider availability errors are eligible for the backup model.
            $backupAllowed = $code === 'GEMINI_SERVICE_UNAVAILABLE'
                || ($code === 'GEMINI_MODEL_UNAVAILABLE' && $confirmedMissing);
            throw new GeminiException($code, $backupAllowed ? $status : 0,
                quotaMessage: $code === 'GEMINI_RATE_LIMIT' ? $this->quotaMessage($model, $provider) : null);
        }
        return $response;
    }
}
