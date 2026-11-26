<?php

declare(strict_types=1);

$privateDirectory = dirname(__DIR__, 2) . '/private';
if (!is_file($privateDirectory . '/web.php')) {
    $privateDirectory = dirname(__DIR__) . '/private';
}
if (!is_file($privateDirectory . '/web.php')) {
    http_response_code(503);
    exit('서버 설정을 확인해 주세요.');
}
define('LOREVERSE_PRIVATE_DIR', $privateDirectory);
require_once $privateDirectory . '/web.php';
require_once $privateDirectory . '/auth.php';
require_once __DIR__ . '/header.php';

function loreversePageStart(string $title, string $active = '', array $scripts = [], bool $studio = false): void
{
    $escape = 'loreverseEscape';
    ?>
    <!doctype html>
    <html lang="ko">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#09090F">
        <title><?= $escape($title) ?> | LOREVERSE</title>
        <link rel="icon" type="image/svg+xml" href="<?= $escape(loreverseUrl('assets/icons/layers.svg')) ?>">
        <?php foreach (['tokens', 'common', 'layout', 'site', 'world'] as $stylesheet): ?>
            <link rel="stylesheet" href="<?= $escape(loreverseUrl('assets/css/' . $stylesheet . '.css')) ?>">
        <?php endforeach; ?>
        <?php foreach (array_merge(['common'], $scripts) as $script): ?>
            <script defer src="<?= $escape(loreverseUrl('assets/js/' . $script . '.js')) ?>"></script>
        <?php endforeach; ?>
    </head>
    <body>
    <a class="button button--primary skip-link" href="#main">본문으로 건너뛰기</a>
    <?php
    if ($studio) {
        loreverseHeader([
            'variant' => 'studio', 'brandUrl' => loreverseUrl('index.php'),
            'drawer' => 'studio-navigation', 'context' => $title,
        ]);
        return;
    }
    loreverseHeader([
        'brandUrl' => loreverseUrl('index.php'),
        'links' => [
            ['label' => 'HOME', 'url' => loreverseUrl('index.php'), 'current' => $active === 'home'],
            ['label' => 'EXPLORE', 'url' => loreverseUrl('explore.php'), 'current' => $active === 'explore'],
            ['label' => 'WORKSPACE', 'url' => loreverseUrl('workspace.php'), 'current' => $active === 'workspace'],
        ],
        'createLinks' => [
            ['label' => '세계관 만들기', 'url' => loreverseUrl('world-create.php')],
            ['label' => '캐릭터 만들기', 'url' => loreverseUrl('workspace.php', ['create' => 'character'])],
        ],
        'accountUrl' => loreverseUrl(isset($_SESSION['user_id']) ? 'workspace.php' : 'login.php'),
    ]);
}

function loreversePageEnd(bool $studio = false): void
{
    ?>
    <?php if (!$studio): ?>
    <footer class="site-footer">
        <div class="site-container site-footer-inner">
            <div>
                <a class="brand" lang="en" href="<?= loreverseEscape(loreverseUrl('index.php')) ?>">LOREVERSE</a>
                <p lang="en">Beyond Imagination.</p>
            </div>
            <nav aria-label="하단 메뉴">
                <a href="<?= loreverseEscape(loreverseUrl('explore.php')) ?>">세계 탐색</a>
                <a href="<?= loreverseEscape(loreverseUrl('workspace.php')) ?>">작업 공간</a>
                <a href="<?= loreverseEscape(loreverseUrl('index.php')) ?>#ai-data">AI 데이터 안내</a>
            </nav>
        </div>
    </footer>
    <?php endif; ?>
    </body>
    </html>
    <?php
}
