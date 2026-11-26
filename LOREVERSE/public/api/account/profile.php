<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/account-actions.php';

loreverseStudioApi('GET', static fn(PDO $pdo, int $userId): array =>
    (new AccountService($pdo, $userId))->profile());
