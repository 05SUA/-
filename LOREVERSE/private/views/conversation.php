<?php

declare(strict_types=1);

require_once LOREVERSE_PRIVATE_DIR . '/services/ChatService.php';
require_once LOREVERSE_PRIVATE_DIR . '/character-image.php';

$scope = array_intersect_key($_GET, array_flip(['world_id', 'character_id', 'session_id']));
$returnUrl = loreverseChatUrl($scope);
$user = loreverseStudioUser($returnUrl);
$escape = 'loreverseEscape';
$error = null;
$input = [];

try {
    $pdo = loreverseDatabase(loreverseWebConfig());
    $chat = new ChatService($pdo, $user);
    $characters = new CharacterService($pdo, $user);
    $worldId = isset($_GET['world_id']) ? loreverseWorldId($_GET['world_id']) : null;
    $characterId = isset($_GET['character_id']) ? loreverseCharacterId($_GET['character_id']) : null;
    $sessionId = isset($_GET['session_id']) ? loreverseChatId($_GET['session_id']) : null;
    $thread = $sessionId === null ? null : $chat->messages($sessionId, $_GET);
    $session = $thread['session'] ?? null;
    if ($session !== null && $characterId !== null && $session['character_id'] !== $characterId) {
        throw new LoreverseWorldError('CHAT_NOT_FOUND', '대화 세션을 찾을 수 없어요.', 404);
    }
    $characterId = $session['character_id'] ?? $characterId;
    $character = $characterId === null ? null : $characters->get($characterId);
    if ($character !== null && $worldId !== null && $character['world_id'] !== $worldId) {
        throw new LoreverseWorldError('CHARACTER_NOT_FOUND', '이 세계관의 캐릭터를 찾을 수 없어요.', 404);
    }
    $worldId ??= $character['world_id'] ?? null;
    $world = $worldId === null ? null : (new WorldService($pdo, $user))->get($worldId);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $input = $_POST;
        try {
            $body = loreverseStudioForm();
            $action = $body['action'] ?? null;
            if ($action === 'create' && $characterId !== null && $sessionId === null) {
                $body['character_id'] = $characterId;
                $body['world_id'] = $worldId;
                $created = $chat->create($body);
                loreverseRedirect(loreverseChatUrl(['session_id' => $created['id']]));
            }
            if ($sessionId === null) {
                loreverseWorldInvalid('대화 작업');
            }
            $body['session_id'] = $sessionId;
            switch ($action) {
                case 'send':
                    $chat->send($body);
                    loreverseRedirect(loreverseChatUrl(['session_id' => $sessionId]) . '#chat-thread-end');
                case 'rename':
                    $chat->rename($sessionId, $body['title'] ?? null);
                    loreverseRedirect(loreverseChatUrl(['session_id' => $sessionId]));
                case 'delete':
                    $chat->delete($sessionId, $body['confirmed'] ?? null);
                    loreverseRedirect(loreverseChatUrl(['character_id' => $characterId]));
                default:
                    loreverseWorldInvalid('대화 작업');
            }
        } catch (Throwable $failure) {
            $error = loreverseWorldFailure($failure);
            http_response_code($error->status);
        }
    }

    $filters = array_filter(['world_id' => $worldId, 'character_id' => $characterId], static fn ($value): bool => $value !== null);
    $sessions = $chat->sessions($filters + ['page' => $_GET['session_page'] ?? 1]);
    $search = is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 100, 'UTF-8') : '';
    $options = $character === null ? $characters->list([
        'world_id' => $worldId ?? '', 'page' => $_GET['character_page'] ?? 1, 'q' => $search, 'sort' => 'name',
    ]) : null;
} catch (Throwable $failure) {
    $error = loreverseWorldFailure($failure);
    http_response_code($error->status);
    loreverseWorldStudioStart('AI 대화', 'chat', []);
    ?>
    <section class="surface world-panel">
        <h1 class="section-title">대화를 열 수 없어요.</h1>
        <p><?= $escape($error->getMessage()) ?></p>
        <a class="button button--outline" href="<?= $escape(loreverseChatUrl()) ?>">대화 목록</a>
    </section>
    <?php
    loreverseWorldStudioEnd();
    exit;
}

