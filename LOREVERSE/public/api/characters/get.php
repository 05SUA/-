<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/CharacterService.php';

loreverseStudioApi('GET', static fn (PDO $pdo, int $user, array $body): array =>
    ['character' => (new CharacterService($pdo, $user))->get(loreverseCharacterId($body['id'] ?? null))]);
