<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/FavoriteService.php';

$media = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $media !== 'application/x-www-form-urlencoded') {
    loreverseStudioApi('POST', static fn(PDO $pdo, int $userId, array $body): array =>
        (new FavoriteService($pdo, $userId))->toggle($body));
    exit;
}

$fallback = loreverseUrl('workspace.php', ['tab' => 'favorites']);
$userId = loreverseStudioUser($fallback);
$return = $_POST['return'] ?? null;
$allowed = array_map('loreverseUrl', ['workspace.php', 'mypage.php', 'world.php', 'character.php', 'story.php']);
if (!is_string($return) || strlen($return) > 400 || strpbrk($return, "\r\n\0\\") !== false
    || !in_array(explode('?', $return, 2)[0], $allowed, true)) {
    $return = $fallback;
}
try {
    $body = loreverseStudioForm();
    $pdo = loreverseDatabase(loreverseWebConfig());
    (new FavoriteService($pdo, $userId))->toggle($body);
    loreverseRedirect($return);
} catch (Throwable $error) {
    $failure = loreverseWorldFailure($error);
    http_response_code($failure->status);
}
loreversePageStart('즐겨찾기');
?>
<main id="main" class="site-container workspace-page" tabindex="-1">
    <section class="surface workspace-intro">
        <h1>즐겨찾기를 변경하지 못했어요.</h1>
        <p><?= loreverseEscape($failure->getMessage()) ?></p>
        <a href="<?= loreverseEscape($return) ?>">돌아가기</a>
    </section>
</main>
<?php loreversePageEnd(); ?>
