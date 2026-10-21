<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddClinicInventoryLedger;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

// Migrations are excluded from Composer's class map. Loading the class does not run it.
require_once APPPATH . 'Database/Migrations/2026-10-15-000002_AddClinicInventoryLedger.php';

/** No migration instance, database connection, or DDL is needed for version preflight. */
final class InventoryMigrationVersionTest extends CIUnitTestCase
{
    public static function supportedVersions(): iterable
    {
        foreach ([
            '8.0.16',
            '8.0.16-0ubuntu0.18.04.1',
            '8.4.3-commercial',
            '9.0.1',
            '10.2.1-MariaDB',
            '10.11.6-MariaDB-0+deb12u1',
            '5.5.5-10.2.1-MariaDB',
            '5.5.5-10.11.6-MariaDB-0ubuntu0.18.04.1',
            '10.11.6-mariadb',
        ] as $version) {
            yield $version => [$version];
        }
    }

    #[DataProvider('supportedVersions')]
    public function testAcceptsSupportedLeadingServerVersions(string $version): void
    {
        AddClinicInventoryLedger::assertSupportedServerVersion($version);
        $this->addToAssertionCount(1);
    }

    public static function unsupportedVersions(): iterable
    {
        foreach ([
            '5.7.44',
            '5.7.44-0ubuntu0.18.04.1',
            '5.7.44-99.99.99',
            '8.0.15-0ubuntu0.22.04.1',
            '5.5.5-8.0.36',
            '10.2.0-MariaDB-0ubuntu0.18.04.1',
            '5.5.5-10.2.0-MariaDB-0ubuntu0.18.04.1',
            '5.5.5-5.5.5-10.11.6-MariaDB',
        ] as $version) {
            yield $version => [$version];
        }
    }

    #[DataProvider('unsupportedVersions')]
    public function testPackageSuffixCannotUpgradeAnUnsupportedServer(string $version): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported server: ' . $version);
        AddClinicInventoryLedger::assertSupportedServerVersion($version);
    }

    public static function unparseableVersions(): iterable
    {
        foreach ([
            '',
            'unknown',
            'server 8.0.36',
            ' 8.0.36',
            'MariaDB-10.11.6',
            '8.0',
            '8.0.16garbage',
            '8.0.16.1',
            '5.5.5-MariaDB-10.11.6',
            '5.5.5-garbage-10.11.6-MariaDB',
        ] as $version) {
            yield [$version];
        }
    }

    #[DataProvider('unparseableVersions')]
    public function testUnparseableVersionFailsClosed(string $version): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot parse server version:');
        AddClinicInventoryLedger::assertSupportedServerVersion($version);
    }
}
