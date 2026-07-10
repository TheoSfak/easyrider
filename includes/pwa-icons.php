<?php
/**
 * EasyRide - Shared PWA icon regeneration
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Resizes $source onto a square transparent canvas of $size x $size,
 * centered, preserving aspect ratio (letterboxed if source isn't square).
 */
function pwaIconSquareResize($source, int $srcW, int $srcH, int $size): \GdImage {
    $canvas = imagecreatetruecolor($size, $size);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);

    $scale = min($size / $srcW, $size / $srcH);
    $destW = (int) round($srcW * $scale);
    $destH = (int) round($srcH * $scale);
    $destX = (int) (($size - $destW) / 2);
    $destY = (int) (($size - $destH) / 2);

    imagecopyresampled($canvas, $source, $destX, $destY, 0, 0, $destW, $destH, $srcW, $srcH);
    return $canvas;
}

/**
 * Loads an image file via the correct GD loader based on its detected MIME
 * type. Supports exactly the four formats Settings' logo upload accepts:
 * JPEG, PNG, GIF, WebP. Returns null if the file can't be read or its MIME
 * type isn't one of these four.
 */
function pwaIconLoadSource(string $path): ?\GdImage {
    if (!file_exists($path)) {
        return null;
    }
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
    } else {
        $mime = mime_content_type($path);
    }
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/gif' => @imagecreatefromgif($path),
        'image/webp' => @imagecreatefromwebp($path),
        default => false,
    };
    return $image ?: null;
}

/**
 * Regenerates the full PWA icon set (assets/icons/icon-*.png plus the
 * maskable variant) from a source image at $sourcePath. Returns true on
 * success, false on any failure -- never throws, so callers (a web upload
 * handler or a CLI script) can degrade gracefully instead of crashing.
 */
function regeneratePwaIconsFromImage(string $sourcePath): bool {
    $source = pwaIconLoadSource($sourcePath);
    if (!$source) {
        return false;
    }
    imagesavealpha($source, true);
    $srcW = imagesx($source);
    $srcH = imagesy($source);
    if ($srcW < 1 || $srcH < 1) {
        imagedestroy($source);
        return false;
    }

    $iconSizes = [72, 96, 128, 144, 152, 192, 384, 512];
    foreach ($iconSizes as $size) {
        $canvas = pwaIconSquareResize($source, $srcW, $srcH, $size);
        $ok = imagepng($canvas, __DIR__ . "/../assets/icons/icon-{$size}.png");
        imagedestroy($canvas);
        if (!$ok) {
            imagedestroy($source);
            return false;
        }
    }

    // Maskable icon: solid brand-black background, badge scaled to 80% of
    // the canvas so the OS's circular/rounded-square mask never clips the
    // emblem (standard maskable-icon safe-zone guideline).
    $maskableSize = 512;
    $maskable = imagecreatetruecolor($maskableSize, $maskableSize);
    $bg = imagecolorallocate($maskable, 0x14, 0x11, 0x10); // --ez-black
    imagefill($maskable, 0, 0, $bg);
    $safeZone = (int) round($maskableSize * 0.8);
    $scale = min($safeZone / $srcW, $safeZone / $srcH);
    $destW = (int) round($srcW * $scale);
    $destH = (int) round($srcH * $scale);
    $destX = (int) (($maskableSize - $destW) / 2);
    $destY = (int) (($maskableSize - $destH) / 2);
    imagecopyresampled($maskable, $source, $destX, $destY, 0, 0, $destW, $destH, $srcW, $srcH);
    $ok = imagepng($maskable, __DIR__ . '/../assets/icons/icon-maskable-512.png');
    imagedestroy($maskable);
    imagedestroy($source);

    return $ok;
}
