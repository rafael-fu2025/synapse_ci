<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Clinic\EncounterService;
use App\Services\Clinic\InventoryService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** These tests deliberately do not connect to or migrate any database. */
final class InventoryValidationTest extends CIUnitTestCase
{
    public static function invalidQuantities(): iterable
    {
        foreach ([0, -1, 1000001, '', '01', '-1', '+1', '1.0', '1e2', ' 1', '1 ', '1x', '100000000000000000000', 1.5, true, false, null, [], new \stdClass()] as $value) {
            yield [ $value ];
        }
    }

    #[DataProvider('invalidQuantities')]
    public function testRawQuantitiesAreNeverCoerced(mixed $quantity): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('table');
        $inventory = new InventoryService($db);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $inventory->dispense(1, 1, $quantity, null, 1, 'key');
    }

    public static function invalidReceiptFields(): iterable
    {
        yield [['quantity'=>'1.5']];
        yield [['quantity'=>false]];
        yield [['expiration_date'=>'2027-02-30']];
        yield [['expiration_date'=>'2027-2-03']];
        yield [['expiration_date'=>[]]];
        yield [['batch_number'=>str_repeat('x', 51)]];
        yield [['supplier'=>str_repeat('x', 151)]];
        yield [['remaining_quantity'=>900]];
        yield [['total_stock'=>900]];
        yield [['tenant_id'=>2]];
    }

    #[DataProvider('invalidReceiptFields')]
    public function testReceiptValidatesBeforeDatabaseAccess(array $override): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('table');
        $inventory = new InventoryService($db);
        $this->expectException(\InvalidArgumentException::class);
        $inventory->receive(1, array_replace(['batch_number'=>'LOT', 'expiration_date'=>'2099-01-01', 'quantity'=>'5'], $override), 1, 'key');
    }

    public static function nullableIdentityValues(): iterable
    {
        yield [null, null];
        yield ['', null];
        yield ['   ', null];
        yield ["\t\r\n", null];
        yield ['500mg', '500mg'];
        yield [' Generic ', ' Generic '];
        yield ['0', '0'];
        yield [[], []];
    }

    #[DataProvider('nullableIdentityValues')]
    public function testOnlyBlankNullableIdentityIsNormalized(mixed $value, mixed $expected): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('table');
        $inventory = new InventoryService($db);
        $normalize = new \ReflectionMethod(InventoryService::class, 'normalizeNullableIdentity');
        $this->assertSame($expected, $normalize->invoke($inventory, $value));
    }

    public static function invalidCatalogKeys(): iterable
    {
        foreach (['', ' ', 'with space', 'slash/key', "line\nbreak", "null\0byte", 'nonascii-é', str_repeat('x', 129)] as $key) {
            yield [$key];
        }
    }

    #[DataProvider('invalidCatalogKeys')]
    public function testProvidedCatalogKeyMustBeNonemptyAsciiBeforeDatabaseAccess(string $key): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('table');
        $db->expects($this->never())->method('query');
        $inventory = new InventoryService($db);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('ASCII idempotency key');
        $inventory->saveMedicine(null, [], 1, $key);
    }

    public function testEncounterRequiresExplicitActorAndKeyBeforeDatabaseAccess(): void
    {
        // Avoid constructor model creation: the guard does not depend on model state.
        $service = (new \ReflectionClass(EncounterService::class))->newInstanceWithoutConstructor();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('explicit actor and idempotency key');
        $service->dispenseMedicine(1, 1, 1, null, 1);
    }
}
