<?php

declare(strict_types=1);

require_once __DIR__ . '/world-data.php';

function loreverseReserveAi(PDO $pdo, int $userId, string $requestId, string $feature, string $model, int $dailyLimit): void
{
    $pdo->beginTransaction();
    try {
        $owner = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $owner->execute([$userId]);
        if (!$owner->fetchColumn()) {
            throw new LoreverseWorldError('AUTH_REQUIRED', '다시 로그인해 주세요.', 401);
        }
        $owner->closeCursor();
        $stale = $pdo->prepare("UPDATE ai_usage_logs SET status = 'error', error_code = 'REQUEST_EXPIRED'
            WHERE user_id = ? AND status = 'pending' AND created_at < UTC_TIMESTAMP() - INTERVAL 2 MINUTE");
        $stale->execute([$userId]);
        $duplicate = $pdo->prepare('SELECT id FROM ai_usage_logs WHERE user_id = ? AND request_id = ?');
        $duplicate->execute([$userId, $requestId]);
        if ($duplicate->fetchColumn()) {
            throw new LoreverseWorldError('DUPLICATE_REQUEST', '이미 처리한 요청이에요. 새로 시도해 주세요.', 409);
        }
        $active = $pdo->prepare("SELECT id FROM ai_usage_logs WHERE user_id = ? AND status = 'pending' LIMIT 1");
        $active->execute([$userId]);
        if ($active->fetchColumn()) {
            throw new LoreverseWorldError('AI_IN_PROGRESS', '이전 AI 요청이 처리 중이에요. 잠시 후 다시 시도해 주세요.', 409);
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM ai_usage_logs WHERE user_id = ? AND created_at >= UTC_DATE()
            AND created_at < UTC_DATE() + INTERVAL 1 DAY');
        $count->execute([$userId]);
        if ((int) $count->fetchColumn() >= $dailyLimit) {
            throw new LoreverseWorldError('AI_DAILY_LIMIT', '오늘의 AI 사용 한도에 도달했어요. 한도는 UTC 자정에 갱신돼요.', 429);
        }
        $insert = $pdo->prepare("INSERT INTO ai_usage_logs (user_id, request_id, feature, status, model)
            VALUES (?, ?, ?, 'pending', ?)");
        $insert->execute([$userId, $requestId, $feature, $model]);
        $pdo->commit();
    } catch (LoreverseWorldError $error) {
        // Persist expiry of stale reservations even when this request is rejected.
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function loreverseFinishAi(
    PDO $pdo,
    int $userId,
    string $requestId,
    ?string $error,
    ?array $usage,
    int $duration
): void {
    $update = $pdo->prepare("UPDATE ai_usage_logs SET status = ?, error_code = ?,
          input_tokens = ?, output_tokens = ?, duration_ms = ?
        WHERE user_id = ? AND request_id = ? AND status = 'pending'");
    $update->execute([
        $error === null ? 'success' : 'error', $error,
        $usage['input_tokens'] ?? null, $usage['output_tokens'] ?? null, $duration, $userId, $requestId,
    ]);
}
