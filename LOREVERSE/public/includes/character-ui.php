<?php

declare(strict_types=1);

require_once __DIR__ . '/studio-flow.php';
require_once LOREVERSE_PRIVATE_DIR . '/services/CharacterService.php';
require_once LOREVERSE_PRIVATE_DIR . '/character-image.php';

function loreverseCharacterPageError(Throwable $error): void
{
    $failure = loreverseWorldFailure($error);
    http_response_code($failure->status);
    loreverseWorldStudioStart('캐릭터', 'characters', []);
    ?>
    <section class="surface world-panel">
        <h1 class="section-title">캐릭터를 열 수 없어요.</h1>
        <p><?= loreverseEscape($failure->getMessage()) ?></p>
        <a class="button button--outline" href="<?= loreverseEscape(loreverseUrl('characters.php')) ?>">캐릭터 목록</a>
    </section>
    <?php
    loreverseWorldStudioEnd();
    exit;
}

function loreverseCharacterFormList(mixed $value, string $field, int $limit = 500): array
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > ($limit + 2) * 10) {
        loreverseCharacterInvalid($field);
    }
    $lines = preg_split('/\R/u', $value);
    $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    return loreverseCharacterList($lines, $field, $limit);
}

function loreverseCharacterNativeFields(array $input): array
{
    foreach (CHARACTER_LIST_FIELDS as $field) {
        $input[$field] = loreverseCharacterFormList($input[$field] ?? '', $field);
    }
    return loreverseCharacterData($input);
}

function loreverseCharacterLock(string $field, array $locks): void
{
    ?>
    <label class="button button--quiet"><input type="checkbox" name="locked_fields[]" value="<?= loreverseEscape($field) ?>"
        <?= in_array($field, $locks, true) ? 'checked' : '' ?>><?= loreverseEscape(CHARACTER_LABELS[$field]) ?> 고정</label>
    <?php
}

function loreverseCharacterValue(string $label, string|array $value): void
{
    if ($value === '' || $value === []) {
        return;
    }
    ?>
    <div>
        <h3><?= loreverseEscape($label) ?></h3>
        <?php if (is_array($value)): ?>
            <ul class="world-records">
                <?php foreach ($value as $item): ?><li><?= loreverseEscape($item) ?></li><?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="world-prose"><?= loreverseEscape($value) ?></p>
        <?php endif; ?>
    </div>
    <?php
}
