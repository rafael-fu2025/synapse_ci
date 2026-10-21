<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProfilePhotoService;
use CodeIgniter\HTTP\Files\UploadedFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProfilePhotoServiceTest extends TestCase
{
    private string $directory;
    private ProfilePhotoService $photos;

    protected function setUp(): void
    {
        $this->assertTrue(extension_loaded('gd') && function_exists('imagewebp'), 'GD with WebP is required.');
        $this->directory = sys_get_temp_dir() . '/synapse-photo-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->photos = new ProfilePhotoService($this->directory . '/storage');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($this->directory);
    }

    private function upload(string $path, bool $valid = true): UploadedFile
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn($valid);
        $file->method('hasMoved')->willReturn(false);
        $file->method('getTempName')->willReturn($path);
        return $file;
    }

    private function image(string $format, int $width = 2400, int $height = 1200): string
    {
        $path = $this->directory . '/original.' . $format;
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, 0x800000);
        match ($format) {
            'jpg' => imagejpeg($image, $path, 95),
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path, 95),
        };
        imagedestroy($image);
        return $path;
    }

    public function testSupportedImagesAreResizedReencodedAndPrivatelyScoped(): void
    {
        foreach (['jpg', 'png', 'webp'] as $format) {
            $original = $this->image($format);
            $hash = hash_file('sha256', $original);
            $result = $this->photos->save($this->upload($original), 17, 42);
            $path = $this->photos->path(17, 42);
            $this->assertSame([1024, 512], [$result['width'], $result['height']]);
            $this->assertSame(IMAGETYPE_WEBP, getimagesize($path)[2]);
            $this->assertLessThanOrEqual(ProfilePhotoService::MAX_STORED_BYTES, filesize($path));
            $this->assertSame($hash, hash_file('sha256', $original), 'Processing never modifies the temporary upload.');
            $this->assertTrue($this->photos->exists(17, 42));
            $this->assertFalse($this->photos->exists(18, 42));
            $this->assertFalse($this->photos->exists(17, 43));
            $this->assertSame([], glob(dirname($path) . '/.processing-*'));
        }
    }

    public function testExactlyTenMegabytesAreAcceptedAndAnExtraByteIsRejectedWithoutReplacingPhoto(): void
    {
        $original = $this->image('jpg', 200, 100);
        $handle = fopen($original, 'ab');
        ftruncate($handle, ProfilePhotoService::MAX_UPLOAD_BYTES);
        fclose($handle);
        $this->photos->save($this->upload($original), 17, 42);
        $saved = hash_file('sha256', $this->photos->path(17, 42));
        file_put_contents($original, 'x', FILE_APPEND);
        clearstatcache(true, $original);
        try {
            $this->photos->save($this->upload($original), 17, 42);
            $this->fail('Over-limit upload accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame(422, $error->getCode());
            $this->assertStringContainsString('10 MB', $error->getMessage());
        }
        $this->assertSame($saved, hash_file('sha256', $this->photos->path(17, 42)));
    }

    public function testInvalidTypesAndPartialUploadsRetainThePreviousPhoto(): void
    {
        $this->photos->save($this->upload($this->image('png', 20, 20)), 17, 42);
        $saved = hash_file('sha256', $this->photos->path(17, 42));
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"></svg>', '<?php echo "not a photo";', '', 'GIF89a invalid'] as $content) {
            $path = $this->directory . '/fake.jpg';
            file_put_contents($path, $content);
            try {
                $this->photos->save($this->upload($path), 17, 42);
                $this->fail('Invalid image accepted.');
            } catch (RuntimeException $error) { $this->assertSame(422, $error->getCode()); }
            $this->assertSame($saved, hash_file('sha256', $this->photos->path(17, 42)));
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->photos->save($this->upload($this->image('jpg', 20, 20), false), 17, 42);
    }

    public function testExcessiveDimensionsAreRejectedBeforeDecoding(): void
    {
        $path = $this->image('png', 1, 1);
        $bytes = file_get_contents($path);
        $ihdr = 'IHDR' . pack('NN', 10000, 10000) . substr($bytes, 24, 5);
        file_put_contents($path, substr($bytes, 0, 12) . $ihdr . pack('N', crc32($ihdr)) . substr($bytes, 33));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->photos->save($this->upload($path), 17, 42);
    }

    public function testJpegOrientationIsAppliedAndMetadataIsDiscarded(): void
    {
        $path = $this->image('jpg', 200, 100);
        $original = file_get_contents($path);
        $tiff = 'II' . pack('vV', 42, 8) . pack('v', 1) . pack('vvVvvV', 0x0112, 3, 1, 6, 0, 0);
        $metadata = "Exif\0\0" . $tiff . 'private GPS and camera metadata';
        file_put_contents($path, substr($original, 0, 2) . "\xff\xe1" . pack('n', strlen($metadata) + 2) . $metadata . substr($original, 2));
        $result = $this->photos->save($this->upload($path), 17, 42);
        $this->assertSame([100, 200], [$result['width'], $result['height']]);
        $this->assertStringNotContainsString('private GPS', file_get_contents($this->photos->path(17, 42)));
        $this->assertStringNotContainsString('Exif', file_get_contents($this->photos->path(17, 42)));
    }

    public function testNoisyImageIsReducedUntilStoredSizeFitsTheBudget(): void
    {
        $path = $this->directory . '/noise.png';
        $image = imagecreatetruecolor(1024, 1024);
        for ($y = 0; $y < 1024; ++$y) {
            $row = random_bytes(1024 * 3);
            for ($x = 0; $x < 1024; ++$x) imagesetpixel($image, $x, $y, (ord($row[$x * 3]) << 16) | (ord($row[$x * 3 + 1]) << 8) | ord($row[$x * 3 + 2]));
        }
        imagepng($image, $path); imagedestroy($image);
        $result = $this->photos->save($this->upload($path), 17, 42);
        $this->assertLessThan(1024, $result['width']);
        $this->assertLessThanOrEqual(ProfilePhotoService::MAX_STORED_BYTES, $result['bytes']);
    }

    public function testSmallImagesAreNotUpscaledAndFailedReplacementCleansTemporaryFiles(): void
    {
        $file = $this->upload($this->image('jpg', 32, 16));
        $result = $this->photos->save($file, 17, 42);
        $this->assertSame([32, 16], [$result['width'], $result['height']]);
        $saved = hash_file('sha256', $this->photos->path(17, 42));
        mkdir($this->photos->path(17, 43));
        try {
            $this->photos->save($file, 17, 43);
            $this->fail('A failed atomic replacement must not be reported as saved.');
        } catch (RuntimeException $error) { $this->assertSame(503, $error->getCode()); }
        $this->assertSame($saved, hash_file('sha256', $this->photos->path(17, 42)));
        $this->assertSame([], glob(dirname($this->photos->path(17, 42)) . '/.processing-*'));
    }

    public function testRuntimeMemoryLimitIsCheckedBeforeDecoding(): void
    {
        $path = $this->image('png', 1, 1);
        $bytes = file_get_contents($path);
        $ihdr = 'IHDR' . pack('NN', 4000, 3000) . substr($bytes, 24, 5);
        file_put_contents($path, substr($bytes, 0, 12) . $ihdr . pack('N', crc32($ihdr)) . substr($bytes, 33));
        $previous = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 32 * 1024 * 1024));
        try {
            $this->photos->save($this->upload($path), 17, 42);
            $this->fail('Unsafe memory estimate accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame(422, $error->getCode());
            $this->assertStringContainsString('memory', $error->getMessage());
        } finally { ini_set('memory_limit', $previous); }
    }

    public function testRemovalIsIdempotentAndCannotAffectOtherOwners(): void
    {
        $file = $this->upload($this->image('jpg', 20, 20));
        $this->photos->save($file, 17, 42);
        $this->photos->save($file, 18, 42);
        $this->photos->save($file, 17, 43);
        $this->photos->remove(17, 42); $this->photos->remove(17, 42);
        $this->assertFalse($this->photos->exists(17, 42));
        $this->assertTrue($this->photos->exists(18, 42));
        $this->assertTrue($this->photos->exists(17, 43));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->photos->path(0, 42);
    }
}
