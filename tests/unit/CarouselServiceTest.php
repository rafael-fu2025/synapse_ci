<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CarouselSlideModel;
use App\Services\Carousel\CarouselAssetStore;
use App\Services\Carousel\CarouselService;
use App\Services\CurrentTenant;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class CarouselServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private array $ids = [];
    private array $files = [];
    private string $storeRoot;
    private CarouselAssetStore $assets;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
        // Keep the managed artwork out of the live private store.
        $this->storeRoot = sys_get_temp_dir() . '/carousel-service-' . bin2hex(random_bytes(6));
        $this->assets = new CarouselAssetStore($this->storeRoot);
    }

    protected function tearDown(): void
    {
        CurrentTenant::set(1);
        foreach ($this->ids as $id) {
            (new CarouselService(null, null, $this->assets))->deleteSlide($id);
        }
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->removeTree($this->storeRoot);
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }

    private function service(): CarouselService
    {
        return new CarouselService(null, null, $this->assets);
    }

    /** Simulate PHP's upload transport; real file content is still MIME-validated. */
    private function upload(): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'carousel-test-');
        copy(FCPATH . 'assets/img/brand/fuel-bg.jpg', $temp);
        $this->files[] = $temp;
        $file = $this->getMockBuilder(UploadedFile::class)
            ->setConstructorArgs([$temp, 'campus.jpg', 'image/jpeg', filesize($temp), UPLOAD_ERR_OK])
            ->onlyMethods(['isValid', 'move'])
            ->getMock();
        $file->method('isValid')->willReturn(true);
        $file->method('move')->willReturnCallback(function ($target, $name) use ($temp) {
            $path = $target . $name;
            $this->files[] = $path;
            return copy($temp, $path);
        });
        return $file;
    }

    public function testCreateAcceptsValidatedUploadWithoutLibraryPath(): void
    {
        $slide = $this->service()->createSlide(['title' => 'Uploaded'], $this->upload());
        $this->ids[] = (int) $slide['id'];
        $this->assertStringStartsWith('uploads/carousel/1/slide_light_', $slide['light_image']);
        $this->assertFileExists($this->assets->absolute($slide['light_image']));
        $this->assertNull($slide['dark_image']);
    }

    public function testUploadedArtworkNeverLandsInThePublicDirectory(): void
    {
        $slide = $this->service()->createSlide(['title' => 'Uploaded'], $this->upload());
        $this->ids[] = (int) $slide['id'];

        $absolute = $this->assets->absolute($slide['light_image']);
        $this->assertIsString($absolute);
        $this->assertStringStartsWith(realpath($this->storeRoot), $absolute);
        $this->assertFileDoesNotExist(FCPATH . $slide['light_image']);
    }

    public function testPublicArtworkIsDuplicatedIntoTheManagedStore(): void
    {
        $slide = $this->service()->createSlide(['light_image' => 'assets/img/brand/fuel-bg.jpg']);
        $this->ids[] = (int) $slide['id'];

        $this->assertStringStartsWith('uploads/carousel/1/imported_', $slide['light_image']);
        $this->assertFileExists($this->assets->absolute($slide['light_image']));
        // The committed original is left untouched.
        $this->assertFileExists(FCPATH . 'assets/img/brand/fuel-bg.jpg');
    }

    public function testReplacementRemovesOldUploadOnlyAfterSaving(): void
    {
        $service = $this->service();
        $slide = $service->createSlide([], $this->upload(), $this->upload());
        $this->ids[] = (int) $slide['id'];
        $oldLight = $this->assets->absolute($slide['light_image']);
        $oldDark = $this->assets->absolute($slide['dark_image']);
        $updated = $service->updateSlide((int) $slide['id'], ['dark_image' => null], $this->upload());
        $this->assertFileDoesNotExist($oldLight);
        $this->assertFileDoesNotExist($oldDark);
        $this->assertFileExists($this->assets->absolute($updated['light_image']));
        $this->assertNull($updated['dark_image']);
    }

    public function testInvalidEditKeepsExistingFilesAndData(): void
    {
        $service = $this->service();
        $slide = $service->createSlide(['title' => 'Original'], $this->upload());
        $this->ids[] = (int) $slide['id'];
        try {
            $service->updateSlide((int) $slide['id'], ['fit_mode' => 'invalid'], $this->upload());
            $this->fail('Invalid fit mode should be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Fit mode', $e->getMessage());
        }
        $this->assertFileExists($this->assets->absolute($slide['light_image']));
        $this->assertSame('Original', (new CarouselSlideModel())->find($slide['id'])['title']);
    }

    public function testUploadErrorIsNotSilentlyIgnoredOnEdit(): void
    {
        $service = $this->service();
        $slide = $service->createSlide(['light_image' => 'assets/img/brand/fuel-bg.jpg']);
        $this->ids[] = (int) $slide['id'];
        $this->expectException(\InvalidArgumentException::class);
        $service->updateSlide((int) $slide['id'], [], new UploadedFile('', 'failed.jpg', 'image/jpeg', 0, UPLOAD_ERR_INI_SIZE));
    }

    public function testOtherTenantCannotEditOrDeleteSlide(): void
    {
        $service = $this->service();
        $slide = $service->createSlide(['light_image' => 'assets/img/brand/fuel-bg.jpg']);
        $this->ids[] = (int) $slide['id'];
        CurrentTenant::set(999999);
        $this->assertNull($service->updateSlide((int) $slide['id'], ['title' => 'Intrusion']));
        $this->assertFalse($service->deleteSlide((int) $slide['id']));
        CurrentTenant::set(1);
        $this->assertNotNull((new CarouselSlideModel())->find($slide['id']));
    }

    public function testThePermanentDefaultImageIsNeverRemovedWithItsSlide(): void
    {
        $service = $this->service();
        $this->assertTrue($this->assets->ensureDefaultAssets());

        $slide = $service->createSlide(['light_image' => CarouselAssetStore::DEFAULT_LIGHT_ASSET]);
        $this->ids[] = (int) $slide['id'];

        $this->assertTrue($service->deleteSlide((int) $slide['id'], ['role' => 'superadmin', 'permissions' => []]));
        foreach ($this->assets->defaultAssets() as $asset) {
            $this->assertFileExists(
                $this->assets->absolute($asset),
                'Deleting a slide must never remove the permanent default artwork.'
            );
        }
    }
}
