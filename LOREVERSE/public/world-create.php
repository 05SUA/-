<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/world-ui.php';
loreverseWebStart();
$posting = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($posting && !isset($_SESSION['user_id'])) {
    loreverseRedirect(loreverseUrl('login.php', ['next' => loreverseUrl('world-create.php')]));
}
$user = $posting ? (int) $_SESSION['user_id'] : loreversePageUser(loreverseUrl('world-create.php', $_GET));
$initial = null;
if (!$posting && isset($_GET['id'])) {
    try {
        $initial = (new WorldService(loreverseDatabase(loreverseWebConfig()), $user))->get(loreverseWorldId($_GET['id']));
    } catch (Throwable $error) {
        loreverseWorldPageError($error);
    }
}
$genre = $initial['genre'] ?? ($posting ? ($_POST['genre'] ?? '') : ($_GET['genre'] ?? ''));
$genre = in_array($genre, LOREVERSE_GENRES, true) ? $genre : '';
$csrf = loreverseCsrf();
$idea = '';
$features = '';
$customGenre = '';
$suggestion = null;
$genreError = null;
if ($posting) {
    require_once __DIR__ . '/includes/world-api.php';
    try {
        $submittedCsrf = $_POST['_csrf'] ?? null;
        if (!is_string($submittedCsrf) || !hash_equals($csrf, $submittedCsrf)) {
            throw new LoreverseWorldError('CSRF_INVALID', '페이지를 새로고침해 주세요.', 403);
        }
        $idea = loreverseWorldText($_POST['idea'] ?? '', WORLD_TEXT_LIMITS['features'], 'features', true);
        $customGenre = loreverseWorldText($_POST['custom_genre'] ?? '', WORLD_TEXT_LIMITS['custom_genre'], 'custom_genre');
        $action = $_POST['action'] ?? '';
        if ($action === 'select' && isset(loreverseGenres()[$genre])) {
            $features = $idea;
        } elseif ($action === 'recommend') {
            if (!function_exists('loreverseRecommendWorldGenre') || !method_exists(GeminiService::class, 'embedForSimilarity')) {
                throw new LoreverseWorldError('GENRE_UPDATE_REQUIRED', '서버에 장르 추천 업데이트를 적용해 주세요.', 503);
            }
            session_write_close();
            $config = loreverseWebConfig();
            $suggestion = loreverseRecommendWorldGenre(loreverseDatabase($config), $user, $_POST, $config, loreverseGenres());
        } else {
            throw new LoreverseWorldError('VALIDATION_ERROR', '추천 장르를 확인해 주세요.');
        }
    } catch (Throwable $error) {
        $genreError = loreverseWorldFailure($error);
        http_response_code($genreError->status);
    }
}
$escape = 'loreverseEscape';
$title = $initial === null ? '세계관 만들기' : '세계관 수정';
$steps = ['장르', '기본 설정', '세부 설정', 'AI 생성', '검토·저장'];
$review = [
    'name' => ['이름', 100], 'era' => ['시대', 100], 'mood' => ['분위기', 100],
    'description' => ['소개', 2000], 'history' => ['역사', 5000],
    'regions' => ['주요 지역', 0], 'factions' => ['주요 세력', 0], 'rules' => ['세계 규칙', 0], 'conflicts' => ['핵심 갈등', 0],
];
loreverseWorldStudioStart($title, $initial === null ? 'create' : 'settings', ['world-api', 'world-wizard'], $initial);
?>
<header class="world-heading">
    <p class="display-kicker" lang="en">WORLD BUILDER</p>
    <h1 class="section-title"><?= $escape($title) ?></h1>
    <p>세계의 규칙과 이야기를 정하고, 검토한 설정을 저장하세요.</p>
