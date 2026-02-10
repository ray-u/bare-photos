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

$pathsRaw = $payload['paths'] ?? [];
if (!is_array($pathsRaw)) {
    http_response_code(400);
    exit('paths must be array');
}

$paths = [];
foreach ($pathsRaw as $value) {
    if (is_string($value)) {
        $paths[] = $value;
    }
}

if ($paths === []) {
    http_response_code(400);
    exit('no paths');
}

$result = deletePhotos($paths);
$ok = count($result['failed']) === 0;

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => $ok,
    'deleted' => $result['deleted'],
    'failed' => $result['failed'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
