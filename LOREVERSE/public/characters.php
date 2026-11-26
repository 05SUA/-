<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/character-ui.php';

$user = loreverseStudioUser(loreverseUrl('characters.php', $_GET));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
try {
    $pdo = loreverseDatabase(loreverseWebConfig());
    $characters = new CharacterService($pdo, $user);
    $result = $characters->list($_GET);
    $options = $characters->worldOptions();
    $contextId = $_GET['world_id'] ?? '';
    $world = $contextId !== '' && $contextId !== 'independent' ? (new WorldService($pdo, $user))->get(loreverseWorldId($contextId)) : null;
} catch (Throwable $error) {
    loreverseCharacterPageError($error);
}
$escape = 'loreverseEscape';
$filters = [];
foreach (['world_id', 'q', 'occupation', 'affiliation', 'sort'] as $key) {
    $value = $_GET[$key] ?? '';
    $filters[$key] = is_string($value) ? mb_substr($value, 0, 100, 'UTF-8') : '';
}
$create = loreverseUrl('character-create.php', $world === null ? [] : ['world_id' => $world['id']]);
loreverseWorldStudioStart('내 캐릭터', 'characters', [], $world);
?>
<div class="world-stack">
    <header class="world-heading">
        <p class="display-kicker" lang="en">CHARACTERS</p>
        <h1 class="section-title"><?= $world === null ? '내 캐릭터' : $escape($world['name']) . ' 캐릭터' ?></h1>
        <a class="button button--primary" href="<?= $escape($create) ?>">새 캐릭터</a>
    </header>
    <form method="get" class="surface world-panel world-filters" action="<?= $escape(loreverseUrl('characters.php')) ?>">
        <label class="field">이름
            <input type="search" name="q" class="field-control" maxlength="100" value="<?= $escape($filters['q']) ?>">
        </label>
        <label class="field">직업
            <input name="occupation" class="field-control" maxlength="100" value="<?= $escape($filters['occupation']) ?>">
        </label>
        <label class="field">소속
            <input name="affiliation" class="field-control" maxlength="100" value="<?= $escape($filters['affiliation']) ?>">
        </label>
        <label class="field">세계관
            <select name="world_id" class="field-control">
                <option value="">모든 세계관</option>
                <option value="independent" <?= $filters['world_id'] === 'independent' ? 'selected' : '' ?>>독립 캐릭터</option>
                <?php foreach ($options as $option): ?>
                    <option value="<?= $option['id'] ?>" <?= $filters['world_id'] === (string) $option['id'] ? 'selected' : '' ?>>
                        <?= $escape($option['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">정렬
            <select name="sort" class="field-control">
                <?php foreach (['updated' => '최근 수정순', 'created' => '최근 생성순', 'name' => '이름순'] as $order => $label): ?>
                    <option value="<?= $order ?>" <?= $filters['sort'] === $order ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button button--primary" type="submit">검색</button>
    </form>
    <?php if ($result['items'] === []): ?>
        <div class="state"><p>표시할 캐릭터가 없어요.</p></div>
    <?php else: ?>
        <div class="world-cards">
            <?php foreach ($result['items'] as $character): ?>
                <a class="surface world-card" href="<?= $escape(loreverseUrl('character.php', ['id' => $character['id']])) ?>">
                    <img src="<?= $escape(loreverseCharacterImageUrl($character)) ?>" alt="" width="512" height="288" loading="lazy">
                    <div class="world-card-copy">
                        <span><?= $escape($character['world_name'] ?? '독립 캐릭터') ?></span>
                        <h2><?= $escape($character['name']) ?></h2>
                        <p><?= $escape(implode(' · ', array_filter([$character['species'], $character['occupation'], $character['affiliation']], static fn (string $text): bool => $text !== ''))) ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($result['pages'] > 1): ?>
        <nav class="world-actions" aria-label="캐릭터 목록 페이지">
            <?php if ($result['page'] > 1): ?>
                <a class="button button--outline" href="<?= $escape(loreverseUrl('characters.php', $filters + ['page' => $result['page'] - 1])) ?>">이전</a>
            <?php endif; ?>
            <span><?= $result['page'] ?> / <?= $result['pages'] ?></span>
            <?php if ($result['page'] < $result['pages']): ?>
                <a class="button button--outline" href="<?= $escape(loreverseUrl('characters.php', $filters + ['page' => $result['page'] + 1])) ?>">다음</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
<?php loreverseWorldStudioEnd(); ?>
