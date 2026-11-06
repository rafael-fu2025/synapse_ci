<?php

declare(strict_types=1);

namespace App\Services\Carousel;

use CodeIgniter\HTTP\Files\UploadedFile;
use GdImage;
use RuntimeException;

/**
 * Managed store for login carousel artwork.
 *
 * Artwork lives outside the web root in a tenant-scoped private directory and
 * is published only through CarouselAssetController, which resolves the logical
 * path recorded in carousel_slides. Nothing under the store is writable through
 * the public web server.
 *
 * The permanent default images occupy the reserved "default" scope. Uploads
 * never target that scope, and removal refuses it outright, so the defaults
 * cannot be replaced or destroyed by an ordinary management request.
 */
final class CarouselAssetStore
{
    /** Logical namespace recorded in carousel_slides for managed artwork. */
    public const NAMESPACE = 'uploads/carousel';

    /** Reserved scope holding the permanent default login images. */
    public const DEFAULT_SCOPE = 'default';

    /** File name of the permanent light-mode default login image. */
    public const DEFAULT_LIGHT_FILE = 'FoundationU-Light.jpg';

    /** File name of the permanent dark-mode default login image. */
    public const DEFAULT_DARK_FILE = 'FoundationU-Dark.png';

    /** Logical path of the permanent light-mode default login image. */
    public const DEFAULT_LIGHT_ASSET = self::NAMESPACE . '/' . self::DEFAULT_SCOPE . '/' . self::DEFAULT_LIGHT_FILE;

    /** Logical path of the permanent dark-mode default login image. */
    public const DEFAULT_DARK_ASSET = self::NAMESPACE . '/' . self::DEFAULT_SCOPE . '/' . self::DEFAULT_DARK_FILE;

    /** Committed public source that seeds the managed copy of the light default. */
    public const DEFAULT_LIGHT_SOURCE = 'assets/img/brand/FoundationU-Light.jpg';

    /** Committed public source that seeds the managed copy of the dark default. */
    public const DEFAULT_DARK_SOURCE = 'assets/img/brand/FoundationU-Dark.png';

    /** Committed public directory that still holds the pre-migration brand artwork. */
    public const PUBLIC_SOURCE = 'assets/img/brand';

    /**
     * The permanent default pair, keyed by its managed path.
     *
     * The light and dark slots are installed together: a login hero that only
     * has one of the two would fall back to the light artwork at night.
     *
     * @var array<string, string>
     */
    private const DEFAULT_SOURCES = [
        self::DEFAULT_LIGHT_ASSET => self::DEFAULT_LIGHT_SOURCE,
        self::DEFAULT_DARK_ASSET  => self::DEFAULT_DARK_SOURCE,
    ];

    /** Legacy public upload directory that pre-migration carousel uploads used. */
    public const LEGACY_UPLOAD = 'uploads/carousel';

    private const SCOPE_PATTERN = '(?:[1-9][0-9]{0,9}|' . self::DEFAULT_SCOPE . ')';
    private const FILE_PATTERN = '[A-Za-z0-9_-][A-Za-z0-9._-]*\.(?i:png|jpe?g|webp|gif)';

    /** Longest edge kept for the installed default image. */
    private const MAX_EDGE = 1600;

    /** Encoder quality used for the installed default images. */
    private const ENCODER_QUALITY = 82;

    /** A managed path: uploads/carousel/{scope}/{file}. */
    public const MANAGED_PATTERN = '#\Auploads/carousel/' . self::SCOPE_PATTERN . '/' . self::FILE_PATTERN . '\z#D';

    /** A legacy public upload path: uploads/carousel/{file}. */
    public const LEGACY_PATTERN = '#\Auploads/carousel/' . self::FILE_PATTERN . '\z#D';

    /** A committed public brand asset path. */
    public const PUBLIC_PATTERN = '#\Aassets/img/brand/(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*' . self::FILE_PATTERN . '\z#D';

