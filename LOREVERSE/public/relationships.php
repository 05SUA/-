<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/RelationshipService.php';

$user = loreverseStudioUser(loreverseUrl('relationships.php', $_GET));
$failure = $proposal = null;
$form = [];
try {
    $pdo = loreverseDatabase(loreverseWebConfig());
    $worldId = loreverseWorldId($_GET['world_id'] ?? null);
    $world = (new WorldService($pdo, $user))->get($worldId);
    $service = new RelationshipService($pdo, $user);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $form = ['world_id' => $worldId] + loreverseStudioForm();
            $action = $form['action'] ?? '';
            if ($action === 'generate') {
                $proposal = $service->generate($form);
                $form = array_replace($form, $proposal);
            } elseif ($action === 'save') {
                $relation = $service->save($form);
                loreverseRedirect(loreverseUrl('relationships.php', ['world_id' => $worldId, 'id' => $relation['id']]));
            } elseif ($action === 'delete') {
                $service->delete($form);
                loreverseRedirect(loreverseUrl('relationships.php', ['world_id' => $worldId]));
            } else {
                throw new LoreverseWorldError('BAD_REQUEST', '관계 작업을 선택해 주세요.');
            }
        } catch (Throwable $error) {
            $failure = loreverseWorldFailure($error);
            http_response_code($failure->status);
        }
    }
    $data = $service->listing($worldId);
} catch (Throwable $error) {
    loreverseWorldPageError($error);
}

$characters = array_column($data['characters'], null, 'id');
$relations = array_column($data['relationships'], null, 'id');
if ($form === []) {
    $selected = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $form = $relations[$selected] ?? [];
}
$formValue = static fn (string $field, string $default = ''): string =>
    is_scalar($form[$field] ?? null) ? (string) $form[$field] : $default;
$escape = 'loreverseEscape';
$url = loreverseUrl('relationships.php', ['world_id' => $worldId]);
$colors = ['lover' => '#F08BB3', 'crush' => '#F08BB3', 'rival' => '#E39B67', 'friend' => '#77C6A4',
    'colleague' => '#78B9E9', 'hostile' => '#E96D79', 'family' => '#B9ABFF'];
$positions = [];
$radius = max(105, count($characters) * 15);
$center = $radius + 48;
foreach (array_keys($characters) as $index => $character) {
    $angle = 2 * M_PI * $index / count($characters) - M_PI / 2;
    $positions[$character] = [$center + cos($angle) * $radius, $center + sin($angle) * $radius];
}
loreverseWorldStudioStart('관계도', 'relationships', [], $world);
?>
<header class="world-heading">
    <h1 class="section-title">관계도</h1>
    <p>한 캐릭터가 상대를 어떻게 생각하는지 방향별로 기록하세요.</p>
</header>
<?php if (count($characters) < 2): ?>
    <section class="surface world-panel">
        <p>관계를 만들려면 이 세계관에 캐릭터 두 명이 필요해요.</p>
        <a class="button button--primary" href="<?= $escape(loreverseUrl('character-create.php', ['world_id' => $worldId])) ?>">캐릭터 만들기</a>
    </section>
