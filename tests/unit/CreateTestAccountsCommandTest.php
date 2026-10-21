<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreateTestAccountsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('SYNAPSE_LOCAL_TEST_ACCOUNTS_ISOLATED')) {
            self::markTestSkipped('Run with tests/_support/local-test-accounts-phpunit.xml.');
        }
    }
    /** @return array{int, string} */
    private function command(string $environment, string $scenario, array $options = [], string $stdin = ''): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/_support/local-test-accounts-cli.php',
            $environment, $scenario, ...$options], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 15;
        do {
            $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                self::fail('Command exceeded 15 seconds; possible unexpected prompt.');
            }
            usleep(10000);
        } while (true);
        $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = $status['exitcode'];
        $closed = proc_close($process);
        return [$exit === -1 ? $closed : $exit, $output];
    }

    public function testDryRunIsDefaultAndHasNoPassword(): void
    {
        [$exit, $output] = $this->command('development', 'normal');
        self::assertSame(0, $exit, $output);
        self::assertSame(15, substr_count($output, 'would_create'));
        self::assertStringContainsString('Fixture count: 0', $output);
        self::assertStringNotContainsString('Shared password', $output);
    }

    public function testConsoleForwardsNamedTenantOptionForReadOnlyPreflight(): void
    {
        [$exit, $output] = $this->command('development', 'normal', ['--tenant', '1']);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Synthetic test accounts — tenant 1', $output);
        self::assertSame(15, substr_count($output, 'would_create'));
        self::assertStringContainsString('Fixture count: 0', $output);
        self::assertStringNotContainsString('Shared password', $output);
    }

    public function testCreatePrintsPasswordOnceAndRerunDoesNotExposeIt(): void
    {
        [$exit, $output] = $this->command('development', 'rerun', ['--create', '--tenant', '1']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Fixture count: 15', $output);
        self::assertSame(1, substr_count($output, 'Shared password (NEW accounts only; shown once):'));
        self::assertSame(15, substr_count($output, 'skipped'));
        self::assertMatchesRegularExpression('/shown once\): [0-9a-f]{32}/', $output);
        $rerun = explode('--- RERUN ---', $output)[1];
        self::assertStringNotContainsString('Shared password', $rerun);
    }

    public function testConsoleResetsAllFixturesFromStdinWithoutExposingPassword(): void
    {
        [$exit, $output] = $this->command('development', 'reset', ['--reset-password', '--tenant', '1'], "password123\n");
        self::assertSame(0, $exit, $output);
        self::assertSame(15, substr_count($output, 'password_reset'));
        self::assertStringContainsString('Verified reset count: 15', $output);
        self::assertStringContainsString('sign in again', $output);
        self::assertStringNotContainsString('password123', $output);
        self::assertStringNotContainsString('Shared password', $output);
    }

    public static function resetRefusals(): iterable
    {
        yield 'mutually exclusive' => ['development', 'reset', ['--create', '--reset-password'], "password123\n", 'Usage:'];
        yield 'no password argument' => ['development', 'reset', ['--reset-password', 'password123'], '', 'Usage:'];
        yield 'empty stdin' => ['development', 'reset', ['--reset-password'], '', '8 to 72 bytes'];
        yield 'short stdin' => ['development', 'reset', ['--reset-password'], "short\n", '8 to 72 bytes'];
        yield 'long stdin' => ['development', 'reset', ['--reset-password'], str_repeat('x', 73), '8 to 72 bytes'];
        yield 'multiple lines' => ['development', 'reset', ['--reset-password'], "password123\nextra\n", 'one password line'];
        yield 'missing' => ['development', 'reset-missing', ['--reset-password'], "password123\n", 'missing test.employee'];
        yield 'collision' => ['development', 'reset-collision', ['--reset-password'], "password123\n", 'collision for test.employee'];
        yield 'production' => ['production', 'reset', ['--reset-password'], "password123\n", 'development environment'];
    }

    #[DataProvider('resetRefusals')]
    public function testConsoleResetRefusalsLeaveEveryHashUnchanged(string $environment, string $scenario, array $options, string $stdin, string $message): void
    {
        [$exit, $output] = $this->command($environment, $scenario, $options, $stdin);
        self::assertNotSame(0, $exit, $output);
        self::assertStringContainsString($message, $output);
        self::assertStringContainsString('Reset unchanged: yes', $output);
        self::assertStringNotContainsString('password123', $output);
        self::assertStringNotContainsString('Shared password', $output);
    }

    public static function refusals(): iterable
    {
        yield 'production cannot create' => ['production', 'normal', ['--create'], 'development environment', 0];
        yield 'testing cannot create' => ['testing', 'normal', ['--create'], 'development environment', 0];
        yield 'unknown env override' => ['development', 'normal', ['--environment', 'development'], 'Usage:', 0];
        yield 'no password argument' => ['development', 'normal', ['--password', 'not-a-secret'], 'Usage:', 0];
        yield 'no positional argument' => ['development', 'normal', ['unwanted'], 'Usage:', 0];
        yield 'create is only a flag' => ['development', 'normal', ['--create', 'yes'], 'Usage:', 0];
        yield 'positional with valid named option' => ['development', 'normal', ['unwanted', '--tenant', '1'], 'Usage:', 0];
        yield 'unknown with valid named option' => ['development', 'normal', ['--tenant', '1', '--unknown'], 'Usage:', 0];
        yield 'valued create with valid tenant' => ['development', 'normal', ['--create', 'yes', '--tenant', '1'], 'Usage:', 0];
        yield 'tenant missing value' => ['development', 'normal', ['--tenant'], 'positive tenant', 0];
        yield 'tenant zero' => ['development', 'normal', ['--tenant', '0'], 'positive tenant', 0];
        yield 'tenant malformed' => ['development', 'normal', ['--tenant', '1x'], 'positive tenant', 0];
        yield 'tenant overflow' => ['development', 'normal', ['--tenant', '999999999999999999999999'], 'positive tenant', 0];
        yield 'tenant does not exist' => ['development', 'normal', ['--create', '--tenant', '999'], 'missing or inactive', 0];
        yield 'collision no partial inserts' => ['development', 'collision', ['--create'], 'collision for test.employee', 1];
    }

    #[DataProvider('refusals')]
    public function testCommandRefusesSafelyWithNonzeroExit(string $environment, string $scenario, array $options, string $message, int $count): void
    {
        [$exit, $output] = $this->command($environment, $scenario, $options);
        self::assertNotSame(0, $exit, $output);
        self::assertStringContainsString($message, $output);
        self::assertStringContainsString('Fixture count: ' . $count, $output);
        self::assertStringNotContainsString('Shared password', $output);
        self::assertStringNotContainsString('INSERT INTO', $output);
        self::assertStringNotContainsString('SELECT ', $output);
    }
}
