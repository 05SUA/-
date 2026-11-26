<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/character-image.php';

loreverseStudioApi('POST', static function (PDO $pdo, int $user, array $body): array {
    if (($body['confirmed'] ?? false) !== true) {
        throw new LoreverseWorldError('CONFIRMATION_REQUIRED', '삭제할 캐릭터를 확인해 주세요.');
    }
    loreverseRemoveCharacterImage((new CharacterService($pdo, $user))->delete(loreverseCharacterId($body['id'] ?? null)));
    return ['deleted' => true];
});
