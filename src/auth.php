<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function requireAppAuth(): void
{
    $credentials = authCredentials();
    if ($credentials['user'] === '' && $credentials['pass'] === '') {
        return;
    }

    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if ($user === $credentials['user'] && hash_equals($credentials['pass'], $pass)) {
        return;
    }

    header('WWW-Authenticate: Basic realm="bare-photos"');
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
