<?php
/**
 * Generate PWA icons for EasyRide from the configured app logo.
 * Run: php generate_pwa_icons.php
 */

$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$outDir = __DIR__ . '/assets/icons';
$logoFile = $argv[1] ?? 'logo_1771739763.jpg';
$logoPath = __DIR__ . '/uploads/logos/' . $logoFile;

if (!$logoFile || !is_file($logoPath)) {
    fwrite(STDERR, "Logo not found: $logoPath\n");
    exit(1);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension is required.\n");
    exit(1);
}

if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$source = loadImage($logoPath);
$srcW = imagesx($source);
$srcH = imagesy($source);

foreach ($sizes as $size) {
    $img = createIconFromLogo($source, $srcW, $srcH, $size, false);
    savePng($img, "$outDir/icon-{$size}.png");
    imagedestroy($img);
    echo "Created icon-{$size}.png from {$logoFile}\n";
}

$img = createIconFromLogo($source, $srcW, $srcH, 512, true);
savePng($img, "$outDir/icon-maskable-512.png");
imagedestroy($img);
imagedestroy($source);

echo "Created icon-maskable-512.png from {$logoFile}\n";
echo "\nAll icons generated in $outDir\n";

function loadImage(string $path): GdImage {
    $info = getimagesize($path);
    if (!$info) {
        throw new RuntimeException("Unsupported image: $path");
    }

    return match ($info[2]) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($path),
        IMAGETYPE_PNG => imagecreatefrompng($path),
        IMAGETYPE_GIF => imagecreatefromgif($path),
        default => throw new RuntimeException("Unsupported image type: {$info[2]}"),
    };
}

function createIconFromLogo(GdImage $source, int $srcW, int $srcH, int $size, bool $maskable): GdImage {
    $img = imagecreatetruecolor($size, $size);
    imagesavealpha($img, true);

    $white = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $white);

    $padding = (int)round($size * ($maskable ? 0.22 : 0.12));
    $maxW = $size - ($padding * 2);
    $maxH = $size - ($padding * 2);

    $scale = min($maxW / $srcW, $maxH / $srcH);
    $dstW = max(1, (int)round($srcW * $scale));
    $dstH = max(1, (int)round($srcH * $scale));
    $dstX = (int)round(($size - $dstW) / 2);
    $dstY = (int)round(($size - $dstH) / 2);

    imagecopyresampled($img, $source, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);

    return $img;
}

function savePng(GdImage $img, string $path): void {
    if (is_file($path) && !is_writable($path)) {
        throw new RuntimeException("Icon is not writable: $path");
    }

    if (!imagepng($img, $path)) {
        throw new RuntimeException("Failed to write icon: $path");
    }
}
