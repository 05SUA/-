<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/character-image.php';

loreverseStudioApi('POST', static function (PDO $pdo, int $userId, array $body): array {
    $id = loreverseCharacterId($body['target_id'] ?? null);
    loreverseUploadCharacterImage($pdo, $userId, $id, $_FILES['file'] ?? null);
    return ['character' => (new CharacterService($pdo, $userId))->get($id)];
}, true);
