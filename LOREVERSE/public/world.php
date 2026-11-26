<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/world-ui.php';
loreverseWebStart();
$user = loreversePageUser(loreverseUrl('world.php', $_GET));
try {
    $service = new WorldService(loreverseDatabase(loreverseWebConfig()), $user);
    $world = $service->get(loreverseWorldId($_GET['id'] ?? null));
    $counts = $service->activityCounts($world['id']);
} catch (Throwable $error) {
    loreverseWorldPageError($error);
}
$escape = 'loreverseEscape';
loreverseWorldStudioStart('세계관 개요', 'detail', ['world-api', 'world-detail'], $world);
?>
<div class="world-stack" data-world-detail data-id="<?= $world['id'] ?>"
     data-csrf="<?= $escape(loreverseCsrf()) ?>" data-api-base="<?= $escape(loreverseUrl('api/')) ?>"
     data-list-url="<?= $escape(loreverseUrl('workspace.php', ['tab' => 'worlds'])) ?>"
     data-world-url="<?= $escape(loreverseUrl('world.php')) ?>">
    <header class="world-banner">
        <img src="<?= $escape(loreverseWorldCoverUrl($world)) ?>" alt="" width="512" height="288">
        <div>
            <p class="display-kicker"><?= $escape(loreverseWorldGenreName($world['genre'], $world['custom_genre'])) ?></p>
            <h1 class="section-title"><?= $escape($world['name']) ?></h1>
        </div>
    </header>
    <p class="form-message world-notice" data-world-message role="status" tabindex="-1" hidden></p>
    <div class="world-actions">
        <a class="button button--primary" href="<?= $escape(loreverseUrl('world-create.php', ['id' => $world['id']])) ?>">편집</a>
        <button class="button button--outline" type="button" data-world-duplicate>설정 복제</button>
        <button class="button button--outline world-delete" type="button" data-world-delete>삭제</button>
    </div>
    <section class="surface world-panel">
        <h2>세계관 개요</h2>
        <?php if ($world['description'] !== ''): ?><p class="world-prose"><?= $escape($world['description']) ?></p><?php endif; ?>
        <dl class="world-meta">
            <div><dt>시대</dt><dd><?= $escape($world['era'] !== '' ? $world['era'] : '미정') ?></dd></div>
            <div><dt>분위기</dt><dd><?= $escape($world['mood'] !== '' ? $world['mood'] : '미정') ?></dd></div>
            <div><dt>캐릭터</dt><dd><?= $counts['characters'] ?></dd></div>
            <div><dt>세력</dt><dd><?= count($world['factions']) ?></dd></div>
            <div><dt>사건</dt><dd><?= $counts['events'] ?></dd></div>
            <div><dt>수정 시간 (UTC)</dt><dd><?= $escape(substr($world['updated_at'], 0, 19)) ?></dd></div>
        </dl>
        <?php if ($world['features'] !== ''): ?><h3>특징</h3><p class="world-prose"><?= $escape($world['features']) ?></p><?php endif; ?>
        <?php if ($world['keywords'] !== []): ?><ul class="world-keywords">
            <?php foreach ($world['keywords'] as $keyword): ?><li><?= $escape($keyword) ?></li><?php endforeach; ?>
        </ul><?php endif; ?>
    </section>
    <div class="world-details-grid">
        <section class="surface world-panel"><h2>역사</h2><p class="world-prose"><?= $escape($world['history'] !== '' ? $world['history'] : '아직 기록된 역사가 없어요.') ?></p></section>
        <?php foreach (['regions' => '주요 지역', 'factions' => '주요 세력', 'rules' => '세계 규칙', 'conflicts' => '핵심 갈등'] as $field => $label): ?>
            <section class="surface world-panel">
                <h2><?= $label ?></h2>
                <?php if ($world[$field] === []): ?><p class="world-muted">아직 기록된 설정이 없어요.</p>
                <?php else: ?><ul class="world-records">
                    <?php foreach ($world[$field] as $item): ?>
                        <li class="world-prose"><?php if (is_array($item)): ?>
                            <strong><?= $escape($item['name']) ?></strong> <?= $escape($item['description']) ?>
                        <?php else: ?><?= $escape($item) ?><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul><?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <form class="surface world-panel" data-detail-cover>
        <h2>커버 이미지</h2>
        <label class="field">새 커버 선택
            <input type="file" class="field-control" name="file" accept="image/jpeg,image/png,image/webp" required>
            <span class="field-help">JPG, PNG, WebP · 2MB 및 4096×4096 이하</span>
        </label>
        <button type="submit" class="button button--outline">커버 올리기</button>
    </form>
</div>
<?php loreverseWorldStudioEnd(); ?>