</header>
<?php if ($initial === null): ?>
    <form id="genre-recommendation-form" method="post" action="<?= $escape(loreverseUrl('world-create.php')) ?>" hidden>
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
        <input type="hidden" name="request_id" value="<?= vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)) ?>">
    </form>
    <?php if ($suggestion !== null): ?>
        <form id="genre-selection-form" method="post" action="<?= $escape(loreverseUrl('world-create.php')) ?>" hidden>
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
            <input type="hidden" name="idea" value="<?= $escape($idea) ?>">
            <input type="hidden" name="genre" value="<?= $escape($suggestion['genre']) ?>">
        </form>
    <?php endif; ?>
<?php endif; ?>
<form class="world-stack" data-world-wizard
      data-api-base="<?= $escape(loreverseUrl('api/')) ?>" data-world-url="<?= $escape(loreverseUrl('world.php')) ?>"
      data-csrf="<?= $escape(loreverseCsrf()) ?>" novalidate>
    <script type="application/json" data-world-initial><?= json_encode(
        $initial, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    ) ?></script>
    <ol class="world-steps" aria-label="세계관 만들기 순서">
        <?php foreach ($steps as $index => $step): ?>
            <li data-step-label><span aria-hidden="true"><?= $index + 1 ?></span><?= $step ?></li>
        <?php endforeach; ?>
    </ol>
    <p class="form-message world-notice" data-world-message role="status" tabindex="-1" hidden></p>
    <noscript><p class="form-message">이 화면에서 세계관을 편집하려면 JavaScript를 켜 주세요.</p></noscript>
    <section class="surface world-panel" data-world-step="0" <?= $initial === null ? '' : 'hidden' ?>>
        <h2 tabindex="-1">어떤 세계를 만들까요?</h2>
        <?php if ($initial === null): ?>
            <?php if ($genreError !== null): ?>
                <p class="form-message" role="alert"><?= $escape($genreError->getMessage()) ?></p>
            <?php endif; ?>
            <label class="field">세계관 아이디어
                <textarea class="field-control" name="idea" form="genre-recommendation-form" maxlength="2000" rows="2"
                          placeholder="예: 떠다니는 섬을 오가는 마법 우체부" required><?= $escape($idea) ?></textarea>
                <span class="field-help">추천을 요청하면 아이디어가 Gemini로 전송됩니다.</span>
            </label>
            <div class="world-actions">
                <button type="submit" name="action" value="recommend" form="genre-recommendation-form"
                        class="button button--outline">AI 장르 추천</button>
            </div>
            <?php if ($suggestion !== null): ?>
                <div class="world-actions">
                    <p role="status">추천 장르 <strong><?= $escape($suggestion['label']) ?></strong></p>
                    <button type="submit" name="action" value="select" form="genre-selection-form"
                            class="button button--primary">이 장르 선택</button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <fieldset class="world-genre-picker">
            <legend>장르를 선택하세요.</legend>
            <?php foreach (LOREVERSE_GENRES as $choice): ?>
                <label class="world-genre-option">
                    <input type="radio" name="genre" value="<?= $escape($choice) ?>" <?= $genre === $choice ? 'checked' : '' ?>
                           <?= $initial === null ? 'form="genre-recommendation-form"' : '' ?>>
                    <span><?= $escape(loreverseWorldGenreName($choice)) ?></span>
                    <?php if (isset(loreverseGenres()[$choice])): ?><small><?= $escape(loreverseGenres()[$choice][2]) ?></small><?php endif; ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <label class="field" data-custom-genre hidden>직접 입력 장르
            <input class="field-control" data-seed="custom_genre" maxlength="100" placeholder="내 세계에 어울리는 장르"
                   <?= $initial === null ? 'name="custom_genre" form="genre-recommendation-form"' : '' ?> value="<?= $escape($customGenre) ?>">
        </label>
    </section>
    <section class="surface world-panel" data-world-step="1" hidden>
        <h2 tabindex="-1">기본 설정</h2>
        <p>입력한 이름, 시대, 분위기는 AI가 바꾸지 않아요. 빈 항목은 초안 생성을 맡길 수 있어요.</p>
        <?php foreach (['name' => '세계관 이름', 'era' => '시대', 'mood' => '분위기'] as $field => $label): ?>
            <label class="field"><?= $label ?><input class="field-control" data-seed="<?= $field ?>" maxlength="100"></label>
        <?php endforeach; ?>
    </section>
    <section class="surface world-panel" data-world-step="2" hidden>
        <h2 tabindex="-1">세부 설정</h2>
        <label class="field">특징<textarea class="field-control" data-seed="features" maxlength="2000" rows="3"><?= $escape($features) ?></textarea></label>
        <label class="field">주요 세력 힌트<textarea class="field-control" data-seed="faction_hints" maxlength="1000" rows="3"></textarea></label>
        <?php loreverseWorldEntryEditor('keywords', '키워드'); ?>
    </section>
    <section class="surface world-panel" data-world-step="3" hidden>
        <h2 tabindex="-1">세계관 초안 만들기</h2>
        <p>Gemini가 제공한 초안을 검토하고 원하는 설정만 저장할 수 있어요.</p>
        <p class="world-muted">창작 입력은 Gemini에 전송됩니다. 개인정보와 비밀번호는 입력하지 마세요. AI 사용 한도는 UTC 자정에 갱신돼요.</p>
        <div class="world-actions">
            <button type="button" class="button button--primary" data-generate-world>Gemini로 초안 생성</button>
            <button type="button" class="button button--outline" data-start-manual>직접 작성</button>
        </div>
    </section>
    <section class="surface world-panel" data-world-step="4" hidden>
        <h2 tabindex="-1">검토하고 저장하기</h2>
        <p>초안을 편집하고 바꾸고 싶지 않은 항목을 고정하세요. 저장하면 설정이 DB에 기록됩니다.</p>
        <div class="world-actions">
            <button class="button button--outline" type="button" data-regenerate-all>전체 초안 다시 생성</button>
        </div>
        <ul class="world-warnings" data-draft-warnings hidden></ul>
        <?php foreach ($review as $field => [$label, $length]): ?>
            <div class="world-review-field" data-review-field="<?= $field ?>">
                <?php if ($length === 0): ?>
                    <?php loreverseWorldEntryEditor($field, $label, in_array($field, ['regions', 'factions'], true)); ?>
                <?php else: ?>
                    <label class="field"><?= $label ?>
                        <?php if ($length > 100): ?>
                            <textarea class="field-control" data-draft="<?= $field ?>" maxlength="<?= $length ?>" rows="4"></textarea>
                        <?php else: ?>
                            <input class="field-control" data-draft="<?= $field ?>" maxlength="<?= $length ?>">
                        <?php endif; ?>
                    </label>
                <?php endif; ?>
                <div class="world-actions">
                    <label class="button button--quiet"><input type="checkbox" data-lock="<?= $field ?>" aria-label="<?= $label ?> 고정">고정</label>
                    <button class="button button--outline" type="button" data-regenerate="<?= $field ?>" aria-label="<?= $label ?> 재생성">이 항목 재생성</button>
                </div>
            </div>
        <?php endforeach; ?>
        <label class="field">커버 이미지 (선택)
            <input class="field-control" type="file" data-world-cover accept="image/jpeg,image/png,image/webp">
            <span class="field-help">JPG, PNG, WebP · 2MB 및 4096×4096 이하</span>
        </label>
        <img class="world-cover-preview" data-cover-preview alt="선택한 커버 미리보기" hidden>
        <div class="world-actions">
            <button type="button" data-save-world class="button button--primary">세계관 저장</button>
            <a class="button button--outline" data-saved-link href="#" hidden>저장한 세계관 보기</a>
        </div>
    </section>
    <div class="world-actions world-step-actions">
        <button class="button button--outline" type="button" data-prev-step hidden>이전</button>
        <button class="button button--primary" type="button" data-next-step hidden>다음</button>
        <button class="button button--outline" type="button" data-cancel-ai hidden>응답 대기 취소</button>
    </div>
</form>
<?php loreverseWorldStudioEnd(); ?>
