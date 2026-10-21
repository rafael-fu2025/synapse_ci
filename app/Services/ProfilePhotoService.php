<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use GdImage;
use RuntimeException;

/** Private, person-owned photos. Never retain or serve the original upload. */
final class ProfilePhotoService
{
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
    public const MAX_STORED_BYTES = 300 * 1024;
    public const MAX_PIXELS = 20_000_000;
    public const MAX_EDGE = 1024;

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? WRITEPATH . 'profile-photos', '/\\');
    }

    /** IDs must come from the refreshed identity, never request data. */
    public function path(int $tenantId, int $personId): string
    {
        if ($tenantId <= 0 || $personId <= 0) {
            throw new RuntimeException('An identified university profile is required.', 403);
        }
        return $this->directory . DIRECTORY_SEPARATOR . $tenantId . DIRECTORY_SEPARATOR . $personId . '.webp';
    }

    public function exists(int $tenantId, int $personId): bool
    {
        return $tenantId > 0 && $personId > 0 && is_file($this->path($tenantId, $personId));
    }

    public function save(UploadedFile $upload, int $tenantId, int $personId): array
    {
        if (!$upload->isValid() || $upload->hasMoved()) {
            throw new RuntimeException('Choose a complete JPEG, PNG, or WebP image up to 10 MB.', 422);
        }
        $path = $upload->getTempName();
        $bytes = is_file($path) ? filesize($path) : false;
        if ($bytes === false || $bytes < 1 || $bytes > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('The original profile picture must be 10 MB or smaller.', 422);
        }
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('Photo processing is unavailable. Please contact the administrator.', 503);
        }
        $info = @getimagesize($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $types = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
        if ($info === false || !isset($types[$info[2]]) || $mime !== $types[$info[2]]) {
            throw new RuntimeException('Upload a valid JPEG, PNG, or WebP image. SVG, GIF, and HEIC are not supported.', 422);
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > 10000 || $height > 10000 || $width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('The image must be at most 20 megapixels and 10,000 pixels per side.', 422);
        }
        // Allow for decoder workspace, the resized canvas, and framework overhead.
        $limit = self::memoryBytes(ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $width * $height * 6 + 24 * 1024 * 1024 > $limit) {
            throw new RuntimeException('This image needs too much processing memory. Resize it before uploading.', 422);
        }
        $destination = $this->path($tenantId, $personId);
        $folder = dirname($destination);
        if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new RuntimeException('The photo could not be stored. Please try again later.', 503);
        }
        $temporary = @tempnam($folder, '.processing-');
        if ($temporary === false || realpath(dirname($temporary)) !== realpath($folder)) {
            // tempnam can fall back to the system temp directory; do not permit cross-volume replacement.
            if ($temporary !== false) @unlink($temporary);
            throw new RuntimeException('The photo could not be stored. Please try again later.', 503);
        }
        @chmod($temporary, 0600);
        $source = null;
        $canvas = null;
        try {
            $source = match ($info[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                IMAGETYPE_PNG => @imagecreatefrompng($path),
                IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            };
            if (!$source instanceof GdImage) {
                throw new RuntimeException('The image could not be decoded. Choose another JPEG, PNG, or WebP file.', 422);
            }
            if ($info[2] === IMAGETYPE_JPEG) {
                $source = $this->orient($source, $path);
                $width = imagesx($source);
                $height = imagesy($source);
            }
            foreach ([[1024, 82], [1024, 70], [768, 70], [512, 65]] as [$edge, $quality]) {
                $scale = min(1, $edge / max($width, $height));
                $outWidth = max(1, (int) round($width * $scale));
                $outHeight = max(1, (int) round($height * $scale));
                $canvas = imagecreatetruecolor($outWidth, $outHeight);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
                if (!imagecopyresampled($canvas, $source, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height)
                    || !imagewebp($canvas, $temporary, $quality)) {
                    throw new RuntimeException('The photo could not be compressed. Your current photo was kept.', 503);
                }
                imagedestroy($canvas);
                $canvas = null;
                clearstatcache(true, $temporary);
                $stored = filesize($temporary);
                $encoded = @getimagesize($temporary);
                if ($stored > 0 && $stored <= self::MAX_STORED_BYTES && ($encoded[2] ?? null) === IMAGETYPE_WEBP) {
                    // Same-filesystem atomic replacement: failures leave the prior photo untouched.
                    if (!@rename($temporary, $destination)) {
                        throw new RuntimeException('The photo could not be saved. Your current photo was kept.', 503);
                    }
                    @chmod($destination, 0600);
                    return ['bytes' => $stored, 'width' => $outWidth, 'height' => $outHeight];
                }
            }
            throw new RuntimeException('The photo could not be compressed safely. Choose a smaller image.', 422);
        } finally {
            if ($source instanceof GdImage) imagedestroy($source);
            if ($canvas instanceof GdImage) imagedestroy($canvas);
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    public function remove(int $tenantId, int $personId): void
    {
        $path = $this->path($tenantId, $personId);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('The photo could not be removed. Please try again later.', 503);
        }
    }

    /** Read only the bounded JPEG EXIF orientation; GD's new canvas discards all metadata. */
    private function orient(GdImage $source, string $path): GdImage
    {
        $orientation = 1;
        $handle = fopen($path, 'rb');
        if ($handle === false) return $source;
        try {
            if (fread($handle, 2) !== "\xff\xd8") return $source;
            for ($segment = 0; $segment < 128; ++$segment) {
                $marker = fread($handle, 2);
                if (strlen($marker) !== 2 || $marker[0] !== "\xff" || in_array(ord($marker[1]), [0xda, 0xd9], true)) break;
                $length = fread($handle, 2);
                if (strlen($length) !== 2) break;
                $size = unpack('n', $length)[1] - 2;
                if ($size < 0) break;
                $data = $size ? fread($handle, $size) : '';
                if (strlen($data) !== $size) break;
                if ($marker !== "\xff\xe1" || !str_starts_with($data, "Exif\0\0")) continue;
                $tiff = substr($data, 6);
                $little = substr($tiff, 0, 2) === 'II';
                if (!$little && substr($tiff, 0, 2) !== 'MM') break;
                $read = static function (int $offset, bool $long = false) use ($tiff, $little): ?int {
                    $size = $long ? 4 : 2;
                    if ($offset < 0 || $offset + $size > strlen($tiff)) return null;
                    return unpack($long ? ($little ? 'V' : 'N') : ($little ? 'v' : 'n'), substr($tiff, $offset, $size))[1];
                };
                if ($read(2) !== 42) break;
                $offset = $read(4, true);
                if ($offset === null) break;
                $count = min($read($offset) ?? 0, 512);
                for ($i = 0; $i < $count; ++$i) {
                    $entry = $offset + 2 + $i * 12;
                    if ($read($entry) === 0x0112 && $read($entry + 2) === 3 && $read($entry + 4, true) === 1) {
                        $orientation = $read($entry + 8) ?? 1;
                        break;
                    }
                }
                break;
            }
        } finally { fclose($handle); }
        if (in_array($orientation, [2, 4], true)) imageflip($source, $orientation === 2 ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        $angle = match ($orientation) { 3 => 180, 5, 6 => 270, 7, 8 => 90, default => 0 };
        if ($angle) {
            $rotated = imagerotate($source, $angle, 0);
            if ($rotated === false) throw new RuntimeException('The image could not be oriented. Choose another photo.', 422);
            imagedestroy($source);
            $source = $rotated;
            if (in_array($orientation, [5, 7], true)) imageflip($source, IMG_FLIP_HORIZONTAL);
        }
        return $source;
    }

    private static function memoryBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') return -1;
        $factor = match (strtolower(substr($value, -1))) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 };
        return (int) ((float) $value * $factor);
    }
}
