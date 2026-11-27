<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/StoryService.php';

$returnUrl = loreverseUrl('story.php', array_intersect_key($_GET, array_flip(['world_id', 'id', 'q', 'status', 'page'])));
$user = loreverseStudioUser($returnUrl);
$escape = 'loreverseEscape';
$status = $_GET['status'] ?? '';
$filter = ['q' => is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100, 'UTF-8') : '',
    'status' => in_array($status, ['active', 'completed'], true) ? $status : ''];
$setupInput = [];
$setupError = null;
$draftReady = false;

try {
    $pdo = loreverseDatabase(loreverseWebConfig());
    $stories = new StoryService($pdo, $user);
    $worldId = isset($_GET['world_id']) ? loreverseWorldId($_GET['world_id']) : null;
    $story = null;
    if (isset($_GET['id'])) {
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if ($id === false) {
            throw new LoreverseWorldError('STORY_NOT_FOUND', '스토리를 찾을 수 없어요.', 404);
        }
        $story = $stories->get($id, $worldId);
        $worldId = (int) $story['world_id'];
    }
    $world = $worldId === null ? null : (new WorldService($pdo, $user))->get($worldId);
    $listing = $story === null ? $stories->list($worldId, $filter + ['page' => $_GET['page'] ?? 1]) : null;
    $cast = $worldId !== null && $story === null ? $stories->cast($worldId) : [];
    if ($story === null && $worldId !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $setupInput = $_POST;
        try {
            $body = loreverseStudioForm();
            switch ($body['action'] ?? null) {
                case 'draft_setup':
                    $setupInput = array_replace($setupInput, $stories->draftSetup($worldId, $body));
                    $draftReady = true;
                    break;
                case 'create':
                    $created = $stories->create(['world_id' => $worldId] + $body);
                    loreverseRedirect(loreverseUrl('story.php', ['id' => $created['id']]));
                default:
                    loreverseWorldInvalid('스토리 요청');
            }
        } catch (Throwable $error) {
            $setupError = loreverseWorldFailure($error);
            http_response_code($setupError->status);
        }
    }
} catch (Throwable $error) {
    $failure = loreverseWorldFailure($error);
    http_response_code($failure->status);
    loreverseWorldStudioStart('스토리 스튜디오', 'stories', []);
    ?>
    <section class="surface world-panel">
        <h1 class="section-title"><?= $failure->status === 404 ? '스토리를 찾을 수 없어요.' : '스토리를 불러올 수 없어요.' ?></h1>
        <a href="<?= $escape(loreverseUrl('story.php')) ?>">스토리 목록으로 돌아가기</a>
    </section>
    <?php
    loreverseWorldStudioEnd();
    exit;
}

