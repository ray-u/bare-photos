<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/photos.php';

requireAppAuth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('method not allowed');
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    http_response_code(400);
    exit('invalid json');
}

$path = isset($payload['path']) && is_string($payload['path']) ? $payload['path'] : '';
$favorite = isset($payload['favorite']) ? (bool) $payload['favorite'] : true;
$resolved = resolvePhotoPathFromRequest($path);
if ($resolved === null) {
    http_response_code(404);
    exit('not found');
}

if (!setFavorite($resolved, $favorite)) {
    http_response_code(500);
    exit('failed to save favorite');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'path' => $resolved,
    'favorite' => $favorite,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
