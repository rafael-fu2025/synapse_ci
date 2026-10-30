<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CarouselSlideModel;
use App\Services\Carousel\CarouselService;
use App\Services\CurrentTenant;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;

/** Private SQLite fixtures only: no application migrations or realtime transport. */
final class CarouselRealtimePublishTest extends CIUnitTestCase
{
    private CarouselService $service;
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(7);
        $this->events = [];
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => false,
        ], false);
        $this->db->query('CREATE TABLE carousel_slides (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            title TEXT, subtitle TEXT, light_image TEXT, dark_image TEXT,
            fit_mode TEXT NOT NULL DEFAULT \'cover\', sort_order INTEGER NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT, updated_at TEXT
        )');
        $this->db->table('carousel_slides')->insertBatch([
            $this->fixture(11, 7, 50),
            $this->fixture(12, 7, 60),
            $this->fixture(21, 8, 70),
        ]);
        $this->service = new CarouselService(new CarouselSlideModel($this->db), function (...$args): bool {
            $this->recordEvent($args);
            return true;
        });
    }

    protected function tearDown(): void
    {
        $this->db->close();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function fixture(int $id, int $tenant, int $order): array
    {
        return [
            'id' => $id, 'tenant_id' => $tenant, 'sort_order' => $order,
            'title' => 'Private title ' . $id, 'subtitle' => 'Private subtitle',
            'light_image' => 'assets/img/brand/fuel-bg.jpg', 'dark_image' => null,
            'fit_mode' => 'cover', 'is_active' => 1,
        ];
    }

    private function rows(): array
    {
        return array_column($this->db->table('carousel_slides')->orderBy('id')->get()->getResultArray(), null, 'id');
    }

    private function recordEvent(array $args): void
    {
        // Assert outside the callback: publisher guards deliberately swallow all throwables.
        $this->events[] = [
            'args' => $args, 'rows' => $this->rows(),
            'depth' => $this->db->transDepth, 'healthy' => $this->db->transStatus(),
        ];
    }

    private function mutate(string $operation): mixed
    {
        // Caller-supplied routing/owner fields must neither escape scope nor enter the hint.
        $data = [
            'title' => 'Changed private title', 'subtitle' => 'Changed private subtitle',
            'light_image' => 'assets/img/brand/fuel-bg.jpg', 'tenant_id' => 8,
            'tenant' => 8, 'admin_id' => 9001, 'channel' => 'public.tenant.8.login-carousel',
        ];
        return match ($operation) {
            'create' => $this->service->createSlide($data),
            'update' => $this->service->updateSlide(11, $data),
            'delete' => $this->service->deleteSlide(11),
            'toggle' => $this->service->toggleActive(11),
            'reorder' => $this->service->reorderSlides([12, 11]),
        };
    }

    public static function mutations(): iterable
    {
        foreach (['create', 'update', 'delete', 'toggle', 'reorder'] as $operation) {
            yield $operation => [$operation];
        }
    }

    private function assertMutationSaved(string $operation, mixed $result): void
    {
        $rows = $this->rows();
        switch ($operation) {
            case 'create':
                self::assertCount(4, $rows);
                self::assertSame(7, (int) $result['tenant_id']);
                self::assertSame('Changed private title', $rows[$result['id']]['title']);
                break;
            case 'update':
                self::assertSame(7, (int) $result['tenant_id']);
                self::assertSame('Changed private title', $rows[11]['title']);
                break;
            case 'delete':
                self::assertTrue($result);
                self::assertArrayNotHasKey(11, $rows);
                break;
            case 'toggle':
                self::assertSame(0, (int) $result['is_active']);
                self::assertSame(0, (int) $rows[11]['is_active']);
                break;
            case 'reorder':
                self::assertSame(1, (int) $rows[12]['sort_order']);
                self::assertSame(2, (int) $rows[11]['sort_order']);
                break;
        }
        self::assertSame(8, (int) $rows[21]['tenant_id']);
        self::assertSame(70, (int) $rows[21]['sort_order']);
        self::assertSame('Private title 21', $rows[21]['title']);
    }

    #[DataProvider('mutations')]
    public function testSuccessfulMutationPublishesOnceAfterReadableWrite(string $operation): void
    {
        $result = $this->mutate($operation);
        $this->assertMutationSaved($operation, $result);
        self::assertCount(1, $this->events);
        self::assertSame(['public.tenant.7.login-carousel', 'login.carousel.changed', []], $this->events[0]['args']);
        self::assertSame($this->rows(), $this->events[0]['rows']);
        self::assertSame(0, $this->events[0]['depth']);
        self::assertTrue($this->events[0]['healthy']);
    }

    public static function invalidSaves(): iterable
    {
        foreach (['create', 'update'] as $operation) {
            yield $operation . ' fit' => [$operation, ['fit_mode' => 'invalid']];
            yield $operation . ' title' => [$operation, ['title' => str_repeat('x', 151)]];
            yield $operation . ' path' => [$operation, ['light_image' => '../private.png']];
        }
    }

    #[DataProvider('invalidSaves')]
    public function testInvalidSaveDoesNotWriteOrPublish(string $operation, array $data): void
    {
        $before = $this->rows();
        try {
            if ($operation === 'create') {
                $this->service->createSlide($data);
            } else {
                $this->service->updateSlide(11, $data);
            }
            self::fail('Invalid data must not be saved.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $this->rows());
            self::assertSame([], $this->events);
        }
    }

    public function testMissingAndForeignRowsCannotMutateOrPublish(): void
    {
        $before = $this->rows();
        foreach ([21, 99999] as $id) {
            self::assertNull($this->service->updateSlide($id, ['title' => 'Intrusion', 'tenant_id' => 7]));
            self::assertFalse($this->service->deleteSlide($id));
            try {
                $this->service->toggleActive($id);
                self::fail('Missing or foreign slide must not be toggled.');
            } catch (\InvalidArgumentException) {
                // The current tenant cannot see this row.
            }
            $this->service->reorderSlides([$id]);
        }
        $this->service->reorderSlides([]);
        self::assertSame($before, $this->rows());
        self::assertSame([], $this->events);
    }

    public function testMixedReorderCannotWriteOrPublishForAnotherTenant(): void
    {
        $foreign = $this->rows()[21];
        $this->service->reorderSlides([21, 12, 99999, 11]);
        self::assertSame($foreign, $this->rows()[21]);
        self::assertSame(2, (int) $this->rows()[12]['sort_order']);
        self::assertSame(4, (int) $this->rows()[11]['sort_order']);
        self::assertCount(1, $this->events);
        self::assertSame(['public.tenant.7.login-carousel', 'login.carousel.changed', []], $this->events[0]['args']);
    }

    public function testOwnerTenantIsNotHardcoded(): void
    {
        CurrentTenant::set(8);
        $saved = $this->service->toggleActive(21);
        self::assertSame(0, (int) $saved['is_active']);
        self::assertSame(1, (int) $this->rows()[11]['is_active']);
        self::assertCount(1, $this->events);
        self::assertSame(['public.tenant.8.login-carousel', 'login.carousel.changed', []], $this->events[0]['args']);
    }

    #[DataProvider('mutations')]
    public function testFailedDatabaseWriteDoesNotPublish(string $operation): void
    {
        $before = $this->rows();
        $verb = match ($operation) {
            'create' => 'INSERT', 'delete' => 'DELETE', default => 'UPDATE',
        };
        $this->db->query("CREATE TRIGGER reject_write BEFORE {$verb} ON carousel_slides
            BEGIN SELECT RAISE(ABORT, 'private database failure'); END");
        $failure = null;
        $result = null;
        try {
            $result = $this->mutate($operation);
        } catch (\RuntimeException $e) {
            $failure = $e;
        }
        if ($operation === 'delete') {
            self::assertFalse($result);
        } else {
            self::assertInstanceOf(\RuntimeException::class, $failure, 'Failed writes must not report success.');
        }
        self::assertSame($before, $this->rows());
        self::assertSame([], $this->events);
    }

    public function testLaterReorderFailureDoesNotPublishEarlierSuccessfulWrite(): void
    {
        $this->db->query("CREATE TRIGGER reject_second_write BEFORE UPDATE ON carousel_slides
            WHEN OLD.id = 11 BEGIN SELECT RAISE(ABORT, 'private database failure'); END");
        try {
            $this->service->reorderSlides([12, 11]);
            self::fail('A failed reorder must not report success.');
        } catch (\RuntimeException) {
            self::assertSame(1, (int) $this->rows()[12]['sort_order']);
            self::assertSame(50, (int) $this->rows()[11]['sort_order']);
            self::assertSame([], $this->events);
        }
    }

    public static function outerTransactions(): iterable
    {
        foreach (self::mutations() as [$operation]) {
            foreach (['commit', 'rollback'] as $outcome) {
                yield $operation . ' ' . $outcome => [$operation, $outcome];
            }
        }
    }

    #[DataProvider('outerTransactions')]
    public function testCallerTransactionNeverPublishesEarlyOrDefersHint(string $operation, string $outcome): void
    {
        $before = $this->rows();
        self::assertTrue($this->db->transBegin());
        $result = $this->mutate($operation);
        $this->assertMutationSaved($operation, $result);
        self::assertSame([], $this->events);
        if ($outcome === 'commit') {
            self::assertTrue($this->db->transCommit());
            $this->assertMutationSaved($operation, $result);
        } else {
            self::assertTrue($this->db->transRollback());
            self::assertSame($before, $this->rows());
        }
        self::assertSame(0, $this->db->transDepth);
        self::assertSame([], $this->events);
    }

    #[DataProvider('mutations')]
    public function testUnhealthyConnectionDoesNotPublishEvenAtDepthZero(string $operation): void
    {
        self::assertTrue($this->db->transBegin());
        self::assertFalse($this->db->query('INSERT INTO carousel_slides (id, tenant_id, sort_order) VALUES (11, 7, 1)'));
        self::assertFalse($this->db->transStatus());
        self::assertTrue($this->db->transRollback());
        self::assertSame(0, $this->db->transDepth);
        self::assertFalse($this->db->transStatus());
        $result = $this->mutate($operation);
        $this->assertMutationSaved($operation, $result);
        self::assertSame([], $this->events);
    }

    public static function publisherFailures(): iterable
    {
        foreach (self::mutations() as [$operation]) {
            foreach ([false, true] as $throws) {
                yield $operation . ($throws ? ' throws' : ' returns false') => [$operation, $throws];
            }
        }
    }

    #[DataProvider('publisherFailures')]
    public function testPublisherFailureCannotFailSuccessfulWrite(string $operation, bool $throws): void
    {
        $this->service = new CarouselService(new CarouselSlideModel($this->db), function (...$args) use ($throws): bool {
            $this->recordEvent($args);
            if ($throws) {
                throw new \RuntimeException('Transport unavailable');
            }
            return false;
        });
        $result = $this->mutate($operation);
        $this->assertMutationSaved($operation, $result);
        self::assertCount(1, $this->events);
        self::assertSame(['public.tenant.7.login-carousel', 'login.carousel.changed', []], $this->events[0]['args']);
        self::assertSame($this->rows(), $this->events[0]['rows']);
    }
}