$pageScope = $sessionId === null ? $filters : ['session_id' => $sessionId];
$pageUrl = loreverseChatUrl($pageScope);
$chooseScope = $worldId === null ? [] : ['world_id' => $worldId];
$chooseUrl = loreverseChatUrl($chooseScope);
$csrf = $escape(loreverseCsrf());
$field = static fn (string $key, string $default = ''): string => $escape(is_string($input[$key] ?? null) ? $input[$key] : $default);
$modeLabel = static fn (string $mode): string => LOREVERSE_CHAT_MODES[$mode] ?? '일상';
$chatStyles = dirname(__DIR__, 2) . '/public/assets/css/chat.css';
$styleHash = is_file($chatStyles) ? hash_file('sha256', $chatStyles) : false;
$styleVersion = $styleHash === false ? [] : ['v' => substr($styleHash, 0, 12)];
loreverseWorldStudioStart('AI 대화', 'chat', ['chat'], $world);
?>
<link rel="stylesheet" href="<?= $escape(loreverseUrl('assets/css/chat.css', $styleVersion)) ?>">
<header class="chat-page-heading">
    <h1>AI 캐릭터 대화</h1>
    <?php if ($world !== null): ?><p><?= $escape($world['name']) ?></p><?php endif; ?>
</header>
<?php if ($error !== null): ?>
    <p class="state state--error" role="alert"><?= $escape($error->getMessage()) ?></p>
