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
$download = isset($_GET['download']) && $_GET['download'] === '1';
$asciiFilename = addcslashes($name, "\"\\");

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
if ($download) {
    header('Content-Disposition: attachment; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($name));
}
readfile($path);
