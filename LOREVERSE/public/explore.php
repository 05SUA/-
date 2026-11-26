<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/genres.php';
loreverseWebStart();
loreversePageStart('EXPLORE', 'explore');
$escape = 'loreverseEscape';
?>
<main class="site-container explore-page" id="main" tabindex="-1">
    <header class="explore-hero">
        <p class="display-kicker" lang="en">EXPLORE DIMENSIONS</p>
        <h1 lang="en">CHOOSE<br>YOUR WORLD.</h1>
        <p>여섯 가지 장르에서 영감을 얻고, 당신의 세계가 시작될 곳을 고르세요.</p>
    </header>
    <section aria-labelledby="genre-list-title">
        <header class="marketing-heading">
            <h2 id="genre-list-title" class="section-title">장르 선택</h2>
            <p>장르를 선택하면 로그인 후 세계관 만들기에서 이어갈 수 있어요.</p>
        </header>
        <?php loreverseGenreCards(); ?>
    </section>
    <section class="sample-worlds" aria-labelledby="sample-title">
        <header class="marketing-heading">
            <p class="display-kicker" lang="en">SAMPLE WORLDS</p>
            <h2 id="sample-title" class="section-title">샘플 세계관</h2>
            <p>장르를 조합해 볼 수 있도록 기획한 공개 창작 예시예요.</p>
        </header>
        <div class="sample-grid">
            <?php foreach ([
                ['fantasy', '바람 왕국의 기록관', '바람을 기억하는 수정이 사라진 날, 왕국의 기록관은 지워진 역사를 찾아 떠납니다.'],
                ['cyberpunk', '네온 아래의 기록', '도시의 모든 기억이 거래되는 밤, 배달원은 자신의 이름으로 저장된 낯선 기억을 발견합니다.'],
                ['historical', '안개가 걷히기 전', '새 국경선이 그어지기 전날, 산길의 작은 주막에 서로 다른 편의 두 사절이 도착합니다.'],
            ] as [$genre, $name, $description]): ?>
                <article class="surface sample-card">
                    <img src="<?= $escape(loreverseUrl('assets/images/genre-' . $genre . '.webp')) ?>"
                         width="512" height="512" alt="" loading="lazy" decoding="async">
                    <div class="sample-copy">
                        <h3><?= $escape($name) ?></h3><p><?= $escape($description) ?></p>
                        <a class="button button--outline" href="<?= $escape(loreverseUrl('world-create.php', ['genre' => $genre])) ?>">
                            이 장르로 시작
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php loreversePageEnd(); ?>
