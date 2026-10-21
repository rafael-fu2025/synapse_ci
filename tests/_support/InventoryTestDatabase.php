<?php

declare(strict_types=1);

namespace Tests\Support;

use Config\Database;
use RuntimeException;

/** Safety gate runs before DatabaseTestTrait can migrate anything. */
final class InventoryTestDatabase
{
    public static function verify(?array $configuration = null): void
    {
        $config = config('Database');
        $tests = $configuration ?? $config->tests;
        if (ENVIRONMENT !== 'testing' || ($tests['DBDriver'] ?? '') !== 'MySQLi'
            || !preg_match('/(?:_test|_tests|_testing)\z/', (string) ($tests['database'] ?? ''))
            || ($tests['database'] ?? '') === ($config->default['database'] ?? '')
            || !empty($tests['DSN']) || !empty($tests['failover'])) {
            throw new RuntimeException('Inventory tests require an explicitly named, isolated MySQL *_test database, never default/DSN/failover.');
        }
        if ($configuration !== null) {
            $config->tests = $configuration;
        }
        $db = Database::connect('tests');
        $actual = $db->query('SELECT DATABASE() AS name')->getRow()->name;
        if ($actual !== $tests['database']) {
            throw new RuntimeException('Connected inventory test schema differs from the verified test configuration.');
        }
    }
}
