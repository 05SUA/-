<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/account-actions.php';
require_once __DIR__ . '/includes/favorite-ui.php';

$self = loreverseUrl('mypage.php');
$userId = loreverseStudioUser($self);
$message = null;
$nicknameInput = null;
try {
    $pdo = loreverseDatabase(loreverseWebConfig());
    $account = new AccountService($pdo, $userId);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try {
            $body = loreverseStudioForm(false);
            $action = $body['action'] ?? '';
            switch ($action) {
                case 'profile':
                    $nicknameInput = loreverseAuthInput($body, 'nickname');
                    $account->update($body, $_FILES['file'] ?? null);
                    break;
                case 'password':
                    loreverseAccountChangePassword($pdo, $userId, $body);
                    break;
                case 'delete':
                    loreverseRedirect(loreverseAccountDelete($pdo, $userId, $body)['redirect']);
                default:
                    throw new LoreverseWorldError('ACCOUNT_ACTION_INVALID', '계정 설정 작업을 확인해 주세요.');
            }
            loreverseRedirect(loreverseUrl('mypage.php', ['saved' => $action]));
        } catch (Throwable $error) {
            $failure = loreverseWorldFailure($error);
            http_response_code($failure->status);
            $message = $failure->getMessage();
        }
    }
    $data = $account->profile();
    $profile = $data['profile'];
    $favorites = (new FavoriteService($pdo, $userId))->list(['limit' => 3]);
} catch (Throwable $error) {
    if ($error instanceof LoreverseWorldError && $error->reason === 'AUTH_REQUIRED') {
        loreverseAuthAttempt('logout', ['_csrf' => loreverseCsrf()]);
        loreverseRedirect(loreverseUrl('login.php', ['next' => $self]));
    }
    error_log('LOREVERSE: account page could not be accessed');
    http_response_code(503);
    loreversePageStart('계정 설정');
    ?>
    <main id="main" class="site-container workspace-page" tabindex="-1">
        <section class="surface workspace-intro">
            <h1>계정 설정을 열지 못했어요.</h1>
            <p>잠시 후 다시 시도해 주세요.</p>
            <a href="<?= loreverseEscape(loreverseUrl('workspace.php')) ?>">작업 공간으로 돌아가기</a>
        </section>
    </main>
    <?php
    loreversePageEnd();
    exit;
}
$escape = 'loreverseEscape';
$csrf = $escape(loreverseCsrf());
$saved = match ($_GET['saved'] ?? null) {
    'profile' => '프로필을 저장했어요.',
    'password' => '비밀번호를 변경했어요.',
    default => null,
};
loreverseWorldStudioStart('계정 설정', 'account', []);
?>
<header class="surface workspace-intro world-welcome">
    <p class="display-kicker" lang="en">MY PAGE</p>
    <h1 class="section-title">계정 설정</h1>
    <p><?= $escape($profile['email']) ?></p>
</header>
<?php if ($message !== null): ?>
    <div class="state state--error" role="alert"><p><?= $escape($message) ?></p></div>
<?php elseif ($saved !== null): ?>
    <div class="state state--success" role="status"><p><?= $escape($saved) ?></p></div>
<?php endif; ?>
<dl class="world-stats">
    <?php foreach (['worlds' => '세계관', 'characters' => '캐릭터', 'stories' => '스토리', 'favorites' => '즐겨찾기'] as $key => $label): ?>
        <div class="surface"><dt><?= $label ?></dt><dd><?= $data['stats'][$key] ?></dd></div>
    <?php endforeach; ?>
</dl>
<div class="world-details-grid">
    <form class="surface world-panel" method="post" action="<?= $escape($self) ?>" enctype="multipart/form-data">
        <h2>프로필</h2>
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="profile">
        <?php if ($profile['has_image']): ?>
            <img src="<?= $escape(loreverseUrl('user-image.php', ['id' => $userId])) ?>" width="112" height="112" alt="현재 프로필 이미지">
        <?php endif; ?>
        <label class="field">닉네임
            <input class="field-control" type="text" name="nickname" required minlength="2" maxlength="30"
                autocomplete="nickname" value="<?= $escape($nicknameInput ?? $profile['nickname']) ?>">
        </label>
        <label class="field">프로필 이미지
            <input class="field-control" type="file" name="file" accept="image/jpeg,image/png,image/webp">
            <span class="field-help">JPG, PNG, WebP · 2MB 및 4096×4096 이하</span>
        </label>
        <?php if ($profile['has_image']): ?>
            <label><input type="checkbox" name="remove_image" value="1"> 기존 이미지 삭제</label>
        <?php endif; ?>
        <button class="button button--primary" type="submit">프로필 저장</button>
    </form>
    <form class="surface world-panel" method="post" action="<?= $escape($self) ?>">
        <h2>비밀번호 변경</h2>
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="password">
        <label class="field">현재 비밀번호
            <input class="field-control" type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label class="field">새 비밀번호
            <input class="field-control" type="password" name="new_password" required minlength="8" maxlength="72" autocomplete="new-password">
            <span class="field-help">8자 이상, UTF-8 72바이트 이하</span>
        </label>
        <label class="field">새 비밀번호 확인
            <input class="field-control" type="password" name="password_confirm" required minlength="8" maxlength="72" autocomplete="new-password">
        </label>
        <button class="button button--outline" type="submit">비밀번호 변경</button>
    </form>
</div>
<section class="world-stack" aria-labelledby="account-favorites-title">
    <div class="world-field-title">
        <h2 id="account-favorites-title">즐겨찾기</h2>
        <a class="button button--outline" href="<?= $escape(loreverseUrl('workspace.php', ['tab' => 'favorites'])) ?>">전체 보기</a>
    </div>
    <?php loreverseFavoriteCards($favorites['items'], $self); ?>
</section>
<form class="surface world-panel" method="post" action="<?= $escape($self) ?>">
    <h2>계정 삭제</h2>
    <p>계정과 저장한 세계관, 캐릭터, 대화, 스토리, 즐겨찾기 및 업로드 이미지를 삭제해요. 삭제하면 복구할 수 없어요.</p>
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="delete">
    <label class="field">현재 비밀번호
        <input class="field-control" type="password" name="current_password" required autocomplete="current-password">
    </label>
    <label class="field">확인 문구
        <input class="field-control" type="text" name="confirmation" required autocomplete="off">
        <span class="field-help">계정 삭제를 입력해 주세요.</span>
    </label>
    <button class="button button--outline world-delete" type="submit">계정 삭제</button>
</form>
<?php loreverseWorldStudioEnd(); ?>
