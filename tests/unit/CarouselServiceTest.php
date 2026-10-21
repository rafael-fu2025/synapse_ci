<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CarouselSlideModel;
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

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
    }

    protected function tearDown(): void
    {
        CurrentTenant::set(1);
        foreach ($this->ids as $id) {
            (new CarouselService())->deleteSlide($id);
        }
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        CurrentTenant::reset();
        parent::tearDown();
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
        $slide = (new CarouselService())->createSlide(['title' => 'Uploaded'], $this->upload());
        $this->ids[] = (int) $slide['id'];
        $this->assertStringStartsWith('uploads/carousel/slide_light_', $slide['light_image']);
        $this->assertFileExists(FCPATH . $slide['light_image']);
        $this->assertNull($slide['dark_image']);
    }

    public function testReplacementRemovesOldUploadOnlyAfterSaving(): void
    {
        $service = new CarouselService();
        $slide = $service->createSlide([], $this->upload(), $this->upload());
        $this->ids[] = (int) $slide['id'];
        $oldLight = FCPATH . $slide['light_image'];
        $oldDark = FCPATH . $slide['dark_image'];
        $updated = $service->updateSlide((int) $slide['id'], ['dark_image' => null], $this->upload());
        $this->assertFileDoesNotExist($oldLight);
        $this->assertFileDoesNotExist($oldDark);
        $this->assertFileExists(FCPATH . $updated['light_image']);
        $this->assertNull($updated['dark_image']);
    }

    public function testInvalidEditKeepsExistingFilesAndData(): void
    {
        $service = new CarouselService();
        $slide = $service->createSlide(['title' => 'Original'], $this->upload());
        $this->ids[] = (int) $slide['id'];
        try {
            $service->updateSlide((int) $slide['id'], ['fit_mode' => 'invalid'], $this->upload());
            $this->fail('Invalid fit mode should be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Fit mode', $e->getMessage());
        }
        $this->assertFileExists(FCPATH . $slide['light_image']);
        $this->assertSame('Original', (new CarouselSlideModel())->find($slide['id'])['title']);
    }

    public function testUploadErrorIsNotSilentlyIgnoredOnEdit(): void
    {
        $service = new CarouselService();
        $slide = $service->createSlide(['light_image' => 'assets/img/brand/fuel-bg.jpg']);
        $this->ids[] = (int) $slide['id'];
        $this->expectException(\InvalidArgumentException::class);
        $service->updateSlide((int) $slide['id'], [], new UploadedFile('', 'failed.jpg', 'image/jpeg', 0, UPLOAD_ERR_INI_SIZE));
    }

    public function testOtherTenantCannotEditOrDeleteSlide(): void
    {
        $service = new CarouselService();
        $slide = $service->createSlide(['light_image' => 'assets/img/brand/fuel-bg.jpg']);
        $this->ids[] = (int) $slide['id'];
        CurrentTenant::set(999999);
        $this->assertNull($service->updateSlide((int) $slide['id'], ['title' => 'Intrusion']));
        $this->assertFalse($service->deleteSlide((int) $slide['id']));
        CurrentTenant::set(1);
        $this->assertNotNull((new CarouselSlideModel())->find($slide['id']));
    }
}
