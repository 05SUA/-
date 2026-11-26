<?php

declare(strict_types=1);

if (($_POST['type'] ?? null) === 'character') {
    require_once dirname(__DIR__) . '/characters/image.php';
} else {
    require_once dirname(__DIR__, 2) . '/includes/world-api.php';
    loreverseWorldApi('image');
}
