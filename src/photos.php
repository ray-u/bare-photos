<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const VIEWABLE_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const RAW_EXTENSIONS = ['arw', 'cr2', 'cr3', 'nef', 'dng', 'rw2', 'orf'];

/**
 * @return array<int, array<string,mixed>>
 */
function listPhotos(string $filter = 'all'): array
{
    ensureDir(PHOTO_DIR);
    ensureDir(THUMB_DIR);

    $items = [];
    $iterator = new DirectoryIterator(PHOTO_DIR);

    foreach ($iterator as $entry) {
        if ($entry->isDot() || !$entry->isFile()) {
            continue;
        }

        $filename = $entry->getFilename();
        $extension = strtolower($entry->getExtension());
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

        $items[] = buildPhotoEntry($filename, $extension, $isImage, $isRaw);
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
function buildPhotoEntry(string $filename, string $extension, bool $isImage, bool $isRaw): array
{
    $thumbInfo = resolveThumbnail($filename, $extension, $isImage, $isRaw);

    return [
        'filename' => $filename,
        'extension' => $extension,
        'type' => $isRaw ? 'raw' : 'image',
        'thumbnailUrl' => $thumbInfo['url'],
        'thumbnailStatus' => $thumbInfo['status'],
        'thumbnailMessage' => $thumbInfo['message'],
        'previewUrl' => $thumbInfo['previewUrl'],
        'sourceUrl' => '/api/file.php?name=' . rawurlencode($filename),
    ];
}

/**
 * @return array{url:?string,status:string,message:string,previewUrl:?string}
 */
function resolveThumbnail(string $filename, string $extension, bool $isImage, bool $isRaw): array
{
    $thumbPath = thumbPath($filename);
    $thumbUrl = '/api/thumb.php?name=' . rawurlencode($filename);

    if (is_file($thumbPath)) {
        $status = 'ready';
        return ['url' => $thumbUrl, 'status' => $status, 'message' => '', 'previewUrl' => $thumbUrl];
    }

    if ($isImage) {
        $generated = generateThumbnailFromImage(photoPath($filename), $thumbPath);
        if ($generated) {
            return ['url' => $thumbUrl, 'status' => 'ready', 'message' => '', 'previewUrl' => '/api/file.php?name=' . rawurlencode($filename)];
        }

        return ['url' => '/api/file.php?name=' . rawurlencode($filename), 'status' => 'fallback-original', 'message' => 'サムネ未生成（元画像表示）', 'previewUrl' => '/api/file.php?name=' . rawurlencode($filename)];
    }

    if ($isRaw) {
        $rawPath = photoPath($filename);
        $generated = generateThumbnailFromRaw($rawPath, $thumbPath);

        if ($generated) {
            return ['url' => $thumbUrl, 'status' => 'ready', 'message' => '', 'previewUrl' => $thumbUrl];
        }

        return [
            'url' => null,
            'status' => 'unavailable',
            'message' => 'RAWサムネ生成不可（ExifToolまたはRAW現像環境が必要）',
            'previewUrl' => null,
        ];
    }

    return ['url' => null, 'status' => 'unsupported', 'message' => '未対応形式', 'previewUrl' => null];
}

function ensureDir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function photoPath(string $filename): string
{
    return PHOTO_DIR . '/' . $filename;
}

function thumbPath(string $filename): string
{
    return THUMB_DIR . '/' . sha1($filename) . '.jpg';
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

function generateThumbnailFromRaw(string $rawPath, string $targetPath): bool
{
    $tempPreview = tempnam(sys_get_temp_dir(), 'raw_preview_');
    if ($tempPreview === false) {
        return false;
    }

    $commands = [
        sprintf('exiftool -b -PreviewImage %s > %s', escapeshellarg($rawPath), escapeshellarg($tempPreview)),
        sprintf('exiftool -b -JpgFromRaw %s > %s', escapeshellarg($rawPath), escapeshellarg($tempPreview)),
    ];

    foreach ($commands as $command) {
        @exec($command, $output, $code);
        if ($code === 0 && is_file($tempPreview) && filesize($tempPreview) > 0 && @getimagesize($tempPreview)) {
            $generated = generateThumbnailFromImage($tempPreview, $targetPath);
            @unlink($tempPreview);
            if ($generated) {
                return true;
            }
        }
        file_put_contents($tempPreview, '');
    }

    @unlink($tempPreview);

    return false;
}

function isSafeFilename(string $name): bool
{
    if ($name === '' || str_contains($name, '..') || str_contains($name, '/')) {
        return false;
    }

    return preg_match('/^[[:print:]]+$/u', $name) === 1;
}
