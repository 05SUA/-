<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/ChatService.php';

loreverseStudioApi('POST', static function (PDO $pdo, int $user, array $body): array {
    (new ChatService($pdo, $user))->delete(loreverseChatId($body['session_id'] ?? null), $body['confirmed'] ?? null);
    return ['deleted' => true];
});
