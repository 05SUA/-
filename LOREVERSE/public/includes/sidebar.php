<?php

declare(strict_types=1);

require_once __DIR__ . '/ui.php';

function loreverseSidebar(array $links, string $label): void
{
    ?>
    <nav class="sidebar-nav" aria-label="<?= loreverseEscape($label) ?>">
        <?php foreach ($links as $link): ?>
            <a href="<?= loreverseEscape($link['url']) ?>"
                <?= !empty($link['current']) ? 'aria-current="page"' : '' ?>>
                <?php if (isset($link['icon'])): ?>
                    <span class="icon icon--<?= loreverseEscape($link['icon']) ?>" aria-hidden="true"></span>
                <?php endif; ?>
                <?= loreverseEscape($link['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}
