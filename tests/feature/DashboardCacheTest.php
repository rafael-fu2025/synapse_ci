<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CurrentTenant;
use App\Services\DashboardCache;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Contract for the page dashboards' cache key.
 *
 * The key is the only thing standing between two tenants, two counsellors and
 * two calendar days, so its composition is asserted directly rather than only
 * through a rendered page.
 */
final class DashboardCacheTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        CurrentTenant::reset();
        DashboardCache::enable(null);
        parent::tearDown();
    }

    public function testTheKeyIsNamespacedByTenantAndManilaBusinessDate(): void
    {
        CurrentTenant::set(7);
        $key = DashboardCache::key('clinic.dashboard');

        $this->assertStringStartsWith('dashboard.7.', $key);
        $this->assertStringContainsString('.' . SchedulingClock::today() . '.', $key);
        $this->assertStringEndsWith('.clinic.dashboard', $key);
    }

    public function testTwoCounsellorsNeverShareAKey(): void
    {
        CurrentTenant::set(1);

        $this->assertNotSame(
            DashboardCache::key('guidance.dashboard.14'),
            DashboardCache::key('guidance.dashboard.15')
        );
    }

    public function testTheSameScopeInTwoTenantsNeverSharesAKey(): void
    {
        CurrentTenant::set(1);
        $first = DashboardCache::key('clinic.dashboard');

        CurrentTenant::set(2);
        $this->assertNotSame($first, DashboardCache::key('clinic.dashboard'));
    }

    public function testRememberServesASecondCallFromTheCacheAndForgetDropsIt(): void
    {
        CurrentTenant::set(1);
        DashboardCache::enable(true);
        $scope = 'contract.' . bin2hex(random_bytes(8));
        $calls = 0;
        $producer = static function () use (&$calls): array {
            return ['call' => ++$calls];
        };

        $this->assertSame(['call' => 1], DashboardCache::remember($scope, 60, $producer));
        $this->assertSame(['call' => 1], DashboardCache::remember($scope, 60, $producer));
        $this->assertSame(1, $calls);

        DashboardCache::forget($scope);
        $this->assertSame(['call' => 2], DashboardCache::remember($scope, 60, $producer));
    }

    public function testTheFourPageDashboardsCacheButTheLiveQueueCountsDoNot(): void
    {
        foreach (['ClinicController', 'GuidanceController', 'DashboardController', 'FacilitiesController'] as $controller) {
            $source = file_get_contents(APPPATH . 'Controllers/' . $controller . '.php');
            $this->assertNotFalse($source, $controller);
            $this->assertStringContainsString('DashboardCache::remember(', $source, $controller);
        }

        // The live-polled queue counter must always reflect the queue in real time.
        $live = file_get_contents(APPPATH . 'Controllers/QueueDashboardController.php');
        $this->assertNotFalse($live);
        $this->assertStringNotContainsString('DashboardCache', $live);
    }
}