<?php else: ?>
    <div class="studio-feature-grid">
        <section class="surface world-panel">
            <h2>캐릭터 연결</h2>
            <svg class="relationship-graph" viewBox="0 0 <?= 2 * $center ?> <?= 2 * $center ?>" role="img" aria-labelledby="relations-title">
                <title id="relations-title"><?= $escape($world['name']) ?>의 방향성 관계도</title>
                <defs><marker id="relation-arrow" markerWidth="7" markerHeight="7" refX="6" refY="3.5" orient="auto">
                    <path d="M 0 0 L 7 3.5 L 0 7 z" fill="#B9ABFF"/>
                </marker></defs>
                <?php foreach ($relations as $relation):
                    [$x1, $y1] = $positions[$relation['from_character_id']];
                    [$x2, $y2] = $positions[$relation['to_character_id']];
                    $length = hypot($x2 - $x1, $y2 - $y1);
                    if ($length === 0.0) {
                        continue;
                    }
                    $dx = ($x2 - $x1) / $length;
                    $dy = ($y2 - $y1) / $length;
                    $type = $relation['custom_type'] ?: (RelationshipService::TYPES[$relation['relationship_type']] ?? '관계');
                    $color = $colors[$relation['relationship_type']] ?? '#8B80C3';
                    ?>
                    <line x1="<?= $x1 + 25 * $dx - 5 * $dy ?>" y1="<?= $y1 + 25 * $dy + 5 * $dx ?>"
                          x2="<?= $x2 - 27 * $dx - 5 * $dy ?>" y2="<?= $y2 - 27 * $dy + 5 * $dx ?>"
                          stroke="<?= $color ?>" stroke-width="2" marker-end="url(#relation-arrow)">
                        <title><?= $escape($characters[$relation['from_character_id']]['name'] . ' → ' . $characters[$relation['to_character_id']]['name'] . ' ' . $type) ?></title>
                    </line>
                <?php endforeach; ?>
                <?php foreach ($positions as $character => [$x, $y]): ?>
                    <circle cx="<?= $x ?>" cy="<?= $y ?>" r="24" fill="#211C38" stroke="#8370F4"/>
                    <text x="<?= $x ?>" y="<?= $y + 4 ?>" text-anchor="middle" fill="#F4F3FA" font-size="9">
                        <?= $escape(mb_strimwidth($characters[$character]['name'], 0, 10, '…', 'UTF-8')) ?>
                    </text>
                <?php endforeach; ?>
            </svg>
            <ul class="studio-record-list">
                <?php foreach ($relations as $relation):
                    $type = $relation['custom_type'] ?: (RelationshipService::TYPES[$relation['relationship_type']] ?? '관계'); ?>
                    <li>
                        <a href="<?= $escape(loreverseUrl('relationships.php', ['world_id' => $worldId, 'id' => $relation['id']])) ?>">
                            <?= $escape($characters[$relation['from_character_id']]['name'] . ' → ' . $characters[$relation['to_character_id']]['name']) ?>
                        </a>
                        <span><?= $escape($type) ?> · 친밀도 <?= (int) $relation['intimacy'] ?> · 신뢰도 <?= (int) $relation['trust'] ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($relations === []): ?><p>저장한 관계가 없어요.</p><?php endif; ?>
        </section>
        <section class="surface world-panel">
            <h2><?= $formValue('id') !== '' ? '관계 수정' : '관계 추가' ?></h2>
            <?php if ($failure !== null): ?><p class="form-message" role="alert"><?= $escape($failure->getMessage()) ?></p><?php endif; ?>
            <?php if ($proposal !== null): ?><p role="status">Gemini가 제안한 내용을 검토한 뒤 저장하세요.</p><?php endif; ?>
            <form method="post" action="<?= $escape($url) ?>" class="world-stack">
                <input type="hidden" name="_csrf" value="<?= $escape(loreverseCsrf()) ?>">
                <input type="hidden" name="id" value="<?= $escape($formValue('id')) ?>">
                <input type="hidden" name="request_id" value="<?= loreverseStudioRequestId() ?>">
                <?php foreach (['from_character_id' => '보낸 캐릭터', 'to_character_id' => '받는 캐릭터'] as $field => $label): ?>
                    <label class="field"><?= $label ?>
                        <select class="field-control" name="<?= $field ?>" required>
                            <option value="">선택하세요</option>
                            <?php foreach ($characters as $id => $character): ?>
                                <option value="<?= (int) $id ?>" <?= $formValue($field) === (string) $id ? 'selected' : '' ?>><?= $escape($character['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
                <label class="field">관계 유형<select class="field-control" name="relationship_type">
                    <?php foreach (RelationshipService::TYPES as $id => $label): ?>
                        <option value="<?= $id ?>" <?= $formValue('relationship_type', 'friend') === $id ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label class="field">직접 입력한 관계 이름<input class="field-control" name="custom_type" maxlength="100" value="<?= $escape($formValue('custom_type')) ?>"></label>
                <div class="studio-field-grid">
                    <?php foreach (['intimacy' => '친밀도', 'trust' => '신뢰도'] as $field => $label): ?>
                        <label class="field"><?= $label ?><input class="field-control" type="number" name="<?= $field ?>" min="0" max="100" required value="<?= $escape($formValue($field, '50')) ?>"></label>
                    <?php endforeach; ?>
                </div>
                <label class="field">관계 서사<textarea class="field-control" name="description" maxlength="3000" rows="3"><?= $escape($formValue('description')) ?></textarea></label>
                <label class="field">첫 만남<textarea class="field-control" name="first_meeting" maxlength="2000" rows="2"><?= $escape($formValue('first_meeting')) ?></textarea></label>
                <div class="world-actions">
                    <button class="button button--primary" name="action" value="save">관계 저장</button>
                    <button class="button button--outline" name="action" value="generate">Gemini 관계 서사 제안</button>
                    <?php if ($formValue('id') !== ''): ?><a class="button button--quiet" href="<?= $escape($url) ?>">새 관계</a><?php endif; ?>
                </div>
            </form>
            <?php if ($formValue('id') !== ''): ?>
                <details class="studio-delete">
                    <summary>관계 삭제</summary>
                    <form method="post" action="<?= $escape($url) ?>" class="world-stack">
                        <input type="hidden" name="_csrf" value="<?= $escape(loreverseCsrf()) ?>">
                        <input type="hidden" name="id" value="<?= $escape($formValue('id')) ?>">
                        <label><input type="checkbox" name="confirmed" value="1" required> 이 방향의 관계를 삭제합니다.</label>
                        <button class="button button--outline" name="action" value="delete">삭제 확인</button>
                    </form>
                </details>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
<?php loreverseWorldStudioEnd(); ?>
