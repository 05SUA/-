<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/character-ui.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/CharacterGenerator.php';

$user = loreverseStudioUser(loreverseUrl('character-create.php', $_GET));
$failure = null;
$warnings = [];
$mode = 'manual';
$keywords = '';
$locks = [];
$target = '';
$saved = null;
try {
    $config = loreverseWebConfig();
    $pdo = loreverseDatabase($config);
    $characters = new CharacterService($pdo, $user);
    $editing = isset($_GET['id']) ? $characters->get(loreverseCharacterId($_GET['id'])) : null;
    $data = $editing ?? loreverseCharacterData(['world_id' => $_GET['world_id'] ?? null]);
    $world = $data['world_id'] === null ? null : (new WorldService($pdo, $user))->get($data['world_id']);
    $options = $characters->worldOptions();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $body = loreverseStudioForm();
            $data = loreverseCharacterNativeFields($body);
            $mode = in_array($body['mode'] ?? null, ['manual', 'auto', 'custom'], true) ? $body['mode'] : 'manual';
            $keywords = is_string($body['keywords'] ?? null) ? $body['keywords'] : '';
            $locks = is_array($body['locked_fields'] ?? null) ? $body['locked_fields'] : [];
            $target = is_string($body['target_field'] ?? null) ? $body['target_field'] : '';
            if (($body['action'] ?? null) === 'generate') {
                $draft = loreverseGenerateCharacter($pdo, $user, [
                    'request_id' => $body['request_id'] ?? null, 'mode' => $mode, 'world_id' => $data['world_id'],
                    'fields' => $data, 'keywords' => loreverseCharacterFormList($keywords, 'keywords', 80),
                    'locked_fields' => $locks, 'target_field' => $target !== '' ? $target : null,
                    'current_data' => $target !== '' ? $data : null,
                ], $config);
                $data = $draft['character'];
                $warnings = $draft['warnings'];
            } elseif (($body['action'] ?? null) === 'save') {
                $input = $data;
                if ($editing !== null) {
                    $input += ['id' => $editing['id'], 'expected_updated_at' => $body['expected_updated_at'] ?? ''];
                }
                $saved = $characters->save($input);
                $editing = $saved;
                $data = $saved;
                if (($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    loreverseUploadCharacterImage($pdo, $user, $saved['id'], $_FILES['file']);
                }
                loreverseRedirect(loreverseUrl('character.php', ['id' => $saved['id']]));
            } else {
                throw new LoreverseWorldError('BAD_REQUEST', '실행할 작업을 선택해 주세요.');
            }
        } catch (Throwable $error) {
            $failure = loreverseWorldFailure($error);
            http_response_code($failure->status);
        }
    }
} catch (Throwable $error) {
    loreverseCharacterPageError($error);
}
$escape = 'loreverseEscape';
$title = $editing === null ? '캐릭터 만들기' : '캐릭터 편집';
$return = loreverseUrl('character-create.php', $editing !== null ? ['id' => $editing['id']] : ($world === null ? [] : ['world_id' => $world['id']]));
$visual = loreverseCharacterImageUrl($editing ?? []);
$groups = [
    '기본 설정' => ['name', 'age', 'gender', 'species', 'occupation', 'affiliation', 'appearance'],
    '성격과 말투' => ['personality', 'values', 'strengths', 'weaknesses', 'speech_style', 'voice_style', 'habits'],
    '서사와 능력' => ['background', 'goal', 'secret', 'abilities', 'limitations'],
];
loreverseWorldStudioStart($title, 'characters', ['character-form'], $world);
?>
<div class="world-stack">
    <header class="world-heading">
        <p class="display-kicker" lang="en">CHARACTER CREATOR</p>
        <h1 class="section-title"><?= $title ?></h1>
    </header>
    <?php if ($failure !== null): ?><p class="form-message" role="alert"><?= $escape($failure->getMessage()) ?></p><?php endif; ?>
    <?php if ($saved !== null): ?>
        <p class="form-message" role="status">캐릭터 설정을 저장했어요. <a href="<?= $escape(loreverseUrl('character.php', ['id' => $saved['id']])) ?>">프로필 보기</a></p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= $escape($return) ?>" class="world-stack" data-character-form novalidate>
        <input type="hidden" name="_csrf" value="<?= $escape(loreverseCsrf()) ?>">
        <input type="hidden" name="request_id" value="<?= $escape(loreverseStudioRequestId()) ?>">
        <?php if ($editing !== null): ?>
            <input type="hidden" name="expected_updated_at" value="<?= $escape($editing['updated_at']) ?>">
        <?php endif; ?>
        <div class="world-details-grid">
            <section class="surface world-panel world-stack">
                <h2>프로필 이미지</h2>
                <img class="world-cover-preview" src="<?= $escape($visual) ?>" data-character-preview
                     data-original="<?= $escape($visual) ?>" alt="캐릭터 이미지 미리보기" width="300" height="400">
                <label class="field">이미지 선택
                    <input type="file" class="field-control" name="file" accept="image/jpeg,image/png,image/webp" data-character-file>
                    <span class="field-help">JPG, PNG, WebP · 2MB 및 4096×4096 이하. AI 초안을 받은 뒤 이미지를 선택하세요.</span>
                </label>
            </section>
            <section class="surface world-panel world-stack">
                <h2>생성 방식</h2>
                <label class="field">세계관
                    <select name="world_id" class="field-control">
                        <option value="">독립 캐릭터</option>
                        <?php foreach ($options as $option): ?>
                            <option value="<?= $option['id'] ?>" <?= $data['world_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                                <?= $escape($option['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">방식
                    <select name="mode" class="field-control">
                        <?php foreach (['manual' => '직접 작성', 'auto' => 'AI 자동 생성', 'custom' => 'AI 맞춤 생성'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $mode === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">AI 키워드
                    <textarea name="keywords" rows="3" maxlength="810" class="field-control"><?= $escape($keywords) ?></textarea>
                    <span class="field-help">자동 생성 시 키워드를 한 줄에 하나씩 입력하세요. 맞춤 생성은 입력한 설정을 유지합니다.</span>
                </label>
                <label class="field">AI 생성 범위
                    <select name="target_field" class="field-control">
                        <option value="">전체 초안</option>
                        <?php foreach (loreverseCharacterDraftFields() as $field): ?>
                            <option value="<?= $field ?>" <?= $target === $field ? 'selected' : '' ?>><?= $escape(CHARACTER_LABELS[$field]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="world-muted">AI 생성 시 입력과 소속 세계관의 창작 설정이 Gemini로 전송됩니다.</p>
                <button type="submit" class="button button--outline" name="action" value="generate">Gemini로 캐릭터 초안 생성</button>
                <?php if ($warnings !== []): ?>
                    <ul class="world-warnings" aria-label="세계관 설정 확인">
                        <?php foreach ($warnings as $warning): ?><li><?= $escape($warning) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
        <?php foreach ($groups as $section => $fields): ?>
            <section class="surface world-panel world-stack">
                <h2><?= $section ?></h2>
                <div class="world-details-grid">
                    <?php foreach ($fields as $field): ?>
                        <div class="world-stack">
                            <?php if ($field === 'appearance'): ?>
                                <fieldset>
                                    <legend>외형</legend>
                                    <?php foreach (CHARACTER_APPEARANCE_FIELDS as $part): ?>
                                        <label class="field"><?= $escape(CHARACTER_LABELS[$part]) ?>
                                            <input class="field-control" name="appearance[<?= $part ?>]" maxlength="500"
                                                   value="<?= $escape($data['appearance'][$part]) ?>">
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            <?php else: ?>
                                <label class="field"><?= $escape(CHARACTER_LABELS[$field]) ?>
                                    <?php if (in_array($field, CHARACTER_LIST_FIELDS, true)): ?>
                                        <textarea class="field-control" name="<?= $field ?>" rows="3" maxlength="5010"><?= $escape(implode("\n", $data[$field])) ?></textarea>
                                        <span class="field-help">한 줄에 하나씩, 최대 10개</span>
                                    <?php elseif (CHARACTER_TEXT_LIMITS[$field] > 100): ?>
                                        <textarea class="field-control" name="<?= $field ?>" rows="3" maxlength="<?= CHARACTER_TEXT_LIMITS[$field] ?>"><?= $escape($data[$field]) ?></textarea>
                                    <?php else: ?>
                                        <input class="field-control" name="<?= $field ?>" maxlength="<?= CHARACTER_TEXT_LIMITS[$field] ?>"
                                               value="<?= $escape($data[$field]) ?>" <?= $field === 'name' ? 'required' : '' ?>>
                                    <?php endif; ?>
                                </label>
                            <?php endif; ?>
                            <?php loreverseCharacterLock($field, $locks); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
        <div class="world-actions">
            <button type="submit" class="button button--primary" name="action" value="save">캐릭터 저장</button>
            <a class="button button--outline" href="<?= $escape(loreverseUrl('characters.php', $world === null ? [] : ['world_id' => $world['id']])) ?>">캐릭터 목록</a>
        </div>
    </form>
</div>
<?php loreverseWorldStudioEnd(); ?>
