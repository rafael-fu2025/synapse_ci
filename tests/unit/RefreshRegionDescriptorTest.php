<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\BaseController;
use CodeIgniter\Test\CIUnitTestCase;

final class RefreshRegionDescriptorTest extends CIUnitTestCase
{
    private object $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new class extends BaseController {
            public function regions(string $owner, array $regions, ?string $url = null): array
            {
                return $this->refreshRegions($owner, $regions, $url);
            }

            public function navigation(string $url, string $history = 'push'): array
            {
                return $this->refreshNavigation($url, $history);
            }

            public function none(): array
            {
                return $this->refreshNone();
            }
        };
    }

    public function testBuildsCanonicalRegionNavigationAndNoneDescriptors(): void
    {
        self::assertSame([
            'mode' => 'regions',
            'owner' => 'appointments.staff.clinic',
            'regions' => ['appointments.pending', 'appointments.schedule'],
            'url' => '/clinic/appointments?date=2026-11-11',
        ], $this->controller->regions(
            'appointments.staff.clinic',
            ['appointments.pending', 'appointments.schedule', 'appointments.pending'],
            '/clinic/appointments?date=2026-11-11',
        ));
        self::assertSame([
            'mode' => 'regions',
            'owner' => 'announcements.staff.clinic',
            'regions' => ['announcements.list', 'announcements.read-history'],
        ], $this->controller->regions(
            'announcements.staff.clinic',
            ['announcements.list', 'announcements.read-history'],
        ));
        self::assertSame([
            'mode' => 'navigate',
            'url' => '/clinic/inventory/42',
            'history' => 'replace',
        ], $this->controller->navigation('/clinic/inventory/42', 'replace'));
        self::assertSame(['mode' => 'none'], $this->controller->none());
    }

    /** @dataProvider invalidRegionDescriptors */
    public function testRejectsInvalidOwnersAndRegions(string $owner, array $regions): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->controller->regions($owner, $regions, '/records');
    }

    public static function invalidRegionDescriptors(): array
    {
        return [
            'empty owner' => ['', ['records']],
            'selector owner' => ['#records', ['records']],
            'uppercase owner' => ['Records', ['records']],
            'empty regions' => ['records', []],
            'empty region' => ['records', ['']],
            'selector region' => ['records', ['[data-row]']],
            'non-string region' => ['records', [1]],
        ];
    }

    public function testRejectsInvalidNavigationHistory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->controller->navigation('/records', 'reload');
    }
}
