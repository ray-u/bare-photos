<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/photos.php';

requireAppAuth();

$name = $_GET['name'] ?? '';
if (!isSafeFilename($name)) {
    http_response_code(400);
    exit('invalid filename');
}

$path = thumbPath($name);
if (!is_file($path)) {
    http_response_code(404);
    exit('thumbnail not found');
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
