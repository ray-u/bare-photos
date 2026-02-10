<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/photos.php';

requireAppAuth();

$inputPath = (string) ($_GET['path'] ?? $_GET['name'] ?? '');
$relativePath = resolvePhotoPathFromRequest($inputPath);
if ($relativePath === null) {
    http_response_code(404);
    exit('not found');
}

$path = photoPath($relativePath);
$mime = mime_content_type($path) ?: 'application/octet-stream';
$download = isset($_GET['download']) && $_GET['download'] === '1';
$displayName = basename($relativePath);
$asciiFilename = addcslashes($displayName, "\"\\");

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
if ($download) {
    header('Content-Disposition: attachment; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($displayName));
}
readfile($path);
