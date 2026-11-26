<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/public/includes/header.php';
require_once dirname(__DIR__) . '/public/includes/sidebar.php';

$links = [
    ['label' => '개요', 'url' => '#overview', 'icon' => 'layers', 'current' => true],
    ['label' => '버튼', 'url' => '#buttons', 'icon' => 'grid'],
    ['label' => '입력', 'url' => '#fields', 'icon' => 'sliders'],
    ['label' => '상태', 'url' => '#states', 'icon' => 'activity'],
    ['label' => '공개 헤더', 'url' => '#public-header', 'icon' => 'layers'],
];

function stage02Palette(): void
{
    $palette = [
        'bg' => '배경', 'panel' => '패널', 'surface' => '표면', 'primary' => '기본 동작',
        'accent' => '강조', 'text' => '기본 텍스트', 'muted' => '보조 텍스트',
        'border' => '구분선', 'danger' => '오류', 'success' => '성공',
    ];
    ?>
    <dl class="palette">
        <?php foreach ($palette as $token => $label): ?>
            <div class="palette-row">
                <dt><i aria-hidden="true" style="background: var(--<?= loreverseEscape($token) ?>)"></i><?= loreverseEscape($label) ?></dt>
                <dd><code>--<?= loreverseEscape($token) ?></code></dd>
            </div>
        <?php endforeach; ?>
    </dl>
    <?php
}
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>공통 UI 시스템 | LOREVERSE</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/common.css">
    <link rel="stylesheet" href="/assets/css/layout.css">
    <script src="/assets/js/common.js" defer></script>
    <style>
        .kit-intro {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            justify-content: space-between;
            gap: 1rem;
            margin-block-end: var(--card-gap);
            padding: clamp(1.25rem, 4vw, 2rem);
            border: 1px solid var(--border);
            border-radius: var(--card-radius);
            background: linear-gradient(116deg, var(--panel), #1d192c);
        }
        .kit-intro h1 { font-size: clamp(1.75rem, 3.5vw, 2.75rem); letter-spacing: -.04em; }
        .kit-intro p { margin-block-start: .625rem; color: var(--muted); }
        .kit-stack { display: grid; gap: var(--card-gap); min-inline-size: 0; }
        .kit-card { display: grid; gap: 1rem; }
        .kit-card h2 { font-size: 1.125rem; }
        .kit-row { display: flex; flex-wrap: wrap; gap: .625rem; }
        .kit-row [aria-pressed="true"] { color: var(--accent); border-color: var(--primary); }
        .kit-fields { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 1rem; align-items: start; }
        .kit-fields .kit-row { grid-column: 1 / -1; align-items: center; }
        .kit-message { color: var(--success); font-size: .875rem; }
        .palette { display: grid; }
        .palette-row { display: flex; justify-content: space-between; gap: .75rem; align-items: center; padding-block: .5rem; }
        .palette-row + .palette-row { border-block-start: 1px solid var(--border); }
        .palette dt { display: flex; align-items: center; gap: .5rem; font-size: .875rem; }
        .palette dd { color: var(--muted); font-size: .8125rem; }
        .palette i { inline-size: .875rem; block-size: .875rem; border: 1px solid var(--control-border); border-radius: 3px; }
        .kit-header-preview { margin-block-start: var(--card-gap); padding: 0; }
        .kit-header-preview > h2 { padding: 1rem 1.25rem 0; }
        .kit-header-preview .site-header { border-radius: 0 0 var(--card-radius) var(--card-radius); box-shadow: none; }
        @media (max-width: 767px) { .kit-fields { grid-template-columns: minmax(0, 1fr); } }
    </style>
</head>
<body>
<a class="button button--primary skip-link" href="#main">본문으로 건너뛰기</a>
<?php loreverseHeader(['variant' => 'studio', 'brandUrl' => '#overview', 'drawer' => 'studio-menu', 'context' => '구성 요소 미리보기']); ?>
<div class="studio-shell">
    <aside class="studio-sidebar">
        <p class="sidebar-title">공통 UI</p>
        <?php loreverseSidebar($links, '구성 요소'); ?>
    </aside>
    <dialog class="drawer" id="studio-menu" data-drawer="sidebar" aria-labelledby="studio-menu-title">
        <div class="drawer-heading">
            <h2 id="studio-menu-title">공통 UI</h2>
            <form method="dialog">
                <button class="button button--quiet button--icon" aria-label="작업 공간 메뉴 닫기">
                    <span class="icon icon--x" aria-hidden="true"></span>
                </button>
            </form>
        </div>
        <?php loreverseSidebar($links, '모바일 구성 요소'); ?>
    </dialog>
    <main class="studio-main" id="main" tabindex="-1">
        <header class="kit-intro" id="overview">
            <div>
                <h1>공통 UI 시스템</h1>
                <p>LOREVERSE에 사용할 버튼, 입력, 탐색과 상태 표시를 확인합니다.</p>
            </div>
            <button class="button button--outline studio-detail-trigger" type="button"
                    data-open-dialog="design-tokens" aria-controls="design-tokens" aria-expanded="false">
                <span class="icon icon--panel-right" aria-hidden="true"></span>디자인 토큰 보기
            </button>
        </header>
        <div class="studio-split">
            <div class="kit-stack">
                <section class="surface kit-card" id="buttons" aria-labelledby="buttons-title">
                    <h2 id="buttons-title">버튼</h2>
                    <div class="kit-row">
                        <a class="button button--primary" href="#fields">입력 확인하기</a>
                        <a class="button button--outline" href="#states">상태 보기</a>
                        <button class="button" type="button" disabled>비활성 버튼</button>
                    </div>
                </section>
                <section class="surface kit-card" id="fields" aria-labelledby="fields-title">
                    <h2 id="fields-title">입력</h2>
                    <form class="kit-fields" method="post" data-input-demo novalidate>
                        <div class="field">
                            <label for="preview-name">이름</label>
                            <input class="field-control" id="preview-name" name="name" required maxlength="40"
                                   aria-describedby="preview-help" placeholder="미리보기 이름을 입력하세요">
                            <span class="field-help" id="preview-help">최대 40자</span>
                            <span class="field-error" id="preview-error" hidden>이름을 입력해 주세요.</span>
                        </div>
                        <div class="field">
                            <label for="preview-type">보기 방식</label>
                            <select class="field-control" id="preview-type" name="type">
                                <option>기본</option>
                                <option>상세</option>
                            </select>
                        </div>
                        <div class="kit-row">
                            <button class="button button--primary" type="submit">입력 확인</button>
                            <p class="kit-message" data-input-message role="status"></p>
                        </div>
                    </form>
                </section>
                <section class="surface kit-card" id="states" aria-labelledby="states-title">
                    <h2 id="states-title">상태 표시</h2>
                    <div class="kit-row" role="group" aria-label="미리볼 상태">
                        <button class="button button--outline" type="button" data-state-button="loading" aria-pressed="false">로딩</button>
                        <button class="button button--outline" type="button" data-state-button="empty" aria-pressed="true">빈 화면</button>
                        <button class="button button--outline" type="button" data-state-button="error" aria-pressed="false">오류</button>
                        <button class="button button--outline" type="button" data-state-button="success" aria-pressed="false">성공</button>
                    </div>
                    <div role="status" aria-live="polite" aria-atomic="true">
                        <div class="state state--loading" data-state="loading" hidden>
                            <span class="spinner" aria-hidden="true"></span>
                            <div><strong>불러오는 중</strong><p>요청을 처리하고 있어요.</p></div>
                        </div>
                        <div class="state" data-state="empty">
                            <span class="icon icon--folder" aria-hidden="true"></span>
                            <div><strong>아직 항목이 없어요</strong><p>항목이 추가되면 여기에 표시됩니다.</p></div>
                        </div>
                        <div class="state state--error" data-state="error" hidden>
                            <span class="icon icon--alert" aria-hidden="true"></span>
                            <div><strong>불러오지 못했어요</strong><p>연결 상태를 확인한 뒤 다시 시도해 주세요.</p></div>
                        </div>
                        <div class="state state--success" data-state="success" hidden>
                            <span class="icon icon--check" aria-hidden="true"></span>
                            <div><strong>완료했어요</strong><p>요청이 정상적으로 처리됐습니다.</p></div>
                        </div>
                    </div>
                </section>
            </div>
            <aside class="surface kit-card studio-detail" aria-labelledby="desktop-tokens-title">
                <h2 id="desktop-tokens-title">디자인 토큰</h2>
                <?php stage02Palette(); ?>
            </aside>
            <dialog class="drawer drawer--right" id="design-tokens" data-drawer="detail" aria-labelledby="mobile-tokens-title">
                <div class="drawer-heading">
                    <h2 id="mobile-tokens-title">디자인 토큰</h2>
                    <form method="dialog">
                        <button class="button button--quiet button--icon" aria-label="디자인 토큰 닫기">
                            <span class="icon icon--x" aria-hidden="true"></span>
                        </button>
                    </form>
                </div>
                <?php stage02Palette(); ?>
            </dialog>
        </div>
        <section class="surface kit-card kit-header-preview" id="public-header" aria-labelledby="public-header-title">
            <h2 id="public-header-title">공개 헤더</h2>
            <?php loreverseHeader([
                'brandUrl' => '#overview',
                'links' => [
                    ['label' => 'HOME', 'url' => '#overview', 'current' => true],
                    ['label' => 'EXPLORE', 'url' => '#states'],
                    ['label' => 'WORKSPACE', 'url' => '#main'],
                ],
                'createLinks' => [
                    ['label' => '세계관 만들기', 'url' => '#fields'],
                    ['label' => '캐릭터 만들기', 'url' => '#fields'],
                ],
                'accountUrl' => '#fields',
            ]); ?>
        </section>
    </main>
</div>
<script>
    const sectionLinks = Array.from(document.querySelectorAll('.sidebar-nav a'));
    function updateSectionLinks() {
        const fragment = sectionLinks.some((link) => link.hash === location.hash) ? location.hash : '#overview';
        for (const link of sectionLinks) {
            if (link.hash === fragment) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        }
    }
    addEventListener('hashchange', updateSectionLinks);
    updateSectionLinks();

    const stateButtons = Array.from(document.querySelectorAll('[data-state-button]'));
    const states = Array.from(document.querySelectorAll('[data-state]'));
    for (const button of stateButtons) {
        button.addEventListener('click', () => {
            for (const item of stateButtons) item.setAttribute('aria-pressed', String(item === button));
            for (const state of states) state.hidden = state.dataset.state !== button.dataset.stateButton;
        });
    }

    const form = document.querySelector('[data-input-demo]');
    const nameField = document.querySelector('#preview-name');
    const error = document.querySelector('#preview-error');
    const message = document.querySelector('[data-input-message]');
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const valid = nameField.value.trim().length > 0 && nameField.checkValidity();
        error.hidden = valid;
        if (valid) {
            nameField.removeAttribute('aria-invalid');
            nameField.setAttribute('aria-describedby', 'preview-help');
        } else {
            nameField.setAttribute('aria-invalid', 'true');
            nameField.setAttribute('aria-describedby', 'preview-help preview-error');
            nameField.focus();
        }
        message.textContent = valid ? '입력 형식을 확인했어요.' : '';
    });
</script>
</body>
</html>
