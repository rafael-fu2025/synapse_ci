<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CarouselSlideModel;
use App\Services\Carousel\CarouselService;
use App\Services\CurrentTenant;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class CarouselLoginSlidesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
        $this->db->transBegin();
    }

    protected function tearDown(): void
    {
        $this->db->transRollback();
        CurrentTenant::reset();
        parent::tearDown();
    }

    public function testNoActiveSlidesUsesOnlyFoundationUniversityFallback(): void
    {
        $this->db->table('carousel_slides')->where('tenant_id', 1)->update(['is_active' => 0]);

        $slides = (new CarouselService())->getSlidesForLogin();

        $this->assertCount(1, $slides);
        $this->assertSame('Foundation University', $slides[0]['title']);
        $this->assertSame('assets/img/brand/fuel-bg.jpg', $slides[0]['light_image']);
        $this->assertSame('assets/img/brand/FoundationU-Dark.png', $slides[0]['dark_image']);
        $this->assertSame('cover', $slides[0]['fit_mode']);

        $this->db->table('carousel_slides')->where('tenant_id', 1)->delete();
        $this->assertSame($slides, (new CarouselService())->getSlidesForLogin());
    }

    public function testActiveSlidesAreReturnedWithoutAddingFallback(): void
    {
        $service = new CarouselService();
        $service->createSlide(['title' => 'Custom slide', 'light_image' => 'assets/img/brand/fuel-bg.jpg']);

        $this->assertSame((new CarouselSlideModel())->getActiveSlides(), $service->getSlidesForLogin());
    }
}
