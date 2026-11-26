<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/TimelineService.php';

$user = loreverseStudioUser(loreverseUrl('timeline.php', $_GET));
$failure = null;
$entry = [];
try {
    $pdo = loreverseDatabase(loreverseWebConfig());
    $worldId = loreverseWorldId($_GET['world_id'] ?? null);
    $world = (new WorldService($pdo, $user))->get($worldId);
    $service = new TimelineService($pdo, $user);
    $params = ['world_id' => $worldId];
    if (is_string($_GET['type'] ?? null) && isset(TimelineService::TYPES[$_GET['type']])) {
        $params['type'] = $_GET['type'];
    }
    $url = loreverseUrl('timeline.php', $params);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $entry = $params + loreverseStudioForm();
            if (($entry['action'] ?? '') === 'delete') {
                $service->delete($entry);
            } elseif (($entry['action'] ?? '') === 'save') {
                $service->save($entry);
            } else {
                throw new LoreverseWorldError('BAD_REQUEST', '타임라인 작업을 선택해 주세요.');
            }
            loreverseRedirect($url);
        } catch (Throwable $error) {
            $failure = loreverseWorldFailure($error);
            http_response_code($failure->status);
        }
    } elseif (isset($_GET['id'])) {
        $entry = $service->get(loreverseWorldId($_GET['id']));
        if ((int) $entry['world_id'] !== $worldId) {
            throw new LoreverseWorldError('EVENT_NOT_FOUND', '타임라인 사건을 찾을 수 없어요.', 404);
        }
    }
    $data = $service->listing($worldId, $params + ['page' => $_GET['page'] ?? 1]);
    $characters = $service->characters($worldId);
} catch (Throwable $error) {
    loreverseWorldPageError($error);
}
$escape = 'loreverseEscape';
$value = static fn (string $key, string $initial = ''): string =>
    is_scalar($entry[$key] ?? null) ? (string) $entry[$key] : $initial;
$selected = is_array($entry['character_ids'] ?? null) ? array_map('strval', array_filter($entry['character_ids'], 'is_scalar')) : [];
loreverseWorldStudioStart('타임라인', 'timeline', [], $world);
?>
<header class="world-heading">
    <h1 class="section-title">타임라인</h1>
    <p>세계의 사건을 가상 날짜와 순서로 기록하세요.</p>
</header>
<div class="studio-feature-grid">
    <section class="surface world-panel">
        <h2>사건 기록</h2>
        <form method="get" action="<?= $escape(loreverseUrl('timeline.php')) ?>" class="world-actions">
            <input type="hidden" name="world_id" value="<?= $worldId ?>">
            <label class="field">유형<select class="field-control" name="type">
                <option value="">전체</option>
                <?php foreach (TimelineService::TYPES as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($params['type'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select></label>
            <button class="button button--outline">필터 적용</button>
        </form>
        <?php if ($data['items'] === []): ?><p>기록된 사건이 없어요.</p><?php endif; ?>
        <ol class="studio-timeline">
            <?php foreach ($data['items'] as $event): ?>
                <li>
                    <p class="world-muted"><?= $escape($event['date_label']) ?> · <?= $escape(TimelineService::TYPES[$event['event_type']] ?? $event['event_type']) ?><?= $event['source_turn_id'] !== null ? ' · 공식 반영' : '' ?></p>
                    <h3><a href="<?= $escape(loreverseUrl('timeline.php', $params + ['id' => $event['id'], 'page' => $data['page']])) ?>"><?= $escape($event['title']) ?></a></h3>
                    <?php if ($event['description'] !== ''): ?><p class="world-prose"><?= $escape($event['description']) ?></p><?php endif; ?>
                    <?php $names = array_intersect_key($characters, array_flip($event['character_ids']));
                    if ($names !== []): ?><p class="world-muted">관련 인물 <?= $escape(implode(', ', $names)) ?></p><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($data['pages'] > 1): ?>
            <nav aria-label="타임라인 페이지" class="world-actions">
                <?php if ($data['page'] > 1): ?><a class="button button--outline" href="<?= $escape(loreverseUrl('timeline.php', $params + ['page' => $data['page'] - 1])) ?>">이전</a><?php endif; ?>
                <span><?= $data['page'] ?> / <?= $data['pages'] ?></span>
                <?php if ($data['page'] < $data['pages']): ?><a class="button button--outline" href="<?= $escape(loreverseUrl('timeline.php', $params + ['page' => $data['page'] + 1])) ?>">다음</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
    <section class="surface world-panel">
        <h2><?= $value('id') !== '' ? '사건 수정' : '사건 추가' ?></h2>
        <?php if ($failure !== null): ?><p class="form-message" role="alert"><?= $escape($failure->getMessage()) ?></p><?php endif; ?>
        <form method="post" action="<?= $escape($url) ?>" class="world-stack">
            <input type="hidden" name="_csrf" value="<?= $escape(loreverseCsrf()) ?>">
            <input type="hidden" name="id" value="<?= $escape($value('id')) ?>">
            <label class="field">사건 제목<input class="field-control" name="title" maxlength="200" required value="<?= $escape($value('title')) ?>"></label>
            <label class="field">가상 날짜<input class="field-control" name="date_label" maxlength="100" required value="<?= $escape($value('date_label')) ?>"></label>
            <div class="studio-field-grid">
                <label class="field">정렬 순서<input class="field-control" name="sort_order" type="number" required value="<?= $escape($value('sort_order', '0')) ?>"></label>
                <label class="field">유형<select class="field-control" name="event_type">
                    <?php foreach (TimelineService::TYPES as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $value('event_type', 'world') === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select></label>
            </div>
            <label class="field">설명<textarea class="field-control" name="description" maxlength="5000" rows="4"><?= $escape($value('description')) ?></textarea></label>
            <?php if ($characters !== []): ?>
                <fieldset class="studio-choice-list">
                    <legend>관련 인물</legend>
                    <?php foreach ($characters as $id => $name): ?>
                        <label><input type="checkbox" name="character_ids[]" value="<?= (int) $id ?>" <?= in_array((string) $id, $selected, true) ? 'checked' : '' ?>> <?= $escape($name) ?></label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
            <div class="world-actions">
                <button class="button button--primary" name="action" value="save">사건 저장</button>
                <?php if ($value('id') !== ''): ?><a class="button button--outline" href="<?= $escape($url) ?>">새 사건</a><?php endif; ?>
            </div>
        </form>
        <?php if ($value('id') !== ''): ?>
            <details class="studio-delete">
                <summary>사건 삭제</summary>
                <form method="post" action="<?= $escape($url) ?>" class="world-stack">
                    <input type="hidden" name="_csrf" value="<?= $escape(loreverseCsrf()) ?>">
                    <input type="hidden" name="id" value="<?= $escape($value('id')) ?>">
                    <label><input type="checkbox" name="confirmed" value="1" required> 이 사건을 삭제합니다.</label>
                    <button class="button button--outline" name="action" value="delete">삭제 확인</button>
                </form>
            </details>
        <?php endif; ?>
    </section>
</div>
<?php loreverseWorldStudioEnd(); ?>
