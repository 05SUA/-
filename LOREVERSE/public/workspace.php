<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/world-ui.php';
loreverseWebStart();
$user = loreversePageUser(loreverseUrl('workspace.php', $_GET));
if (($_GET['create'] ?? '') === 'world') {
    $genre = $_GET['genre'] ?? null;
    loreverseRedirect(loreverseUrl('world-create.php', in_array($genre, LOREVERSE_GENRES, true) ? ['genre' => $genre] : []));
}
try {
    $pdo = loreverseDatabase(loreverseWebConfig());
    $find = $pdo->prepare('SELECT nickname FROM users WHERE id = ?');
    $find->execute([$user]);
    $nickname = $find->fetchColumn();
    if ($nickname === false) {
        unset($_SESSION['user_id'], $_SESSION['csrf']);
        session_regenerate_id(true);
        loreverseRedirect(loreverseUrl('login.php'));
    }
    $worlds = new WorldService($pdo, $user);
    $listing = ($_GET['tab'] ?? '') === 'worlds';
    $summary = $worlds->summary();
    $list = $listing ? $worlds->list($_GET) : null;
} catch (Throwable $error) {
    loreverseWorldPageError($error);
}
$escape = 'loreverseEscape';
$filter = [];
foreach (['q', 'genre', 'sort'] as $field) {
    $filter[$field] = is_string($_GET[$field] ?? null) ? mb_substr($_GET[$field], 0, 100, 'UTF-8') : '';
}
loreverseWorldStudioStart($listing ? '내 세계관' : '작업 공간', $listing ? 'worlds' : 'dashboard', ['auth']);
?>
<header class="workspace-intro surface world-welcome">
    <p class="display-kicker" lang="en">WORKSPACE</p>
    <h1 class="section-title"><?= $listing ? '내 세계관' : $escape($nickname) . '님, 환영해요.' ?></h1>
    <?php if (($_GET['create'] ?? '') === 'character'): ?><p>캐릭터 만들기는 다음 개발 단계에서 열립니다.</p><?php endif; ?>
    <div class="world-actions">
        <a class="button button--primary" href="<?= $escape(loreverseUrl('world-create.php')) ?>">
            <span class="icon icon--plus" aria-hidden="true"></span>새 세계관
        </a>
        <a class="button button--outline" href="<?= $escape(loreverseUrl('explore.php')) ?>">세계 탐색하기</a>
        <form action="<?= $escape(loreverseUrl('api/auth/logout.php')) ?>" method="post" data-auth-form
              data-auth-endpoint="<?= $escape(loreverseUrl('api/auth/logout.php')) ?>">
            <input type="hidden" name="_csrf" value="<?= $escape(loreverseCsrf()) ?>">
            <button class="button button--quiet" type="submit">로그아웃</button>
            <p class="form-message" data-auth-message role="alert" tabindex="-1" hidden></p>
        </form>
    </div>
</header>
<?php if (!$listing): ?>
    <dl class="world-stats">
        <?php foreach (['worlds' => '세계관', 'characters' => '캐릭터', 'stories' => '스토리'] as $key => $label): ?>
            <div class="surface"><dt><?= $label ?></dt><dd><?= $summary['counts'][$key] ?></dd></div>
        <?php endforeach; ?>
    </dl>
<?php endif; ?>
<section class="world-stack" aria-labelledby="world-list-title">
    <div class="world-field-title">
        <h2 id="world-list-title"><?= $listing ? '세계관 목록' : '최근 작업한 세계관' ?></h2>
        <?php if (!$listing): ?><a class="button button--outline" href="<?= $escape(loreverseUrl('workspace.php', ['tab' => 'worlds'])) ?>">전체 보기</a><?php endif; ?>
    </div>
    <?php if ($listing): ?>
        <form class="surface world-filters" method="get" action="<?= $escape(loreverseUrl('workspace.php')) ?>">
            <input type="hidden" name="tab" value="worlds">
            <label class="field">이름 또는 소개
                <input type="search" name="q" class="field-control" maxlength="100" value="<?= $escape($filter['q']) ?>">
            </label>
            <div class="field">
                <label for="world-filter-genre">장르</label>
                <select class="field-control" id="world-filter-genre" name="genre">
                    <option value="">전체</option>
                    <?php foreach (LOREVERSE_GENRES as $genre): ?>
                        <option value="<?= $escape($genre) ?>" <?= $filter['genre'] === $genre ? 'selected' : '' ?>><?= $escape(loreverseWorldGenreName($genre)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="world-filter-sort">정렬</label>
                <select class="field-control" id="world-filter-sort" name="sort">
                    <?php foreach (['updated' => '최근 수정순', 'created' => '최근 생성순', 'name' => '이름순'] as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filter['sort'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="button button--primary" type="submit">검색</button>
        </form>
        <p class="world-muted">세계관 <?= $list['total'] ?>개</p>
    <?php endif; ?>
    <?php loreverseWorldCards($listing ? $list['items'] : $summary['recent']); ?>
    <?php if ($listing && $list['pages'] > 1): ?>
        <nav class="world-actions" aria-label="세계관 목록 페이지">
            <?php if ($list['page'] > 1): ?><a class="button button--outline" href="<?= $escape(loreverseUrl('workspace.php', $filter + ['tab' => 'worlds', 'page' => $list['page'] - 1])) ?>">이전</a><?php endif; ?>
            <span><?= $list['page'] ?> / <?= $list['pages'] ?></span>
            <?php if ($list['page'] < $list['pages']): ?><a class="button button--outline" href="<?= $escape(loreverseUrl('workspace.php', $filter + ['tab' => 'worlds', 'page' => $list['page'] + 1])) ?>">다음</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php loreverseWorldStudioEnd(); ?>
