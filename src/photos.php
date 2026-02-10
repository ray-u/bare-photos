<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const VIEWABLE_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const RAW_EXTENSIONS = ['arw', 'cr2', 'cr3', 'nef', 'dng', 'rw2', 'orf'];

/**
 * @return array<int, array<string,mixed>>
 */
function listPhotos(string $filter = 'all', bool $favoritesOnly = false): array
{
    ensureDir(PHOTO_DIR);
    ensureDir(THUMB_DIR);

    $favorites = loadFavoritesSet();
    $items = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(PHOTO_DIR, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }

        $relativePath = relativePhotoPathFromAbsolute($entry->getPathname());
        if ($relativePath === null) {
            continue;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $isImage = in_array($extension, VIEWABLE_IMAGE_EXTENSIONS, true);
        $isRaw = in_array($extension, RAW_EXTENSIONS, true);

        if (!$isImage && !$isRaw) {
            continue;
        }

        if ($filter === 'raw' && !$isRaw) {
            continue;
        }

        if ($filter === 'image' && !$isImage) {
            continue;
        }

        if ($favoritesOnly && !isset($favorites[$relativePath])) {
            continue;
        }

        $items[] = buildPhotoEntry($relativePath, $extension, $isImage, $isRaw, isset($favorites[$relativePath]));
    }

    usort(
        $items,
        static fn(array $a, array $b): int => strnatcasecmp($a['filename'], $b['filename'])
    );

    return $items;
}

/**
 * @return array<string,mixed>
 */
function buildPhotoEntry(string $relativePath, string $extension, bool $isImage, bool $isRaw, bool $isFavorite): array
{
    $thumbInfo = resolveThumbnail($relativePath, $isImage, $isRaw);
    $apiBase = currentApiBasePath();
    $takenAt = resolveTakenAt($relativePath, $isRaw);

    return [
        'filename' => $relativePath,
        'basename' => basename($relativePath),
        'extension' => $extension,
        'type' => $isRaw ? 'raw' : 'image',
        'thumbnailUrl' => $thumbInfo['url'],
        'thumbnailStatus' => $thumbInfo['status'],
        'thumbnailMessage' => $thumbInfo['message'],
        'previewUrl' => $thumbInfo['previewUrl'],
        'sourceUrl' => $apiBase . '/file.php?path=' . rawurlencode($relativePath),
        'path' => $relativePath,
        'takenAt' => $takenAt,
        'isFavorite' => $isFavorite,
    ];
}

/**
 * @return array{url:?string,status:string,message:string,previewUrl:?string}
 */
function resolveThumbnail(string $relativePath, bool $isImage, bool $isRaw): array
{
    $thumbPath = thumbPath($relativePath);
    $apiBase = currentApiBasePath();
    $thumbUrl = $apiBase . '/thumb.php?path=' . rawurlencode($relativePath);

    if (is_file($thumbPath)) {
        $previewUrl = $thumbUrl;
        if ($isRaw) {
            $sidecarPreview = findSidecarPreviewImage($relativePath);
            if ($sidecarPreview !== null) {
                $previewUrl = $apiBase . '/file.php?path=' . rawurlencode($sidecarPreview);
            }
        }

        return ['url' => $thumbUrl, 'status' => 'ready', 'message' => '', 'previewUrl' => $previewUrl];
    }

    if ($isImage) {
        $generated = generateThumbnailFromImage(photoPath($relativePath), $thumbPath);
        if ($generated) {
            return [
                'url' => $thumbUrl,
                'status' => 'ready',
                'message' => '',
                'previewUrl' => $apiBase . '/file.php?path=' . rawurlencode($relativePath),
            ];
        }

        return [
            'url' => $apiBase . '/file.php?path=' . rawurlencode($relativePath),
            'status' => 'fallback-original',
            'message' => 'サムネ未生成（元画像表示）',
            'previewUrl' => $apiBase . '/file.php?path=' . rawurlencode($relativePath),
        ];
    }

    if ($isRaw) {
        $generated = generateThumbnailFromRaw($relativePath, photoPath($relativePath), $thumbPath);

        if ($generated['generated']) {
            $previewUrl = $thumbUrl;
            if ($generated['previewPath'] !== null) {
                $previewUrl = $apiBase . '/file.php?path=' . rawurlencode($generated['previewPath']);
            }

            return ['url' => $thumbUrl, 'status' => 'ready', 'message' => '', 'previewUrl' => $previewUrl];
        }

        return [
            'url' => null,
            'status' => 'unavailable',
            'message' => 'RAWサムネ生成不可（同名JPG/JPEGまたはExifToolが必要）',
            'previewUrl' => null,
        ];
    }

    return ['url' => null, 'status' => 'unsupported', 'message' => '未対応形式', 'previewUrl' => null];
}

