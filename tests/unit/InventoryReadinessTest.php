<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Clinic\InventoryService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Read-only readiness contract: no live database, migration runner or DDL. */
final class InventoryReadinessTest extends CIUnitTestCase
{
    private static function schema(): array
    {
        return [
            'clinic_medicines' => ['id', 'tenant_id', 'name', 'generic_name', 'category', 'dosage_form', 'strength', 'unit_of_measure', 'minimum_stock_level', 'total_stock', 'is_active', 'version', 'created_at', 'updated_at'],
            'clinic_medicine_batches' => ['id', 'tenant_id', 'medicine_id', 'batch_number', 'expiration_date', 'initial_quantity', 'remaining_quantity', 'status', 'created_at', 'updated_at'],
            'clinic_dispensations' => ['id', 'tenant_id', 'encounter_id', 'medicine_id', 'batch_id', 'quantity', 'instructions', 'dispensed_by_user_id', 'created_at', 'operation_id', 'snapshot_name', 'snapshot_generic_name', 'snapshot_strength', 'snapshot_dosage_form', 'snapshot_unit', 'snapshot_batch_number', 'snapshot_expiration_date', 'snapshot_reconstructed'],
            'inventory_operations' => ['id', 'tenant_id', 'medicine_id', 'encounter_id', 'actor_id', 'idempotency_key', 'payload_hash', 'type', 'reason', 'supplier', 'reference', 'reversal_of', 'reversal_mode', 'created_at'],
            'stock_movements' => ['id', 'tenant_id', 'medicine_id', 'batch_id', 'operation_id', 'actor_id', 'delta', 'reason', 'original_movement_id', 'created_at'],
            'migrations' => ['version', 'namespace'],
        ];
    }

    private function connection(array $schema, int $markers = 1): BaseConnection
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('tableExists')->willReturnCallback(static fn ($table) => isset($schema[$table]));
        $db->method('getFieldNames')->willReturnCallback(static fn ($table) => $schema[$table]);
        $db->expects($this->never())->method('query');
        $db->expects($this->never())->method('transBegin');
        $history = $this->createMock(BaseBuilder::class);
        $history->method('where')->willReturnCallback(function ($field, $value) use ($history) {
            $this->assertContains([$field, $value], [['version', '2026-10-15-000002'], ['namespace', 'App']]);
            return $history;
        });
        $history->method('countAllResults')->willReturn($markers);
        $db->method('table')->with(config('Migrations')->table)->willReturn($history);
        return $db;
    }

    public static function incompleteSchemas(): iterable
    {
        foreach (self::schema() as $table => $columns) {
            $schema = self::schema();
            unset($schema[$table]);
            yield 'missing table ' . $table => [$schema];
            foreach ($columns as $column) {
                $schema = self::schema();
                $schema[$table] = array_values(array_diff($columns, [$column]));
                yield $table . '.' . $column => [$schema];
            }
        }
    }

    #[DataProvider('incompleteSchemas')]
    public function testMissingRequiredTableOrColumnIsUnavailable(array $schema): void
    {
        $inventory = new InventoryService($this->connection($schema));
        $this->assertFalse($inventory->isInstalled());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage(InventoryService::SETUP_PENDING_MESSAGE);
        $inventory->assertInstalled();
    }

    public function testCompleteColumnsWithoutCompletedMigrationMarkerAreUnavailable(): void
    {
        $this->assertFalse((new InventoryService($this->connection(self::schema(), 0)))->isInstalled());
    }

    public function testConfiguredHistoryTableAndExactVersionAndNamespaceAreRequired(): void
    {
        $config = config('Migrations');
        $original = $config->table;
        $config->table = 'deployment_history';
        try {
            $schema = self::schema();
            $schema[$config->table] = $schema['migrations'];
            unset($schema['migrations']);
            $history = $this->createMock(BaseBuilder::class);
            $predicates = [];
            $history->expects($this->exactly(2))->method('where')->willReturnCallback(static function ($field, $value) use ($history, &$predicates) {
                $predicates[$field] = $value;
                return $history;
            });
            $history->expects($this->once())->method('countAllResults')->willReturn(1);
            // Use a fresh connection so this test strictly verifies both predicates.
            $db = $this->createMock(BaseConnection::class);
            $db->method('tableExists')->willReturnCallback(static fn ($table) => isset($schema[$table]));
            $db->method('getFieldNames')->willReturnCallback(static fn ($table) => $schema[$table]);
            $db->expects($this->once())->method('table')->with('deployment_history')->willReturn($history);
            $inventory = new InventoryService($db);
            $this->assertTrue($inventory->isInstalled());
            $inventory->assertInstalled();
            $this->assertTrue($inventory->isInstalled());
            $this->assertSame(['version' => '2026-10-15-000002', 'namespace' => 'App'], $predicates);
        } finally {
            $config->table = $original;
        }
    }

    public function testUnavailableResultIsCachedOnlyForTheServiceInstance(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->exactly(2))->method('tableExists')->willReturn(false);
        $db->expects($this->never())->method('getFieldNames');
        $db->expects($this->never())->method('table');
        $inventory = new InventoryService($db);
        $this->assertFalse($inventory->isInstalled());
        $this->assertFalse($inventory->isInstalled());
        $this->assertFalse((new InventoryService($db))->isInstalled());
    }

    public function testMetadataFailureIsCachedAndDoesNotExposeSqlDetails(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->once())->method('tableExists')->willThrowException(new \RuntimeException('SQL secret unknown column status'));
        $inventory = new InventoryService($db);
        $this->assertFalse($inventory->isInstalled());
        try {
            $inventory->assertInstalled();
            $this->fail('Pending inventory must be unavailable.');
        } catch (\RuntimeException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertSame(InventoryService::SETUP_PENDING_MESSAGE, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }
}
