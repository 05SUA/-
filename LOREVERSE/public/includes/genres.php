<?php

declare(strict_types=1);

function loreverseGenres(): array
{
    return [
        'fantasy' => ['판타지', 'FANTASY', '마법과 오래된 왕국이 세계의 규칙을 만드는 곳.'],
        'cyberpunk' => ['사이버펑크', 'CYBERPUNK', '기술과 기업이 지배하는 도시에서 자유를 찾는 이야기.'],
        'modern' => ['현대물', 'MODERN', '익숙한 도시와 평범한 하루에서 시작되는 이야기.'],
        'apocalypse' => ['아포칼립스', 'APOCALYPSE', '문명이 무너진 자리에서 새로운 질서를 세우는 세계.'],
        'dark_fantasy' => ['다크 판타지', 'DARK FANTASY', '금기와 대가가 모든 선택에 그림자를 드리우는 곳.'],
        'historical' => ['시대극', 'HISTORICAL', '시대의 관습과 격변이 인물의 운명을 흔드는 이야기.'],
    ];
}

function loreverseGenreCards(): void
{
    ?>
    <div class="genre-grid">
        <?php foreach (loreverseGenres() as $genre => [$name, $english, $description]): ?>
            <a class="genre-card" href="<?= loreverseEscape(loreverseUrl('world-create.php', ['genre' => $genre])) ?>">
                <img src="<?= loreverseEscape(loreverseUrl('assets/images/genre-' . $genre . '.webp')) ?>"
                     width="512" height="512" alt="" loading="lazy" decoding="async">
                <div class="genre-copy">
                    <span lang="en"><?= loreverseEscape($english) ?></span>
                    <h3><?= loreverseEscape($name) ?></h3>
                    <p><?= loreverseEscape($description) ?></p>
                </div>
                <span class="genre-arrow" aria-hidden="true">↗</span>
            </a>
        <?php endforeach; ?>
    </div>
    <a class="button button--outline custom-genre" href="<?= loreverseEscape(loreverseUrl('world-create.php', ['genre' => 'custom'])) ?>">
        <span class="icon icon--plus" aria-hidden="true"></span><span lang="en">CUSTOM</span> 직접 장르 정하기
    </a>
    <?php
}
