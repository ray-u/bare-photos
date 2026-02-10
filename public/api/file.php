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

$path = photoPath($name);
if (!is_file($path)) {
    http_response_code(404);
    exit('not found');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
readfile($path);
