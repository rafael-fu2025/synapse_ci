<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Clinic\InventoryService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** Read-query coverage against a private in-memory schema; no migrations or application DB. */
final class InventoryMovementPaginationTest extends CIUnitTestCase
{
    private BaseConnection $ledgerDb;
    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $this->ledgerDb->query('CREATE TABLE clinic_medicines (id INTEGER PRIMARY KEY, tenant_id INTEGER, is_active INTEGER, minimum_stock_level INTEGER)');
        $this->ledgerDb->query('CREATE TABLE clinic_medicine_batches (id INTEGER PRIMARY KEY, tenant_id INTEGER, medicine_id INTEGER, remaining_quantity INTEGER, status TEXT, expiration_date TEXT)');
        $this->ledgerDb->query('CREATE TABLE stock_movements (id INTEGER PRIMARY KEY, tenant_id INTEGER, medicine_id INTEGER)');
        $this->ledgerDb->query('CREATE TABLE inventory_operations (id INTEGER PRIMARY KEY, tenant_id INTEGER, medicine_id INTEGER, reversal_of INTEGER, reversal_mode TEXT)');
        $this->ledgerDb->table('clinic_medicines')->insertBatch([
            ['id' => 11, 'tenant_id' => 7, 'is_active' => 1, 'minimum_stock_level' => 0],
            ['id' => 12, 'tenant_id' => 7, 'is_active' => 1, 'minimum_stock_level' => 0],
            ['id' => 13, 'tenant_id' => 8, 'is_active' => 1, 'minimum_stock_level' => 0],
        ]);
        CurrentTenant::set(7);
        $this->inventory = new InventoryService($this->ledgerDb);
    }

    protected function tearDown(): void
    {
        CurrentTenant::reset();
        $this->ledgerDb->close();
        parent::tearDown();
    }

    private function seedMovements(): void
    {
        for ($id = 1; $id <= 101; $id++) {
            $this->ledgerDb->table('stock_movements')->insert(['id' => $id, 'tenant_id' => 7, 'medicine_id' => 11]);
        }
        // Neither another medicine nor another tenant may inflate the total or appear on a page.
        $this->ledgerDb->table('stock_movements')->insertBatch([
            ['id' => 102, 'tenant_id' => 7, 'medicine_id' => 12],
            ['id' => 103, 'tenant_id' => 8, 'medicine_id' => 11],
        ]);
    }

    public function testScopedTotalDefaultSizeAndLastPageClamping(): void
    {
        $this->seedMovements();
        $first = $this->inventory->details(11);
        $this->assertSame(['page' => 1, 'per_page' => 100, 'total' => 101, 'pages' => 2, 'from' => 1, 'to' => 100], $first['movementPagination']);
        $this->assertCount(100, $first['movements']);
        $this->assertSame(range(101, 2), array_map('intval', array_column($first['movements'], 'id')));
        $last = $this->inventory->details(11, 1000000);
        $this->assertSame(['page' => 2, 'per_page' => 100, 'total' => 101, 'pages' => 2, 'from' => 101, 'to' => 101], $last['movementPagination']);
        $this->assertSame([1], array_map('intval', array_column($last['movements'], 'id')));
    }

    public function testEverySupportedSizeUsesMatchingOffsetsAndMetadata(): void
    {
        $this->seedMovements();
        foreach ([20, 25, 50, 100] as $size) {
            $details = $this->inventory->details(11, 2, $size);
            $meta = $details['movementPagination'];
            $this->assertSame($size, $meta['per_page']);
            $this->assertSame($size + 1, $meta['from']);
            $this->assertSame(min(2 * $size, 101), $meta['to']);
            $this->assertSame((int) ceil(101 / $size), $meta['pages']);
            $this->assertSame(101 - $size, (int) $details['movements'][0]['id']);
        }
    }

    public function testEmptyLedgerHasZeroRangeAndOnePage(): void
    {
        $details = $this->inventory->details(11, 9, 20);
        $this->assertSame([], $details['movements']);
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 0, 'pages' => 1, 'from' => 0, 'to' => 0], $details['movementPagination']);
    }

    public function testForeignMedicineIsStillInaccessible(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(404);
        $this->inventory->details(13);
    }

    public function testInvalidSizeIsRejectedBeforeQueries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->inventory->details(11, 1, 10);
    }
}
