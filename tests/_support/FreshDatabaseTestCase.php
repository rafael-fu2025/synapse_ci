<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;

/** Guard before CIUnitTestCase invokes a child's DatabaseTestTrait migrations. */
abstract class FreshDatabaseTestCase extends CIUnitTestCase
{
    protected function setUp(): void
    {
        $schema = getenv('FRESH_DATABASE_SCHEMA');
        if (!is_string($schema) || !preg_match('/^humus_fresh_[0-9a-f]{16}$/D', $schema)
            || !defined('HUMUS_FRESH_DATABASE_VERIFIED') || HUMUS_FRESH_DATABASE_VERIFIED !== $schema) {
            $this->markTestSkipped('Run migration-backed tests through tests/_support/run-fresh-database-tests.py.');
        }
        $config = config('Database');
        foreach (['default', 'tests'] as $group) {
            $fields = $config->$group;
            if ($config->defaultGroup !== 'tests' || $fields['database'] !== $schema
                || $fields['hostname'] !== '127.0.0.1' || (int) $fields['port'] !== (int) getenv('FRESH_DATABASE_PORT')
                || $fields['DSN'] !== '' || $fields['DBPrefix'] !== '' || $fields['DBDriver'] !== 'MySQLi'
                || !empty($fields['failover']) || !empty($fields['pConnect'])) {
                throw new \RuntimeException('Fresh database configuration changed; refusing migrations.');
            }
        }
        parent::setUp();
    }
}
