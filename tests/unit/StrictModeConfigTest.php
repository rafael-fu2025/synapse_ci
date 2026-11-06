<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The runtime and test connections must behave the same way.
 *
 * A non-strict session silently truncates an over-length string or an invalid date
 * with only a warning, so a value written by the application can differ from the
 * value the developer tested against.
 */
final class StrictModeConfigTest extends TestCase
{
    public function testRuntimeAndTestConnectionsAreBothStrict(): void
    {
        $config = config('Database');
        self::assertTrue($config->default['strictOn'], 'The runtime connection must run in strict SQL mode.');
        self::assertTrue($config->tests['strictOn'], 'The test connection must run in strict SQL mode.');
    }

    public function testRuntimeAndTestConnectionsShareTheSameCollation(): void
    {
        $config = config('Database');
        self::assertSame($config->tests['DBCollat'], $config->default['DBCollat']);
        self::assertSame('utf8mb4_unicode_ci', $config->default['DBCollat']);
        self::assertSame($config->default['charset'], $config->tests['charset']);
    }

    public function testTheStrictModeAuditCommandIsReadOnly(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Commands/AuditStrictModeViolations.php');
        self::assertNotFalse($source);
        self::assertStringContainsString("protected \$name = 'synapse:strict-mode-audit';", $source);

        // The preflight audit must never write, so it can run against live clinical data.
        foreach (['INSERT\s+INTO', 'UPDATE\s', 'DELETE\s+FROM', 'ALTER\s+TABLE', 'DROP\s+TABLE', '\bTRUNCATE\b', 'REPLACE\s+INTO'] as $statement) {
            self::assertDoesNotMatchRegularExpression('/' . $statement . '/i', $source);
        }
    }
}
