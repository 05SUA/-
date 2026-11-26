<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/FavoriteService.php';

function loreverseFavoriteButton(PDO $pdo, int $userId, string $type, int $id, string $returnUrl): void
{
    $saved = (new FavoriteService($pdo, $userId))->contains($type, $id);
    loreverseFavoriteForm($type, $id, $returnUrl, $saved);
}

function loreverseFavoriteForm(string $type, int $id, string $returnUrl, bool $saved): void
{
    $escape = 'loreverseEscape';
    ?>
    <form method="post" action="<?= $escape(loreverseUrl('api/favorites/toggle.php')) ?>">
        <input type="hidden" name="_csrf" value="<?= $escape(loreverseCsrf()) ?>">
        <input type="hidden" name="type" value="<?= $escape($type) ?>">
        <input type="hidden" name="target_id" value="<?= $id ?>">
        <input type="hidden" name="desired_state" value="<?= $saved ? '0' : '1' ?>">
        <input type="hidden" name="return" value="<?= $escape($returnUrl) ?>">
        <button type="submit" class="button button--outline" aria-pressed="<?= $saved ? 'true' : 'false' ?>">
            <?= $saved ? '즐겨찾기 해제' : '즐겨찾기 추가' ?>
        </button>
    </form>
    <?php
}

function loreverseFavoriteCards(array $items, string $returnUrl): void
{
    if ($items === []) {
        ?>
        <div class="state">
            <span class="icon icon--folder" aria-hidden="true"></span>
            <div><strong>즐겨찾기에 저장한 항목이 없어요.</strong><p>세계관, 캐릭터, 스토리 페이지에서 즐겨찾기에 추가할 수 있어요.</p></div>
        </div>
        <?php
        return;
    }
    $routes = ['world' => ['세계관', 'world.php'], 'character' => ['캐릭터', 'character.php'],
        'story' => ['스토리', 'story.php']];
    $escape = 'loreverseEscape';
    ?>
    <div class="world-cards">
        <?php foreach ($items as $item): ?>
            <?php [$label, $route] = $routes[$item['type']]; ?>
            <article class="surface world-panel world-stack">
                <p class="world-muted"><?= $label ?></p>
                <h3><a href="<?= $escape(loreverseUrl($route, ['id' => $item['target_id']])) ?>"><?= $escape($item['name']) ?></a></h3>
                <?php loreverseFavoriteForm($item['type'], $item['target_id'], $returnUrl, true); ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
}
