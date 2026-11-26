<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/account-actions.php';

$media = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
loreverseStudioApi('POST', static fn(PDO $pdo, int $userId, array $body): array =>
    (new AccountService($pdo, $userId))->update($body, $_FILES['file'] ?? null),
    $media === 'multipart/form-data');