    /**
     * Every image path that may be published to a client.
     *
     * Managed artwork is addressed by its tenant scope; the reserved default
     * scope is published like any other.
     */
    public const PUBLISHED_PATTERN = '#\A(?:assets/img/brand/(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*'
        . '|uploads/carousel/' . self::SCOPE_PATTERN . '/[A-Za-z0-9_-][A-Za-z0-9._-]*'
        . '|uploads/carousel/[A-Za-z0-9_-][A-Za-z0-9._-]*)\.(?i:png|jpe?g|webp|gif)\z#D';

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? config('Carousel')->storeRoot, '/\\');
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * The logical paths of the permanent default login images.
     *
     * @return array{light: string, dark: string}
     */
    public function defaultAssets(): array
    {
        return ['light' => self::DEFAULT_LIGHT_ASSET, 'dark' => self::DEFAULT_DARK_ASSET];
    }

    /** True when the logical path addresses the reserved default scope. */
    public function isDefault(?string $logicalPath): bool
    {
        return is_string($logicalPath)
            && str_starts_with($logicalPath, self::NAMESPACE . '/' . self::DEFAULT_SCOPE . '/');
    }

    /** True when the logical path addresses an asset inside the managed store. */
    public function isManaged(?string $logicalPath): bool
    {
        return is_string($logicalPath) && preg_match(self::MANAGED_PATTERN, $logicalPath) === 1;
    }

    /** True when the logical path is an accepted source for a slide image. */
    public function isAllowed(?string $logicalPath): bool
    {
        return is_string($logicalPath) && $logicalPath !== '' && strlen($logicalPath) <= 255
            && (preg_match(self::MANAGED_PATTERN, $logicalPath) === 1
                || preg_match(self::LEGACY_PATTERN, $logicalPath) === 1
                || preg_match(self::PUBLIC_PATTERN, $logicalPath) === 1);
    }

    /**
     * Split a managed logical path into its scope and file name.
     *
     * @return array{scope: string, file: string}|null
     */
    public function parseManaged(string $logicalPath): ?array
    {
        if (preg_match(self::MANAGED_PATTERN, $logicalPath) !== 1) {
            return null;
        }

        $parts = explode('/', $logicalPath);

        return ['scope' => $parts[2], 'file' => $parts[3]];
    }

    /**
     * Absolute filesystem path of a managed asset, or null when the path is
     * not managed or the file is missing. Containment is re-checked against the
     * resolved root so a symlinked entry can never escape the store.
     */
    public function absolute(string $logicalPath): ?string
    {
        $parsed = $this->parseManaged($logicalPath);
        if ($parsed === null) {
            return null;
        }

        $directory = $this->scopeDirectory($parsed['scope'], false);
        if ($directory === null) {
            return null;
        }

        $candidate = $directory . DIRECTORY_SEPARATOR . $parsed['file'];
        if (is_link($candidate)) {
            return null;
        }

        $resolved = realpath($candidate);
        $root = realpath($directory);
        if ($resolved === false || $root === false || !is_file($resolved)
            || dirname($resolved) !== $root
        ) {
            return null;
        }

        return $resolved;
    }

    public function exists(string $logicalPath): bool
    {
        return $this->absolute($logicalPath) !== null;
    }

    /**
     * Absolute directory of a managed scope.
     *
     * Reads never create directories, so a public asset request cannot make a
     * directory appear. Creation is reserved for writes.
     *
     * The reserved default scope is not a write target for uploads; only the
     * installer may place files there.
     */
    public function scopeDirectory(string $scope, bool $create = true): ?string
    {
        if ($scope !== self::DEFAULT_SCOPE && preg_match('#\A[1-9][0-9]{0,9}\z#D', $scope) !== 1) {
            return null;
        }

        $directory = $this->root . DIRECTORY_SEPARATOR . $scope;
        if (is_dir($directory)) {
            return $directory;
        }
        if (!$create) {
            return null;
        }
        if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return null;
        }

        return $directory;
    }

    /**
     * Store an uploaded image in the tenant scope and return its logical path.
     */
    public function store(UploadedFile $file, string $variant, int $tenantId): string
    {
        $scope = (string) max(1, $tenantId);
        $directory = $this->scopeDirectory($scope);
        if ($directory === null) {
            throw new RuntimeException('Unable to prepare the carousel image store.', 503);
        }

        $extension = $this->rasterExtension($file->getTempName());
        do {
            $name = 'slide_' . $variant . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
        } while (file_exists($directory . DIRECTORY_SEPARATOR . $name));

        if (!$file->move($directory . DIRECTORY_SEPARATOR, $name)) {
            throw new RuntimeException('Unable to move carousel image upload.');
        }

        @chmod($directory . DIRECTORY_SEPARATOR . $name, 0600);

        return self::NAMESPACE . '/' . $scope . '/' . $name;
    }

    /**
     * Copy a committed public asset into the managed store.
     *
     * The managed name is derived from the source path and its content, so the
     * same public artwork always maps onto the same managed copy.
     */
    public function import(string $publicPath, int $tenantId): ?string
    {
        if (!is_string($publicPath) || $publicPath === '' || strlen($publicPath) > 255
            || (preg_match(self::PUBLIC_PATTERN, $publicPath) !== 1
                && preg_match(self::LEGACY_PATTERN, $publicPath) !== 1)
        ) {
            return null;
        }

        $source = realpath(FCPATH . $publicPath);
        $publicRoot = realpath(FCPATH);
        if ($source === false || $publicRoot === false || !is_file($source)
            || !str_starts_with($source, $publicRoot . DIRECTORY_SEPARATOR)
            || is_link(FCPATH . $publicPath)
        ) {
            return null;
        }

        try {
            $extension = $this->rasterExtension($source);
        } catch (RuntimeException) {
            return null;
        }

        $scope = (string) max(1, $tenantId);
        $directory = $this->scopeDirectory($scope);
        if ($directory === null) {
            return null;
        }

        $name = 'imported_' . substr(hash('sha256', $publicPath . '|' . (string) @filesize($source)), 0, 32) . '.' . $extension;
        $destination = $directory . DIRECTORY_SEPARATOR . $name;

        if (!is_file($destination) && !@copy($source, $destination)) {
            return null;
        }
        @chmod($destination, 0600);

        return self::NAMESPACE . '/' . $scope . '/' . $name;
    }

    /**
     * Install the permanent default login images into the managed store.
     *
     * The committed public sources are the single origin of the default
     * artwork; the managed copies are what the application publishes.
     */
    public function ensureDefaultAssets(): bool
    {
        $installed = true;
        foreach (self::DEFAULT_SOURCES as $asset => $source) {
            if (!$this->exists($asset) && !$this->installAsset($asset, $source)) {
                $installed = false;
            }
        }

        return $installed;
    }

    /** Copy one committed public source onto its reserved managed path. */
    private function installAsset(string $asset, string $source): bool
    {
        $parsed = $this->parseManaged($asset);
        if ($parsed === null) {
            return false;
        }

        $directory = $this->scopeDirectory($parsed['scope']);
        if ($directory === null) {
            return false;
        }

        $origin = realpath(FCPATH . $source);
        $publicRoot = realpath(FCPATH);
        if ($origin === false || $publicRoot === false || !is_file($origin)
            || !str_starts_with($origin, $publicRoot . DIRECTORY_SEPARATOR)
        ) {
            return false;
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.installing-' . bin2hex(random_bytes(8));
        if (!@copy($origin, $temporary)) {
            return false;
        }
        @chmod($temporary, 0600);

        $destination = $directory . DIRECTORY_SEPARATOR . $parsed['file'];
        if (!@rename($temporary, $destination)) {
            @unlink($temporary);
            return false;
        }
        @chmod($destination, 0600);

        // The login hero is published through PHP, so keep the installed copy
        // light enough to fetch on every sign-in page load.
        $this->boundInstalledCopy($destination);

        return $this->exists($asset);
    }

    /**
     * Re-encode an installed image as a bounded copy when GD is available.
     *
     * The original artwork is left untouched; only the managed copy is
     * reduced, and it keeps its own format so the published Content-Type still
     * matches the bytes on disk. Failure keeps the full-size copy in place.
     */
    private function boundInstalledCopy(string $path): void
    {
        if (!extension_loaded('gd')) {
            return;
        }

        $info = @getimagesize($path);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return;
        }

        [$width, $height] = $info;
        $scale = min(1, self::MAX_EDGE / max($width, $height));
        if ($scale >= 1) {
            return;
        }

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        };
        if (!$source instanceof GdImage) {
            return;
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        $copied = @imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);
        if (!$copied) {
            imagedestroy($canvas);
            return;
        }

        $temporary = $path . '.bounded';
        $written = match ($info[2]) {
            IMAGETYPE_PNG  => @imagepng($canvas, $temporary, 6),
            IMAGETYPE_WEBP => @imagewebp($canvas, $temporary, self::ENCODER_QUALITY),
            default        => @imagejpeg($canvas, $temporary, self::ENCODER_QUALITY),
        };
        imagedestroy($canvas);
        if (!$written) {
            @unlink($temporary);
            return;
        }

        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    /**
     * Remove a managed asset.
     *
     * The permanent default images are never removable through this method.
     */
    public function remove(string $logicalPath): bool
    {
        if ($this->isDefault($logicalPath)) {
            return false;
        }

        $absolute = $this->absolute($logicalPath);
        if ($absolute === null) {
            return false;
        }

        return @unlink($absolute);
    }

    /** Detect the raster type of a file on disk. */
    public function rasterExtension(string $path): string
    {
        $extensions = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $image = @getimagesize($path);
        if (!isset($extensions[$mime]) || $image === false || ($image['mime'] ?? null) !== $mime) {
            throw new RuntimeException('Carousel images must be PNG, JPEG, WEBP, or GIF raster images.');
        }

        return $extensions[$mime];
    }
}
