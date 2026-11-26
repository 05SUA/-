<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function loreverseAccountPage(string $action): void
{
    loreverseWebStart();
    $register = $action === 'register';
    $data = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
    $next = loreverseSafeNext($data['next'] ?? $_GET['next'] ?? null);
    if (isset($_SESSION['user_id'])) {
        loreverseRedirect($next);
    }
    $failure = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $result = loreverseAuthAttempt($action, $data);
            loreverseRedirect($result['redirect']);
        } catch (Throwable $error) {
            $failure = loreverseAuthFailure($error);
            http_response_code($failure->status);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        header('Allow: GET, HEAD, POST');
        http_response_code(405);
        exit('이 페이지에서 사용할 수 없는 요청입니다.');
    }
    $title = $register ? '회원가입' : '로그인';
    $escape = 'loreverseEscape';
    loreversePageStart($title, '', ['auth']);
    ?>
    <main class="site-container account-page" id="main" tabindex="-1">
        <div class="account-art" aria-hidden="true">
            <p lang="en">BEYOND<br>IMAGINATION.</p>
        </div>
        <section class="account-content" aria-labelledby="account-title">
            <p class="display-kicker" lang="en"><?= $register ? 'CREATE ACCOUNT' : 'WELCOME BACK' ?></p>
            <h1 class="section-title" id="account-title"><?= $escape($title) ?></h1>
            <form class="account-form" action="<?= $escape(loreverseUrl($action . '.php')) ?>" method="post"
                  data-auth-form data-auth-endpoint="<?= $escape(loreverseUrl('api/auth/' . $action . '.php')) ?>" novalidate>
                <input type="hidden" name="_csrf" value="<?= $escape(loreverseCsrf()) ?>">
                <input type="hidden" name="next" value="<?= $escape($next) ?>">
                <p class="form-message" data-auth-message role="alert" tabindex="-1" <?= $failure === null ? 'hidden' : '' ?>>
                    <?= $failure === null ? '' : $escape($failure->getMessage()) ?>
                </p>
                <?php
                loreverseAccountField('email', '이메일', 'email', 'email', $data, $failure);
                if ($register) {
                    loreverseAccountField('nickname', '닉네임', 'text', 'nickname', $data, $failure, '2~30자');
                }
                loreverseAccountField('password', '비밀번호', 'password', $register ? 'new-password' : 'current-password',
                    $data, $failure, $register ? '8자 이상, UTF-8 72바이트 이하' : '');
                if ($register) {
                    loreverseAccountField('password_confirm', '비밀번호 확인', 'password', 'new-password', $data, $failure);
                }
                ?>
                <button class="button button--primary" type="submit"><?= $escape($title) ?></button>
            </form>
            <p class="account-switch">
                <?= $register ? '이미 계정이 있나요?' : '처음 방문하셨나요?' ?>
                <a href="<?= $escape(loreverseUrl($register ? 'login.php' : 'register.php', ['next' => $next])) ?>">
                    <?= $register ? '로그인' : '회원가입' ?>
                </a>
            </p>
        </section>
    </main>
    <?php loreversePageEnd();
}

function loreverseAccountField(
    string $name,
    string $label,
    string $type,
    string $autocomplete,
    array $data,
    ?LoreverseAuthError $failure,
    string $help = ''
): void {
    $escape = 'loreverseEscape';
    $error = $failure->fields[$name] ?? '';
    $id = 'account-' . $name;
    $description = trim(($help !== '' ? $id . '-help ' : '') . ($error !== '' ? $id . '-error' : ''));
    ?>
    <div class="field" data-auth-field="<?= $escape($name) ?>">
        <label for="<?= $escape($id) ?>"><?= $escape($label) ?></label>
        <input class="field-control" type="<?= $escape($type) ?>" id="<?= $escape($id) ?>" name="<?= $escape($name) ?>"
               autocomplete="<?= $escape($autocomplete) ?>" required
               <?= $type === 'password' ? '' : 'value="' . $escape(substr(loreverseAuthInput($data, $name), 0, 255)) . '"' ?>
               <?= $description === '' ? '' : 'aria-describedby="' . $escape($description) . '"' ?>
               <?= $error === '' ? '' : 'aria-invalid="true"' ?>>
        <?php if ($help !== ''): ?>
            <span class="field-help" id="<?= $escape($id . '-help') ?>"><?= $escape($help) ?></span>
        <?php endif; ?>
        <span class="field-error" id="<?= $escape($id . '-error') ?>" <?= $error === '' ? 'hidden' : '' ?>><?= $escape($error) ?></span>
    </div>
    <?php
}