function currentApiBasePath(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/photos.php';
    $base = str_replace('\\', '/', dirname($scriptName));
    $base = rtrim($base, '/');

    if ($base === '' || $base === '.') {
        return '/api';
    }

    return $base;
}

function ensureDir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function photoPath(string $relativePath): string
{
    return PHOTO_DIR . '/' . $relativePath;
}

function thumbPath(string $relativePath): string
{
    return THUMB_DIR . '/' . sha1($relativePath) . '.jpg';
}

function generateThumbnailFromImage(string $sourcePath, string $targetPath): bool
{
    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

    if (extension_loaded('imagick')) {
        return generateWithImagick($sourcePath, $targetPath);
    }

    if (!extension_loaded('gd')) {
        return false;
    }

    $image = match ($extension) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
        'png' => @imagecreatefrompng($sourcePath),
        'gif' => @imagecreatefromgif($sourcePath),
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        default => false,
    };

    if (!$image) {
        return false;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    [$dstW, $dstH] = downscaleSize($width, $height, THUMB_MAX_EDGE);
    $thumb = imagecreatetruecolor($dstW, $dstH);

    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $dstW, $dstH, $width, $height);
    $saved = imagejpeg($thumb, $targetPath, 84);

    imagedestroy($image);
    imagedestroy($thumb);

    return $saved;
}

function generateWithImagick(string $sourcePath, string $targetPath): bool
{
    try {
        $imagick = new Imagick($sourcePath);
        $imagick->thumbnailImage(THUMB_MAX_EDGE, THUMB_MAX_EDGE, true, true);
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(84);
        $result = $imagick->writeImage($targetPath);
        $imagick->clear();
        $imagick->destroy();

        return $result;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return array{0:int,1:int}
 */
function downscaleSize(int $width, int $height, int $maxEdge): array
{
    if ($width <= $maxEdge && $height <= $maxEdge) {
        return [$width, $height];
    }

    $scale = min($maxEdge / $width, $maxEdge / $height);

    return [max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale))];
}

/**
 * @return array{generated:bool,previewPath:?string}
 */
function generateThumbnailFromRaw(string $rawRelativePath, string $rawPath, string $targetPath): array
{
    $sidecarPreview = findSidecarPreviewImage($rawRelativePath);
    if ($sidecarPreview !== null && generateThumbnailFromImage(photoPath($sidecarPreview), $targetPath)) {
        return ['generated' => true, 'previewPath' => $sidecarPreview];
    }

    $tempPreview = tempnam(sys_get_temp_dir(), 'raw_preview_');
    if ($tempPreview === false) {
        return ['generated' => false, 'previewPath' => null];
    }

    $commands = [
        sprintf('exiftool -b -PreviewImage %s > %s', escapeshellarg($rawPath), escapeshellarg($tempPreview)),
        sprintf('exiftool -b -JpgFromRaw %s > %s', escapeshellarg($rawPath), escapeshellarg($tempPreview)),
    ];

    foreach ($commands as $command) {
        $output = [];
        $code = 1;
        @exec($command, $output, $code);

        if ($code === 0 && is_file($tempPreview) && filesize($tempPreview) > 0 && @getimagesize($tempPreview)) {
            $generated = generateThumbnailFromImage($tempPreview, $targetPath);
            @unlink($tempPreview);
            if ($generated) {
                return ['generated' => true, 'previewPath' => null];
            }
        }

        file_put_contents($tempPreview, '');
    }

    @unlink($tempPreview);

    return ['generated' => false, 'previewPath' => null];
}

