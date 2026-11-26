<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/TimelineService.php';
loreverseStudioApi('GET', static fn (PDO $pdo, int $user, array $data): array =>
    (new TimelineService($pdo, $user))->listing(loreverseWorldId($data['world_id'] ?? null), $data));
