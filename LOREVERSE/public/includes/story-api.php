<?php

declare(strict_types=1);

require_once __DIR__ . '/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/StoryService.php';

function loreverseStoryApi(string $action): void
{
    loreverseStudioApi(in_array($action, ['list', 'get'], true) ? 'GET' : 'POST',
        static function (PDO $pdo, int $userId, array $body) use ($action): array {
            $stories = new StoryService($pdo, $userId);
            return match ($action) {
                'list' => $stories->list(isset($body['world_id']) ? loreverseWorldId($body['world_id']) : null, $body),
                'get' => ['story' => $stories->get(loreverseStoryId($body['id'] ?? null),
                    isset($body['world_id']) ? loreverseWorldId($body['world_id']) : null)],
                'create' => ['story' => $stories->create($body)],
                'advance' => $stories->advance($body, loreverseWebConfig()),
                'apply-canon' => $stories->applyCanon($body),
                'complete' => $stories->complete($body),
                'delete' => $stories->delete($body),
            };
        });
}