function findSidecarPreviewImage(string $rawRelativePath): ?string
{
    $dir = dirname($rawRelativePath);
    if ($dir === '.') {
        $dir = '';
    }

    $baseName = pathinfo($rawRelativePath, PATHINFO_FILENAME);
    foreach (['jpg', 'jpeg', 'JPG', 'JPEG'] as $extension) {
        $candidate = ($dir !== '' ? $dir . '/' : '') . $baseName . '.' . $extension;
        if (is_file(photoPath($candidate))) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return array{value:?string,source:string}
 */
function resolveTakenAt(string $relativePath, bool $isRaw): array
{
    $path = photoPath($relativePath);

    $fromExif = resolveTakenAtFromImageExif($path);
    if ($fromExif !== null) {
        return ['value' => $fromExif, 'source' => 'exif'];
    }

    if ($isRaw) {
        $fromRaw = resolveTakenAtFromRawExiftool($path);
        if ($fromRaw !== null) {
            return ['value' => $fromRaw, 'source' => 'exiftool'];
        }
    }

    $modifiedAt = @filemtime($path);
    if ($modifiedAt !== false) {
        return ['value' => date('Y-m-d H:i:s', $modifiedAt), 'source' => 'filemtime'];
    }

    return ['value' => null, 'source' => 'unavailable'];
}

function resolveTakenAtFromImageExif(string $path): ?string
{
    if (!function_exists('exif_read_data')) {
        return null;
    }

    $exif = @exif_read_data($path, null, true);
    if (!is_array($exif)) {
        return null;
    }

    $value = $exif['EXIF']['DateTimeOriginal'] ?? $exif['EXIF']['DateTimeDigitized'] ?? $exif['IFD0']['DateTime'] ?? null;
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    return normalizeExifDateTime($value);
}

function resolveTakenAtFromRawExiftool(string $path): ?string
{
    $commands = [
        sprintf('exiftool -s3 -DateTimeOriginal %s', escapeshellarg($path)),
        sprintf('exiftool -s3 -CreateDate %s', escapeshellarg($path)),
    ];

    foreach ($commands as $command) {
        $output = [];
        $code = 1;
        @exec($command, $output, $code);
        if ($code !== 0 || empty($output)) {
            continue;
        }

        $joined = trim(implode(' ', $output));
        if ($joined !== '') {
            return normalizeExifDateTime($joined);
        }
    }

    return null;
}

function normalizeExifDateTime(string $value): string
{
    $trimmed = trim($value);
    $normalized = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $trimmed);

    return $normalized ?? $trimmed;
}

function relativePhotoPathFromAbsolute(string $absolutePath): ?string
{
    $normalized = str_replace('\\', '/', $absolutePath);
    $prefix = str_replace('\\', '/', PHOTO_DIR) . '/';

    if (!str_starts_with($normalized, $prefix)) {
        return null;
    }

    return substr($normalized, strlen($prefix));
}

function normalizeRelativePhotoPath(string $path): ?string
{
    $normalized = trim(str_replace('\\', '/', $path));
    $normalized = ltrim($normalized, '/');

    if ($normalized === '' || str_contains($normalized, "\0")) {
        return null;
    }

    if (preg_match('#(^|/)\.\.?(/|$)#', $normalized) === 1) {
        return null;
    }

    $segments = explode('/', $normalized);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return null;
        }
    }

    return $normalized;
}

function resolvePhotoPathFromRequest(string $paramPath): ?string
{
    $normalized = normalizeRelativePhotoPath($paramPath);
    if ($normalized === null) {
        return null;
    }

    $fullPath = photoPath($normalized);
    if (!is_file($fullPath)) {
        return null;
    }

    $realPhotoDir = realpath(PHOTO_DIR);
    $realFilePath = realpath($fullPath);
    if ($realPhotoDir === false || $realFilePath === false) {
        return null;
    }

    $realPhotoDir = str_replace('\\', '/', $realPhotoDir);
    $realFilePath = str_replace('\\', '/', $realFilePath);

    if (!str_starts_with($realFilePath, $realPhotoDir . '/')) {
        return null;
    }

    return $normalized;
}

function isSafeFilename(string $name): bool
{
    return normalizeRelativePhotoPath($name) !== null;
}

/**
 * @return array<string,bool>
 */
function loadFavoritesSet(): array
{
    ensureDir(FAVORITE_DIR);

    if (!is_file(FAVORITES_FILE)) {
        return [];
    }

    $raw = file_get_contents(FAVORITES_FILE);
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $set = [];
    foreach ($decoded as $value) {
        if (!is_string($value)) {
            continue;
        }
        $normalized = normalizeRelativePhotoPath($value);
        if ($normalized !== null) {
            $set[$normalized] = true;
        }
    }

    return $set;
}

/**
 * @param array<string,bool> $set
 */
function saveFavoritesSet(array $set): bool
{
    ensureDir(FAVORITE_DIR);

    $items = array_keys($set);
    sort($items, SORT_NATURAL | SORT_FLAG_CASE);

    return file_put_contents(FAVORITES_FILE, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

function setFavorite(string $relativePath, bool $favorite): bool
{
    $set = loadFavoritesSet();

    if ($favorite) {
        $set[$relativePath] = true;
    } else {
        unset($set[$relativePath]);
    }

    return saveFavoritesSet($set);
}

/**
 * @param array<int,string> $paths
 * @return array{deleted:array<int,string>,failed:array<int,string>}
 */
function deletePhotos(array $paths): array
{
    $deleted = [];
    $failed = [];
    $favorites = loadFavoritesSet();

    foreach ($paths as $path) {
        $normalized = resolvePhotoPathFromRequest($path);
        if ($normalized === null) {
            $failed[] = $path;
            continue;
        }

        $fullPath = photoPath($normalized);
        if (!@unlink($fullPath)) {
            $failed[] = $normalized;
            continue;
        }

        $thumbPath = thumbPath($normalized);
        if (is_file($thumbPath)) {
            @unlink($thumbPath);
        }

        unset($favorites[$normalized]);
        $deleted[] = $normalized;
    }

    saveFavoritesSet($favorites);

    return ['deleted' => $deleted, 'failed' => $failed];
}
