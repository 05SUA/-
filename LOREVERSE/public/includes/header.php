<?php

declare(strict_types=1);

require_once __DIR__ . '/ui.php';

/** Pass application-authored URLs and labels, or links to test sections in the component preview. */
function loreverseHeader(array $options): void
{
    $studio = ($options['variant'] ?? '') === 'studio';
    $drawer = $options['drawer'] ?? '';
    $brand = $options['brandUrl'];
    ?>
    <header class="<?= $studio ? 'studio-header' : 'site-header' ?>">
        <div class="<?= $studio ? 'studio-header-left' : 'site-container site-header-inner' ?>">
            <?php if ($studio && $drawer !== ''): ?>
                <button type="button" class="button button--quiet button--icon studio-menu-trigger"
                        data-open-dialog="<?= loreverseEscape($drawer) ?>"
                        aria-controls="<?= loreverseEscape($drawer) ?>" aria-expanded="false"
                        aria-label="작업 공간 메뉴 열기">
                    <span class="icon icon--menu" aria-hidden="true"></span>
                </button>
            <?php endif; ?>
            <a class="brand" href="<?= loreverseEscape($brand) ?>" lang="en">
                <span class="brand-mark" aria-hidden="true"><span>✦</span></span>
                LOREVERSE
            </a>
            <?php if (!$studio): ?>
                <nav class="site-nav" aria-label="공개 메뉴">
                    <?php foreach ($options['links'] as $link): ?>
                        <a class="button button--quiet" lang="en" href="<?= loreverseEscape($link['url']) ?>"
                            <?= !empty($link['current']) ? 'aria-current="page"' : '' ?>>
                            <?= loreverseEscape($link['label']) ?>
                        </a>
                    <?php endforeach; ?>
                    <details class="create-menu" data-create-menu>
                        <summary class="button button--outline">
                            <span class="icon icon--plus" aria-hidden="true"></span>
                            <span lang="en">CREATE</span>
                            <span class="icon icon--chevron-down" aria-hidden="true"></span>
                        </summary>
                        <div class="surface create-menu-items">
                            <?php foreach ($options['createLinks'] as $link): ?>
                                <a class="button button--quiet" href="<?= loreverseEscape($link['url']) ?>">
                                    <?= loreverseEscape($link['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <a class="button button--quiet" href="<?= loreverseEscape($options['accountUrl']) ?>">계정</a>
                </nav>
            <?php endif; ?>
        </div>
        <?php if ($studio && isset($options['context'])): ?>
            <span class="studio-header-right"><?= loreverseEscape($options['context']) ?></span>
        <?php endif; ?>
    </header>
    <?php
}
