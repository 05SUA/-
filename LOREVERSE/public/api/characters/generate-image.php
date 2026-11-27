<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode(['ok' => false, 'error' => [
    'code' => 'AI_PORTRAIT_REMOVED',
    'message' => '프로필 사진은 파일을 선택해 직접 올려 주세요.',
    'fields' => (object) [],
]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
