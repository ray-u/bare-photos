<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/photos.php';

requireAppAuth();

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'raw', 'image'], true)) {
    $filter = 'all';
}

$photos = listPhotos($filter);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'total' => count($photos),
    'filter' => $filter,
    'items' => $photos,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
