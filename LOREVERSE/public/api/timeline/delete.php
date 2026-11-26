<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/TimelineService.php';
loreverseStudioApi('POST', static fn (PDO $pdo, int $user, array $data): array =>
    (new TimelineService($pdo, $user))->delete($data));
