<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';
loadDotEnv(__DIR__ . '/../.env');

const PHOTO_DIR = __DIR__ . '/../photos';
const THUMB_DIR = __DIR__ . '/../thumbs';
const THUMB_MAX_EDGE = 480;

/**
 * @return array{user:string,pass:string}
 */
function authCredentials(): array
{
    $user = getenv('APP_BASIC_USER') ?: '';
    $pass = getenv('APP_BASIC_PASS') ?: '';

    return ['user' => $user, 'pass' => $pass];
}
