<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/photos.php';

requireAppAuth();

$inputPath = (string) ($_GET['path'] ?? $_GET['name'] ?? '');
$normalizedPath = normalizeRelativePhotoPath($inputPath);
if ($normalizedPath === null) {
    http_response_code(400);
    exit('invalid path');
}

$path = thumbPath($normalizedPath);
if (!is_file($path)) {
    http_response_code(404);
    exit('thumbnail not found');
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
