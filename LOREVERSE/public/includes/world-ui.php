<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/genres.php';
require_once __DIR__ . '/sidebar.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/WorldService.php';

function loreverseWorldGenreName(string $genre, string $custom = ''): string
{
    return $genre === 'custom' ? ($custom !== '' ? $custom : '직접 입력') : (loreverseGenres()[$genre][0] ?? '미분류');
}

function loreverseWorldCoverUrl(array $world): string
{
    if (!empty($world['has_cover'])) {
        return loreverseUrl('world-cover.php', ['id' => $world['id'], 'v' => $world['updated_at']]);
    }
    $asset = isset(loreverseGenres()[$world['genre']]) ? 'genre-' . $world['genre'] . '.webp' : 'hero-poster.webp';
    return loreverseUrl('assets/images/' . $asset);
}

function loreverseWorldPageError(Throwable $error): void
{
    $status = $error instanceof LoreverseWorldError ? $error->status : 503;
    http_response_code($status);
    loreversePageStart($status === 404 ? '세계관을 찾을 수 없어요' : '잠시 후 다시 시도해 주세요');
    ?>
    <main id="main" class="site-container workspace-page" tabindex="-1">
        <section class="surface workspace-intro">
            <h1><?= $status === 404 ? '세계관을 찾을 수 없어요.' : '세계관에 연결할 수 없어요.' ?></h1>
            <a href="<?= loreverseEscape(loreverseUrl('workspace.php')) ?>">작업 공간으로 돌아가기</a>
        </section>
    </main>
    <?php
    loreversePageEnd();
    exit;
}

function loreverseWorldStudioStart(string $title, string $active, array $scripts, ?array $world = null): void
{
    loreversePageStart($title, 'workspace', $scripts, true);
    $links = [
        ['label' => '대시보드', 'url' => loreverseUrl('workspace.php'), 'icon' => 'grid', 'current' => $active === 'dashboard'],
        ['label' => '내 세계관', 'url' => loreverseUrl('workspace.php', ['tab' => 'worlds']), 'icon' => 'layers', 'current' => $active === 'worlds'],
        ['label' => '새 세계관', 'url' => loreverseUrl('world-create.php'), 'icon' => 'plus', 'current' => $active === 'create'],
    ];
    if ($world !== null) {
        $links[] = ['label' => '세계관 개요', 'url' => loreverseUrl('world.php', ['id' => $world['id']]),
            'icon' => 'activity', 'current' => $active === 'detail'];
    }
    ?>
    <div class="studio-shell">
        <aside class="studio-sidebar">
            <p class="sidebar-title"><?= loreverseEscape($world['name'] ?? '작업 공간') ?></p>
            <?php loreverseSidebar($links, '작업 공간'); ?>
        </aside>
        <dialog class="drawer" id="studio-navigation" data-drawer="sidebar" aria-label="작업 공간 메뉴">
            <div class="drawer-heading">
                <p class="sidebar-title">작업 공간</p>
                <form method="dialog"><button class="button button--quiet button--icon" aria-label="메뉴 닫기">
                    <span class="icon icon--x" aria-hidden="true"></span>
                </button></form>
            </div>
            <?php loreverseSidebar($links, '모바일 작업 공간'); ?>
        </dialog>
        <main id="main" class="studio-main world-studio" tabindex="-1">
    <?php
}

function loreverseWorldStudioEnd(): void
{
    echo '</main></div>';
    loreversePageEnd(true);
}

function loreverseWorldCards(array $worlds): void
{
    if ($worlds === []) {
        ?>
        <div class="state">
            <span class="icon icon--folder" aria-hidden="true"></span>
            <div><strong>표시할 세계관이 없어요.</strong><p>새 세계관을 만들거나 검색 조건을 바꿔 보세요.</p></div>
        </div>
        <?php
        return;
    }
    ?>
    <div class="world-cards">
        <?php foreach ($worlds as $world): ?>
            <a class="surface world-card" href="<?= loreverseEscape(loreverseUrl('world.php', ['id' => $world['id']])) ?>">
                <img src="<?= loreverseEscape(loreverseWorldCoverUrl($world)) ?>" alt="" loading="lazy" width="512" height="288">
                <div class="world-card-copy">
                    <span><?= loreverseEscape(loreverseWorldGenreName($world['genre'], $world['custom_genre'])) ?></span>
                    <h3><?= loreverseEscape($world['name']) ?></h3>
                    <?php if ($world['description'] !== ''): ?><p><?= loreverseEscape($world['description']) ?></p><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}

function loreverseWorldEntryEditor(string $name, string $label, bool $pair = false): void
{
    ?>
    <div class="world-entry-editor" data-world-list="<?= loreverseEscape($name) ?>" data-pair="<?= $pair ? 'yes' : 'no' ?>">
        <div class="world-field-title">
            <strong><?= loreverseEscape($label) ?></strong>
            <button type="button" class="button button--outline" data-add-entry>항목 추가</button>
        </div>
        <p data-empty-entry>추가된 항목이 없어요.</p>
        <ol data-entries></ol>
        <template>
            <li class="surface world-entry">
                <?php if ($pair): ?>
                    <label class="field"><?= loreverseEscape($label) ?> 이름
                        <input class="field-control" data-entry-name maxlength="100">
                    </label>
                <?php endif; ?>
                <label class="field"><?= loreverseEscape($pair ? '설명' : $label) ?>
                    <?php if ($name === 'keywords'): ?>
                        <input class="field-control" data-entry-text maxlength="80">
                    <?php else: ?>
                        <textarea class="field-control" data-entry-text maxlength="500" rows="2"></textarea>
                    <?php endif; ?>
                </label>
                <button type="button" class="button button--quiet" data-remove-entry>항목 제거</button>
            </li>
        </template>
    </div>
    <?php
}
