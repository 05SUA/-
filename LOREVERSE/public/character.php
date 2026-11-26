<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/character-ui.php';

$user = loreverseStudioUser(loreverseUrl('character.php', $_GET));
$tabs = [
    'profile' => ['프로필', ['age', 'gender', 'species', 'occupation', 'affiliation', 'appearance']],
    'personality' => ['성격', ['personality', 'values', 'strengths', 'weaknesses', 'speech_style', 'voice_style', 'habits']],
    'story' => ['서사', ['background', 'goal', 'secret']],
    'abilities' => ['능력', ['abilities', 'limitations']],
    'relations' => ['관계', []],
];
$tab = $_GET['tab'] ?? 'profile';
$tab = is_string($tab) && isset($tabs[$tab]) ? $tab : 'profile';
$failure = null;
try {
    $id = loreverseCharacterId($_GET['id'] ?? null);
    $pdo = loreverseDatabase(loreverseWebConfig());
    $characters = new CharacterService($pdo, $user);
    $character = $characters->get($id);
    $world = $character['world_id'] === null ? null : ['id' => $character['world_id'], 'name' => $character['world_name']];
    $return = loreverseUrl('character.php', ['id' => $id, 'tab' => $tab]);
    $list = loreverseUrl('characters.php', $world === null ? [] : ['world_id' => $world['id']]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $body = loreverseStudioForm();
            if (($body['action'] ?? null) === 'duplicate') {
                $copy = $characters->duplicate($id);
                loreverseRedirect(loreverseUrl('character.php', ['id' => $copy['id']]));
            }
            if (($body['action'] ?? null) !== 'delete' || ($body['confirmed'] ?? null) !== '1') {
                throw new LoreverseWorldError('CONFIRMATION_REQUIRED', '삭제할 캐릭터를 확인해 주세요.');
            }
            loreverseRemoveCharacterImage($characters->delete($id));
            loreverseRedirect($list);
        } catch (Throwable $error) {
            $failure = loreverseWorldFailure($error);
            http_response_code($failure->status);
        }
    }
    $relationships = [];
    if ($tab === 'relations' && $world !== null) {
        $query = $pdo->prepare('SELECT peer.id, peer.name, r.relationship_type, r.custom_type,
            r.from_character_id, r.description FROM relationships r
            JOIN characters peer ON peer.id = CASE WHEN r.from_character_id = ? THEN r.to_character_id ELSE r.from_character_id END
            WHERE r.world_id = ? AND peer.user_id = ? AND (r.from_character_id = ? OR r.to_character_id = ?) ORDER BY r.id');
        $query->execute([$id, $world['id'], $user, $id, $id]);
        $relationships = $query->fetchAll();
    }
} catch (Throwable $error) {
    loreverseCharacterPageError($error);
}
$escape = 'loreverseEscape';
loreverseWorldStudioStart($character['name'], 'characters', [], $world);
?>
<div class="world-stack">
    <header class="world-heading">
        <p class="display-kicker" lang="en">CHARACTER PROFILE</p>
        <h1 class="section-title"><?= $escape($character['name']) ?></h1>
        <p><?= $escape($character['world_name'] ?? '독립 캐릭터') ?></p>
    </header>
    <?php if ($failure !== null): ?><p class="form-message" role="alert"><?= $escape($failure->getMessage()) ?></p><?php endif; ?>
    <div class="world-actions">
        <a class="button button--outline" href="<?= $escape($list) ?>">캐릭터 목록</a>
        <a class="button button--primary" href="<?= $escape(loreverseUrl('character-create.php', ['id' => $id])) ?>">편집</a>
        <a class="button button--outline" href="<?= $escape(loreverseChatUrl(['character_id' => $id])) ?>">대화 시작</a>
        <form method="post" action="<?= $escape($return) ?>">
            <input type="hidden" name="_csrf" value="<?= $escape(loreverseCsrf()) ?>">
            <button type="submit" name="action" value="duplicate" class="button button--outline">설정 복제</button>
        </form>
        <?php if (function_exists('loreverseFavoriteButton')): ?>
            <?php loreverseFavoriteButton($pdo, $user, 'character', $id, $return); ?>
        <?php endif; ?>
    </div>
    <div class="world-details-grid">
        <section class="surface world-panel" aria-label="캐릭터 이미지">
            <img class="world-cover-preview" src="<?= $escape(loreverseCharacterImageUrl($character)) ?>"
                 alt="<?= $escape($character['name']) ?> 프로필" width="300" height="400">
        </section>
        <section class="surface world-panel world-stack">
            <nav class="world-actions" aria-label="캐릭터 프로필 항목">
                <?php foreach ($tabs as $key => [$label]): ?>
                    <a class="button <?= $tab === $key ? 'button--primary' : 'button--outline' ?>"
                       <?= $tab === $key ? 'aria-current="page"' : '' ?>
                       href="<?= $escape(loreverseUrl('character.php', ['id' => $id, 'tab' => $key])) ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </nav>
            <h2><?= $tabs[$tab][0] ?></h2>
            <?php if ($tab === 'relations'): ?>
                <?php if ($relationships === []): ?><p class="world-muted">기록된 관계가 없어요.</p><?php endif; ?>
                <?php foreach ($relationships as $relationship): ?>
                    <article>
                        <h3><a href="<?= $escape(loreverseUrl('character.php', ['id' => $relationship['id']])) ?>"><?= $escape($relationship['name']) ?></a></h3>
                        <p><?= (int) $relationship['from_character_id'] === $id ? '이 캐릭터에서' : '이 캐릭터에게' ?> ·
                            <?= $escape($relationship['custom_type'] ?: $relationship['relationship_type']) ?></p>
                        <?php loreverseCharacterValue('관계 설명', $relationship['description']); ?>
                    </article>
                <?php endforeach; ?>
                <?php if ($world !== null && is_file(__DIR__ . '/relationships.php')): ?>
                    <a class="button button--outline" href="<?= $escape(loreverseUrl('relationships.php', ['world_id' => $world['id']])) ?>">세계관 관계도</a>
                <?php endif; ?>
            <?php else: ?>
                <?php foreach ($tabs[$tab][1] as $field): ?>
                    <?php if ($field === 'appearance'): ?>
                        <?php foreach ($character['appearance'] as $part => $value): ?>
                            <?php loreverseCharacterValue(CHARACTER_LABELS[$part], $value); ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php loreverseCharacterValue(CHARACTER_LABELS[$field], $character[$field]); ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
    <form method="post" action="<?= $escape($return) ?>" class="surface world-panel world-stack">
        <h2>캐릭터 삭제</h2>
        <p>삭제하면 연결된 관계와 대화가 함께 사라집니다. 진행 중인 스토리에 등장하는 캐릭터는 삭제할 수 없어요.</p>
        <input type="hidden" name="_csrf" value="<?= $escape(loreverseCsrf()) ?>">
        <label><input type="checkbox" name="confirmed" value="1" required> 이 캐릭터 삭제를 확인했어요.</label>
        <button type="submit" name="action" value="delete" class="button button--outline world-delete">삭제</button>
    </form>
</div>
<?php loreverseWorldStudioEnd(); ?>