$listUrl = loreverseUrl('story.php', $worldId === null ? [] : ['world_id' => $worldId]);
$storyUrl = $story === null ? $listUrl : loreverseUrl('story.php', ['id' => $story['id']]);
$listParameters = $filter + ($worldId === null ? [] : ['world_id' => $worldId]);
$setupValue = static fn (string $key): string => $escape(is_string($setupInput[$key] ?? null) ? $setupInput[$key] : '');
$selectedCast = is_array($setupInput['character_ids'] ?? null) ? $setupInput['character_ids'] : [];
loreverseWorldStudioStart('스토리 스튜디오', 'stories', ['world-api', 'story'], $world);
?>
<div class="world-stack" data-story-studio data-csrf="<?= $escape(loreverseCsrf()) ?>"
     data-api-base="<?= $escape(loreverseUrl('api/')) ?>"
     data-list-url="<?= $escape($listUrl) ?>" data-story-url="<?= $escape($storyUrl) ?>"
     data-world-id="<?= $worldId ?? '' ?>" data-story-id="<?= (int) ($story['id'] ?? 0) ?>"
     data-latest-turn="<?= (int) ($story['latest_turn'] ?? 0) ?>">
    <?php if ($world !== null): ?>
        <header class="world-banner">
            <img src="<?= $escape(loreverseWorldCoverUrl($world)) ?>" alt="" width="512" height="288">
            <div>
                <p class="display-kicker" lang="en">STORY STUDIO</p>
                <h1 class="section-title"><?= $escape($story['title'] ?? '스토리 스튜디오') ?></h1>
                <p><?= $escape($world['name']) ?></p>
            </div>
        </header>
    <?php else: ?>
        <header class="surface world-panel world-welcome">
            <p class="display-kicker" lang="en">STORY STUDIO</p>
            <h1 class="section-title">내 스토리</h1>
            <div class="world-actions">
                <a class="button button--primary" href="<?= $escape(loreverseUrl('workspace.php', ['tab' => 'worlds'])) ?>">세계관에서 스토리 시작</a>
            </div>
        </header>
    <?php endif; ?>
    <p class="state" data-story-message role="status" tabindex="-1" hidden></p>
    <?php if ($story !== null): ?><noscript><p class="state state--error">장면을 진행하려면 JavaScript를 사용해 주세요.</p></noscript><?php endif; ?>
    <?php if ($story === null): ?>
        <section class="surface world-panel" aria-labelledby="story-list-title">
            <h2 id="story-list-title"><?= $world === null ? '전체 스토리' : '이 세계관의 스토리' ?></h2>
            <form class="world-actions" method="get" action="<?= $escape(loreverseUrl('story.php')) ?>">
                <?php if ($worldId !== null): ?><input type="hidden" name="world_id" value="<?= $worldId ?>"><?php endif; ?>
                <label class="field">제목 검색
                    <input class="field-control" type="search" name="q" maxlength="100" value="<?= $escape($filter['q']) ?>">
                </label>
                <label class="field">상태
                    <select class="field-control" name="status">
                        <?php foreach (['' => '전체', 'active' => '진행 중', 'completed' => '종료'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filter['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button button--outline" type="submit">검색</button>
            </form>
            <?php if ($listing['items'] === []): ?>
                <p><?= $filter['q'] !== '' || $filter['status'] !== '' ? '검색 조건에 맞는 스토리가 없어요.' : '아직 저장된 스토리가 없어요.' ?></p>
            <?php else: ?>
                <ul class="world-records">
                    <?php foreach ($listing['items'] as $item): ?>
                        <li class="surface world-panel">
                            <h3><a href="<?= $escape(loreverseUrl('story.php', ['id' => $item['id']])) ?>"><?= $escape($item['title']) ?></a></h3>
                            <p><?= $item['status'] === 'completed' ? '종료' : '진행 중' ?> · 장면 <?= (int) $item['turn_count'] ?>개</p>
                            <?php if ($item['premise'] !== ''): ?><p class="world-prose"><?= $escape($item['premise']) ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($listing['pages'] > 1): ?>
                    <nav class="world-actions" aria-label="스토리 목록 페이지">
                        <?php if ($listing['page'] > 1): ?>
                            <a class="button button--outline" href="<?= $escape(loreverseUrl('story.php', $listParameters + ['page' => $listing['page'] - 1])) ?>">이전</a>
                        <?php endif; ?>
                        <span><?= $listing['page'] ?> / <?= $listing['pages'] ?></span>
                        <?php if ($listing['page'] < $listing['pages']): ?>
                            <a class="button button--outline" href="<?= $escape(loreverseUrl('story.php', $listParameters + ['page' => $listing['page'] + 1])) ?>">다음</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php if ($world !== null): ?>
            <section class="surface world-panel" aria-labelledby="story-create-title">
                <h2 id="story-create-title">새 스토리 설정</h2>
                <?php if ($cast === []): ?>
                    <p>스토리를 시작하려면 이 세계관의 캐릭터가 한 명 이상 필요해요.</p>
                    <div class="world-actions">
                        <a class="button button--outline" href="<?= $escape(loreverseUrl('characters.php', ['world_id' => $worldId])) ?>">캐릭터 관리</a>
                    </div>
                <?php else: ?>
                    <?php if ($setupError !== null): ?><p class="state state--error" role="alert"><?= $escape($setupError->getMessage()) ?></p><?php endif; ?>
                    <?php if ($draftReady): ?><p class="state" role="status">빈 항목을 채웠어요. 원하는 대로 수정하고 스토리를 만들어 보세요.</p><?php endif; ?>
                    <form class="world-stack" action="<?= $escape($listUrl) ?>" method="post" data-story-form="create">
                        <input type="hidden" name="_csrf" value="<?= $escape(loreverseCsrf()) ?>">
                        <input type="hidden" name="request_id" value="<?= $escape(loreverseStudioRequestId()) ?>">
                        <fieldset class="world-genre-picker" aria-describedby="story-cast-help">
                            <legend>등장인물</legend>
                            <?php foreach ($cast as $actor): ?>
                                <label class="world-genre-option">
                                    <input type="checkbox" name="character_ids[]" value="<?= (int) $actor['id'] ?>"
                                           <?= in_array((string) $actor['id'], $selectedCast, true) ? 'checked' : '' ?>>
                                    <span><?= $escape($actor['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="field-help" id="story-cast-help">등장인물을 한 명 이상, 최대 다섯 명까지 선택하세요. 아래의 Gemini 버튼은 세계관과 선택한 인물을 바탕으로 빈 설정만 채워요.</p>
                        <label class="field">스토리 제목
                            <input class="field-control" name="title" maxlength="100" value="<?= $setupValue('title') ?>" required>
                        </label>
                        <label class="field">시작 사건 또는 배경
                            <textarea class="field-control" name="premise" rows="4" maxlength="2000" required><?= $setupValue('premise') ?></textarea>
                        </label>
                        <div class="world-details-grid">
                            <label class="field">장소
                                <input class="field-control" name="location" maxlength="200" value="<?= $setupValue('location') ?>">
                            </label>
                            <label class="field">분위기
                                <input class="field-control" name="mood" maxlength="100" value="<?= $setupValue('mood') ?>">
                            </label>
                        </div>
                        <label class="field">내 역할
                            <textarea class="field-control" name="user_role" rows="2" maxlength="500"><?= $setupValue('user_role') ?></textarea>
                        </label>
                        <div class="world-actions">
                            <button class="button button--primary" type="submit" name="action" value="create">스토리 만들기</button>
                            <button class="button button--outline" type="submit" name="action" value="draft_setup"
                                    formaction="<?= $escape($listUrl . '#story-create-title') ?>" formnovalidate>Gemini로 스토리 설정 채우기</button>
                        </div>
                        <p class="field-help">AI로 설정을 채울 때 작성한 설정과 선택한 인물, 세계관의 창작 자료가 Gemini에 전송돼요.</p>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <div class="world-actions">
            <a class="button button--outline" href="<?= $escape($listUrl) ?>">스토리 목록</a>
            <?php if (function_exists('loreverseFavoriteButton')) {
                loreverseFavoriteButton($pdo, $user, 'story', (int) $story['id'], $storyUrl);
            } ?>
        </div>
        <section class="surface world-panel" aria-labelledby="story-setting-title">
            <h2 id="story-setting-title">스토리 설정</h2>
            <p><?= $story['status'] === 'completed' ? '종료' : '진행 중' ?></p>
            <p class="world-prose"><?= $escape($story['premise']) ?></p>
            <dl class="world-meta">
                <?php foreach (['location' => '장소', 'mood' => '분위기', 'user_role' => '내 역할'] as $key => $label): ?>
                    <?php if ($story[$key] !== ''): ?><div><dt><?= $label ?></dt><dd class="world-prose"><?= $escape($story[$key]) ?></dd></div><?php endif; ?>
                <?php endforeach; ?>
                <div>
                    <dt>등장인물</dt>
                    <dd class="world-prose"><?= $escape(implode(', ', array_map(
                        static fn(array $actor): string => $actor['name'] . (!empty($actor['inactive']) ? ' (비활성)' : ''),
                        $story['cast'],
                    ))) ?></dd>
                </div>
            </dl>
        </section>
        <?php
        $names = array_column($story['cast'], 'name', 'id');
        $turns = $story['turns'];
        $latest = array_pop($turns);
        ?>
        <section class="world-stack" aria-labelledby="story-latest">
            <h2 id="story-latest" tabindex="-1">현재 장면</h2>
            <?php if ($latest === null): ?>
                <p class="world-muted">아직 장면이 없어요.</p>
            <?php else: ?>
                <?php loreverseRenderStoryTurn($latest, $names); ?>
            <?php endif; ?>
        </section>
        <?php if ($story['status'] !== 'completed'): ?>
            <section class="surface world-panel" aria-labelledby="story-advance-title">
                <h2 id="story-advance-title"><?= $latest === null ? '첫 장면 만들기' : '다음 장면으로 이어가기' ?></h2>
                <?php if ($latest === null): ?>
                    <form class="world-actions" action="<?= $escape(loreverseUrl('api/stories/advance.php')) ?>" method="post"
                          data-story-form="advance" data-action-type="start">
                        <button class="button button--primary" type="submit">Gemini로 첫 장면 만들기</button>
                    </form>
                <?php else: ?>
                    <?php if ($latest['choices'] !== []): ?>
                        <form class="world-actions" action="<?= $escape(loreverseUrl('api/stories/advance.php')) ?>" method="post"
                              aria-label="Gemini가 제안한 선택지" data-story-form="advance" data-action-type="choice">
                            <?php foreach ($latest['choices'] as $index => $choice): ?>
                                <button class="button button--outline" type="submit" name="choice_index" value="<?= (int) $index ?>"><?= $escape($choice) ?></button>
                            <?php endforeach; ?>
                        </form>
                    <?php endif; ?>
                    <form class="world-stack" action="<?= $escape(loreverseUrl('api/stories/advance.php')) ?>" method="post"
                          data-story-form="advance" data-action-type="free">
                        <label class="field">직접 행동 입력
                            <textarea class="field-control" name="action_text" rows="3" maxlength="1000" required></textarea>
                        </label>
                        <div class="world-actions"><button class="button button--primary" type="submit">Gemini로 다음 장면 만들기</button></div>
                    </form>
                <?php endif; ?>
                <details class="surface world-panel">
                    <summary>장면 직접 작성</summary>
                    <form class="world-stack" action="<?= $escape(loreverseUrl('api/stories/advance.php')) ?>" method="post"
                          data-story-form="advance" data-action-type="manual">
                        <label class="field">장면 제목
                            <input class="field-control" name="scene_title" maxlength="200" required>
                        </label>
                        <label class="field">장면 내용
                            <textarea class="field-control" name="narration" rows="6" maxlength="5000" required></textarea>
                        </label>
                        <div class="world-actions"><button class="button button--outline" type="submit">직접 작성한 장면 저장</button></div>
                    </form>
                </details>
            </section>
        <?php endif; ?>
        <?php if ($turns !== []): ?>
            <details class="surface world-panel">
                <summary>이전 장면 <?= count($turns) ?>개</summary>
                <div class="world-stack">
                    <?php foreach (array_reverse($turns) as $turn) { loreverseRenderStoryTurn($turn, $names); } ?>
                </div>
            </details>
        <?php endif; ?>
        <section class="surface world-panel" aria-labelledby="story-manage-title">
            <h2 id="story-manage-title">스토리 관리</h2>
            <form class="world-actions" action="<?= $escape(loreverseUrl('api/stories/complete.php')) ?>" method="post" data-story-form="complete">
                <input type="hidden" name="status" value="<?= $story['status'] === 'completed' ? 'active' : 'completed' ?>">
                <button class="button button--outline" type="submit"><?= $story['status'] === 'completed' ? '스토리 다시 시작' : '스토리 종료' ?></button>
            </form>
            <details>
                <summary>스토리 삭제</summary>
                <form class="world-stack" action="<?= $escape(loreverseUrl('api/stories/delete.php')) ?>" method="post" data-story-form="delete">
                    <p>스토리와 장면 기록을 삭제합니다. 이미 공식 반영한 사건과 관계는 세계관에 남아요.</p>
                    <label class="world-genre-option"><input type="checkbox" name="confirmed" required>스토리와 장면 삭제에 동의해요.</label>
                    <div class="world-actions"><button class="button button--outline world-delete" type="submit">스토리 영구 삭제</button></div>
                </form>
            </details>
        </section>
    <?php endif; ?>
</div>
<?php loreverseWorldStudioEnd(); ?>

<?php
function loreverseRenderStoryTurn(array $turn, array $names): void
{
    $escape = 'loreverseEscape';
    $changes = $turn['relationship_changes'];
    $event = $turn['timeline_event'];
    $applied = $turn['canon_applied_at'] !== null;
    ?>
    <article class="surface world-panel" aria-labelledby="story-turn-<?= (int) $turn['id'] ?>">
        <p class="display-kicker">장면 <?= (int) $turn['turn_no'] ?> · <?= $turn['source'] === 'manual' ? '직접 작성' : 'Gemini 생성' ?></p>
        <h3 id="story-turn-<?= (int) $turn['id'] ?>"><?= $escape($turn['scene_title']) ?></h3>
        <?php if ($turn['user_action'] !== ''): ?><p class="world-prose"><strong>내 행동</strong> <?= $escape($turn['user_action']) ?></p><?php endif; ?>
        <p class="world-prose"><?= $escape($turn['narration']) ?></p>
        <?php if ($turn['dialogues'] !== []): ?>
            <ul class="world-records">
                <?php foreach ($turn['dialogues'] as $line): ?>
                    <li class="world-prose">
                        <strong><?= $escape($line['character_name'] ?? $names[$line['character_id']] ?? '등장인물') ?></strong>
                        <?php if ($line['speech'] !== ''): ?> <?= $escape($line['speech']) ?><?php endif; ?>
                        <?php if ($line['action'] !== ''): ?> <em><?= $escape($line['action']) ?></em><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($applied): ?>
            <p>선택한 제안을 세계관 기록에 공식 반영했어요.</p>
        <?php elseif ($changes !== [] || $event !== null): ?>
            <details class="surface world-panel">
                <summary>공식 설정 제안 검토</summary>
                <p>선택한 제안을 이 장면에서 한 번 반영할 수 있어요.</p>
                <form class="world-stack" action="<?= $escape(loreverseUrl('api/stories/apply-canon.php')) ?>" method="post" data-story-form="apply-canon">
                    <input type="hidden" name="turn_id" value="<?= (int) $turn['id'] ?>">
                    <?php foreach ($changes as $index => $change): ?>
                        <label class="world-genre-option">
                            <input type="checkbox" name="selected_relationship_indices[]" value="<?= (int) $index ?>">
                            <span><?= $escape($change['from_character_name'] ?? $names[$change['from_character_id']] ?? '등장인물') ?> →
                                <?= $escape($change['to_character_name'] ?? $names[$change['to_character_id']] ?? '등장인물') ?>
                                · 친밀도 <?= sprintf('%+d', $change['intimacy_delta']) ?> · 신뢰도 <?= sprintf('%+d', $change['trust_delta']) ?></span>
                            <small><?= $escape($change['reason']) ?></small>
                        </label>
                    <?php endforeach; ?>
                    <?php if ($event !== null): ?>
                        <label class="world-genre-option">
                            <input type="checkbox" name="include_event">
                            <span>타임라인 사건 · <?= $escape($event['title']) ?><?php if ($event['date_label'] !== ''): ?> · <?= $escape($event['date_label']) ?><?php endif; ?></span>
                            <small><?= $escape($event['description']) ?></small>
                        </label>
                    <?php endif; ?>
                    <div class="world-actions"><button class="button button--primary" type="submit">선택한 제안 공식 반영</button></div>
                </form>
            </details>
        <?php endif; ?>
    </article>
    <?php
}