<?php endif; ?>
<div class="chat-layout" data-chat data-character-name="<?= $escape($character['name'] ?? '') ?>">
    <details class="surface world-panel chat-sessions" data-chat-sessions open>
        <summary>대화 목록</summary>
        <nav aria-label="대화 세션">
            <div class="world-actions">
                <?php if ($character !== null): ?>
                    <a class="button button--outline" href="<?= $escape(loreverseChatUrl($filters)) ?>">새 대화</a>
                <?php endif; ?>
                <a href="<?= $escape($chooseUrl) ?>"><?= $character === null ? '캐릭터 선택' : '캐릭터 변경' ?></a>
                <?php if ($world !== null): ?><a href="<?= $escape(loreverseChatUrl()) ?>">전체 대화</a><?php endif; ?>
            </div>
            <?php if ($sessions['items'] === []): ?>
                <p class="world-muted">아직 대화가 없어요.</p>
            <?php else: ?>
                <ul class="chat-session-list">
                    <?php foreach ($sessions['items'] as $item): ?>
                        <li>
                            <a href="<?= $escape(loreverseChatUrl(['session_id' => $item['id']])) ?>"
                                <?= $item['id'] === $sessionId ? 'aria-current="page"' : '' ?>>
                                <strong><?= $escape($item['title']) ?></strong>
                                <span><?= $escape($item['character_name']) ?> · <?= $modeLabel($item['mode']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($sessions['pages'] > 1): ?>
                <div class="world-actions" aria-label="대화 목록 페이지">
                    <?php if ($sessions['page'] > 1): ?><a href="<?= $escape(loreverseChatUrl($pageScope + ['session_page' => $sessions['page'] - 1])) ?>">이전</a><?php endif; ?>
                    <span><?= $sessions['page'] ?> / <?= $sessions['pages'] ?></span>
                    <?php if ($sessions['page'] < $sessions['pages']): ?><a href="<?= $escape(loreverseChatUrl($pageScope + ['session_page' => $sessions['page'] + 1])) ?>">다음</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </nav>
    </details>
    <?php if ($session !== null): ?>
        <section class="surface chat-window" aria-labelledby="chat-thread-title">
            <header class="chat-thread-header">
                <img class="chat-thread-avatar" src="<?= $escape(loreverseCharacterImageUrl($character)) ?>"
                     alt="" width="42" height="42">
                <div>
                    <h2 id="chat-thread-title"><?= $escape($session['title']) ?></h2>
                    <p><?= $escape($character['name']) ?> · <?= $modeLabel($session['mode']) ?></p>
                </div>
            </header>
            <?php if ($session['user_role'] !== '' || $session['situation'] !== ''): ?>
                <details class="chat-thread-context">
                    <summary>대화 설정</summary>
                    <dl class="world-stack chat-context">
                        <?php if ($session['user_role'] !== ''): ?><div><dt>내 역할과 관계</dt><dd><?= $escape($session['user_role']) ?></dd></div><?php endif; ?>
                        <?php if ($session['situation'] !== ''): ?><div><dt>상황</dt><dd><?= $escape($session['situation']) ?></dd></div><?php endif; ?>
                    </dl>
                </details>
            <?php endif; ?>
            <?php if ($thread['pages'] > 1): ?>
                <nav class="world-actions chat-thread-nav" aria-label="메시지 기록">
                    <?php if ($thread['page'] < $thread['pages']): ?><a class="button button--outline" href="<?= $escape(loreverseChatUrl($pageScope + ['page' => $thread['page'] + 1])) ?>">이전 메시지</a><?php endif; ?>
                    <?php if ($thread['page'] > 1): ?><a class="button button--outline" href="<?= $escape(loreverseChatUrl($pageScope + ['page' => $thread['page'] - 1])) ?>">더 최근 메시지</a><?php endif; ?>
                </nav>
            <?php endif; ?>
            <div class="chat-transcript" data-chat-transcript role="log" aria-label="대화 기록"
                 aria-live="polite" aria-relevant="additions" tabindex="0">
                <p class="chat-empty" data-chat-empty<?= $thread['items'] !== [] ? ' hidden' : '' ?>>첫 메시지를 보내 대화를 시작해 보세요.</p>
                <ol class="chat-messages" data-chat-messages role="list">
                    <?php foreach ($thread['items'] as $message): ?>
                        <li class="chat-message chat-message--<?= $escape($message['role']) ?>" data-message-id="<?= $message['id'] ?>">
                            <strong class="chat-message-author" data-chat-author><?= $message['role'] === 'assistant' ? $escape($character['name']) : '나' ?></strong>
                            <div class="chat-message-bubble">
                                <p class="chat-action" data-chat-action aria-label="행동"<?= $message['action'] === '' ? ' hidden' : '' ?>><?= $escape($message['action']) ?></p>
                                <p class="chat-message-text" data-chat-content<?= $message['content'] === '' ? ' hidden' : '' ?>><?= $escape($message['content']) ?></p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <span id="chat-thread-end" aria-hidden="true"></span>
            </div>
            <template data-chat-message-template>
                <li class="chat-message">
                    <strong class="chat-message-author" data-chat-author></strong>
                    <div class="chat-message-bubble">
                        <p class="chat-action" data-chat-action aria-label="행동" hidden></p>
                        <p class="chat-message-text" data-chat-content hidden></p>
                    </div>
                </li>
            </template>
            <?php if ($thread['page'] === 1): ?>
                <form class="chat-composer" method="post" action="<?= $escape($pageUrl) ?>"
                      data-chat-send data-endpoint="<?= $escape(loreverseUrl('api/chat/send.php')) ?>">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                    <input type="hidden" name="request_id" value="<?= loreverseStudioRequestId() ?>">
                    <input type="hidden" name="expected_last_id" value="<?= $thread['latest_message_id'] ?>">
                    <label class="chat-composer-label" for="chat-message">메시지</label>
                    <div class="chat-compose-row">
                        <textarea class="field-control" id="chat-message" name="message" maxlength="2000" rows="2"
                                  aria-describedby="chat-message-help" placeholder="메시지를 입력하세요" required><?= $field('message') ?></textarea>
                        <button class="button button--primary" type="submit">메시지 보내기</button>
                    </div>
                    <p class="state" data-chat-status role="status" aria-live="polite" hidden></p>
                    <a class="button button--outline chat-reload" href="<?= $escape($pageUrl) ?>" data-chat-reload hidden>최근 대화 다시 열기</a>
                    <p class="field-help" id="chat-message-help">메시지, 캐릭터 설정, 최근 대화가 Gemini에 전송돼요. 개인정보를 입력하지 마세요.</p>
                </form>
            <?php else: ?>
                <div class="chat-composer">
                    <a class="button button--outline" href="<?= $escape($pageUrl) ?>">최근 대화에서 이어가기</a>
                </div>
            <?php endif; ?>
            <details class="chat-settings">
                <summary>대화 관리</summary>
                <form class="world-stack" method="post" action="<?= $escape($pageUrl) ?>">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="rename">
                    <label class="field">대화 이름<input class="field-control" name="title" maxlength="100" required
                        value="<?= $field('title', $session['title']) ?>"></label>
                    <div class="world-actions"><button class="button button--outline" type="submit">이름 변경</button></div>
                </form>
                <form class="world-stack" method="post" action="<?= $escape($pageUrl) ?>">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete">
                    <label><input type="checkbox" name="confirmed" value="1" required> 대화와 모든 메시지를 삭제할게요.</label>
                    <div class="world-actions"><button class="button button--outline world-delete" type="submit">대화 삭제</button></div>
                </form>
            </details>
        </section>
    <?php elseif ($character !== null): ?>
        <section class="surface world-panel chat-thread" aria-labelledby="chat-create-title">
            <h2 id="chat-create-title">새 대화 설정</h2>
            <form class="world-stack" method="post" action="<?= $escape($pageUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create">
                <label class="field">대화 이름
                    <input class="field-control" name="title" value="<?= $field('title', mb_substr($character['name'] . '와의 대화', 0, 100, 'UTF-8')) ?>"
                           maxlength="100" required>
                </label>
                <label class="field">대화 모드
                    <select class="field-control" name="mode">
                        <?php foreach (LOREVERSE_CHAT_MODES as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($input['mode'] ?? null) === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">내 역할과 관계 (선택)
                    <textarea class="field-control" name="user_role" maxlength="500" rows="2"><?= $field('user_role') ?></textarea>
                </label>
                <label class="field">상황 (선택)
                    <textarea class="field-control" name="situation" maxlength="1000" rows="4"><?= $field('situation') ?></textarea>
                </label>
                <div class="world-actions"><button class="button button--primary" type="submit">대화 시작</button></div>
            </form>
        </section>
    <?php else: ?>
        <section class="surface world-panel chat-thread" aria-labelledby="chat-choose-title">
            <h2 id="chat-choose-title">대화할 캐릭터 선택</h2>
            <form class="world-actions" method="get" action="<?= $escape(loreverseUrl('workspace.php')) ?>">
                <input type="hidden" name="tab" value="conversation">
                <?php if ($worldId !== null): ?><input type="hidden" name="world_id" value="<?= $worldId ?>"><?php endif; ?>
                <label class="field">캐릭터 검색
                    <input class="field-control" type="search" name="q" value="<?= $escape($search) ?>" maxlength="100">
                </label>
                <button class="button button--outline" type="submit">검색</button>
            </form>
            <?php if ($options['items'] === []): ?>
                <p class="state"><?= $search === '' ? '아직 대화할 캐릭터가 없어요.' : '검색에 맞는 캐릭터가 없어요.' ?></p>
                <a class="button button--outline" href="<?= $escape(loreverseUrl('character-create.php', $chooseScope)) ?>">캐릭터 만들기</a>
            <?php else: ?>
                <ul class="studio-record-list" id="chat-characters">
                    <?php foreach ($options['items'] as $item): ?>
                        <li>
                            <a href="<?= $escape(loreverseChatUrl($chooseScope + ['character_id' => $item['id']])) ?>"><?= $escape($item['name']) ?></a>
                            <span><?= $escape($item['world_name'] ?? '독립 캐릭터') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($options['pages'] > 1): ?>
                <nav class="world-actions" aria-label="캐릭터 페이지">
                    <?php $pageQuery = $chooseScope + ['q' => $search]; ?>
                    <?php if ($options['page'] > 1): ?><a href="<?= $escape(loreverseChatUrl($pageQuery + ['character_page' => $options['page'] - 1])) ?>">이전</a><?php endif; ?>
                    <span><?= $options['page'] ?> / <?= $options['pages'] ?></span>
                    <?php if ($options['page'] < $options['pages']): ?><a href="<?= $escape(loreverseChatUrl($pageQuery + ['character_page' => $options['page'] + 1])) ?>">다음</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($character !== null): ?>
        <details class="surface world-panel chat-profile" data-chat-profile open>
            <summary>캐릭터 프로필</summary>
            <div class="world-stack">
                <img src="<?= $escape(loreverseCharacterImageUrl($character)) ?>" alt="" width="160" height="180" loading="lazy">
                <h2><?= $escape($character['name']) ?></h2>
                <p><?= $escape($character['world_name'] ?? '독립 캐릭터') ?></p>
                <?php if ($character['occupation'] !== ''): ?><p><?= $escape($character['occupation']) ?></p><?php endif; ?>
                <?php if ($character['speech_style'] !== ''): ?><p class="world-prose">말투 · <?= $escape($character['speech_style']) ?></p><?php endif; ?>
                <a href="<?= $escape(loreverseUrl('character.php', ['id' => $characterId])) ?>">프로필 열기</a>
            </div>
        </details>
    <?php endif; ?>
</div>
<?php loreverseWorldStudioEnd(); ?>
