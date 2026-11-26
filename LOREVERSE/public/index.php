<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/genres.php';
loreverseWebStart();
loreversePageStart('HOME', 'home', ['hero']);
$escape = 'loreverseEscape';
$video = loreverseHeroVideo();
$videoType = $video['type'] ?? null;
$create = loreverseUrl('world-create.php');
?>
<main id="main" tabindex="-1">
    <section class="home-hero" aria-labelledby="hero-title">
        <img class="hero-media" src="<?= $escape(loreverseUrl('assets/images/hero-poster.webp')) ?>"
             width="1600" height="900" alt="" fetchpriority="high">
        <?php if ($videoType === 'file'): ?>
            <video class="hero-media hero-video" data-hero-video="<?= $escape($video['url']) ?>"
                   muted loop playsinline preload="none" aria-hidden="true"></video>
            <button class="button button--outline hero-video-toggle" data-hero-toggle type="button" hidden>영상 재생</button>
        <?php elseif ($video === null): ?>
            <p class="hero-caption">포털 콘셉트 이미지</p>
        <?php endif; ?>
        <div class="hero-copy site-container">
            <p class="display-kicker" lang="en">BEYOND IMAGINATION.</p>
            <h1 id="hero-title" lang="en">YOUR WORLD.<br>YOUR RULES.</h1>
            <p class="hero-description">당신의 상상에서 시작되는 무한한 세계.</p>
            <div class="hero-actions">
                <a class="button button--primary" href="<?= $escape($create) ?>">세계 창조하기 <span aria-hidden="true">↗</span></a>
                <a class="button button--outline" href="<?= $escape(loreverseUrl('explore.php')) ?>">세계 탐색하기</a>
                <?php if ($videoType === 'youtube'): ?>
                    <a class="button button--outline" href="#portal-video">포털 영상 보기</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="marketing-content site-container">
        <?php if ($videoType === 'youtube'): ?>
            <section id="portal-video" aria-label="포털 영상">
                <iframe class="hero-video-embed" src="<?= $escape($video['url']) ?>"
                        width="1280" height="720" loading="lazy" title="LOREVERSE 포털 영상"
                        allow="encrypted-media; picture-in-picture" allowfullscreen></iframe>
            </section>
        <?php endif; ?>
        <section class="about site-grid" aria-labelledby="about-title">
            <div class="about-copy">
                <p class="display-kicker" lang="en">ABOUT LOREVERSE</p>
                <h2 class="section-title" id="about-title" lang="en">IMAGINE.<br>CREATE. CONNECT.</h2>
                <p class="section-lead">상상하고, 창조하고, 연결하다.</p>
                <p>세계의 규칙을 세우고 그 안을 살아가는 인물을 만들어 보세요. AI는 새로운 가능성을 제안하고, 어떤 이야기가 공식 설정이 될지는 당신이 결정합니다.</p>
            </div>
            <div class="about-art" aria-hidden="true">
                <div class="orbit orbit--one"></div><div class="orbit orbit--two"></div><div class="orbit orbit--three"></div>
                <span>✦</span>
            </div>
        </section>

        <section aria-labelledby="dimensions-title">
            <header class="marketing-heading">
                <p class="display-kicker" lang="en">EXPLORE DIMENSIONS</p>
                <h2 class="section-title" id="dimensions-title" lang="en">CHOOSE YOUR WORLD.</h2>
                <p>마음에 드는 장르로 시작하거나 나만의 장르를 정해 보세요.</p>
            </header>
            <?php loreverseGenreCards(); ?>
        </section>

        <section aria-labelledby="features-title">
            <header class="marketing-heading">
                <p class="display-kicker" lang="en">FEATURES</p>
                <h2 class="section-title" id="features-title" lang="en">BRING STORIES TO LIFE.</h2>
                <p>세계관에서 이야기를 이어가는 창작 도구를 준비하고 있어요.</p>
            </header>
            <div class="feature-grid">
                <?php foreach ([
                    ['layers', '세계관 생성', '역사, 세력, 규칙을 정해 이야기의 무대를 만듭니다.'],
                    ['grid', '캐릭터 생성', '인물의 성격, 배경, 목표를 세계관과 연결합니다.'],
                    ['sliders', '관계도', '두 인물이 서로를 어떻게 생각하는지 방향별로 기록합니다.'],
                    ['activity', 'AI 대화', '캐릭터의 관점에서 대화를 나누며 설정을 탐색합니다.'],
                    ['folder', '스토리', '선택을 통해 전개를 만들고 승인한 사건을 공식 기록에 남깁니다.'],
                ] as [$icon, $label, $description]): ?>
                    <article class="surface feature">
                        <span class="icon icon--<?= $escape($icon) ?>" aria-hidden="true"></span>
                        <h3><?= $escape($label) ?></h3><p><?= $escape($description) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section aria-labelledby="start-title">
            <header class="marketing-heading">
                <p class="display-kicker" lang="en">HOW TO START</p>
                <h2 class="section-title" id="start-title">상상에서 이야기까지</h2>
            </header>
            <ol class="start-grid">
                <li><h3 lang="en">CREATE</h3><p>세계의 장르와 규칙을 정해 보세요.</p></li>
                <li><h3 lang="en">CONNECT</h3><p>인물과 인물 사이에 관계를 만드세요.</p></li>
                <li><h3 lang="en">EXPERIENCE</h3><p>대화와 선택으로 그 세계를 경험하세요.</p></li>
            </ol>
        </section>

        <section class="final-cta" aria-labelledby="final-title">
            <h2 class="section-title" lang="en" id="final-title">YOUR UNIVERSE AWAITS.</h2>
            <p>당신의 세계가 기다리고 있습니다.</p>
            <a class="button button--primary" href="<?= $escape(loreverseUrl('workspace.php')) ?>">작업 공간 열기</a>
        </section>
        <section class="ai-note" id="ai-data" aria-labelledby="ai-title">
            <h2 id="ai-title">AI 데이터 안내</h2>
            <p>AI 기능을 사용할 때 창작 입력이 Gemini에 전송될 수 있어요. 실명, 연락처, 비밀번호 같은 민감한 정보는 창작 입력에 넣지 마세요.</p>
        </section>
    </div>
</main>
<?php loreversePageEnd(); ?>
