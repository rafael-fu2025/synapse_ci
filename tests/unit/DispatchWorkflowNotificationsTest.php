<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Commands\DispatchWorkflowNotifications;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use Config\WorkflowAlerts;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;

final class DispatchWorkflowNotificationsTest extends CIUnitTestCase
{
    use StreamFilterTrait;
    private array $savedOptions;
    private DispatchWorkflowNotifications $command;

    protected function setUp(): void
    {
        parent::setUp();
        $property = new ReflectionProperty(CLI::class, 'options');
        $this->savedOptions = $property->getValue();
        $property->setValue(null, []);
        $this->command = new class(new NullLogger(), new Commands()) extends DispatchWorkflowNotifications {
            public array $calls = [];
            public bool $fail = false;
            public bool $enabled = true;
            public int $failed = 0;
            protected function alertsConfig(): WorkflowAlerts
            {
                $config = new WorkflowAlerts();
                $config->enabled = $this->enabled;
                return $config;
            }
            protected function scanNotifications(): array
            {
                $this->calls[] = 'scan';
                if ($this->fail) throw new RuntimeException('PRIVATE clinical text and credentials');
                return ['scanned' => 12, 'recorded' => 3, 'failed' => $this->failed];
            }
            protected function dispatchNotifications(int $limit): array
            {
                $this->calls[] = $limit;
                return ['processed' => 2, 'failed' => 0];
            }
        };
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(CLI::class, 'options'))->setValue(null, $this->savedOptions);
        parent::tearDown();
    }

    private function execute(array $params = []): array
    {
        $this->resetStreamFilterBuffer();
        $exit = $this->command->run($params);
        return [$exit, trim($this->getStreamFilterBuffer())];
    }

    public function testDefaultFiniteScanThenDispatchPrintsCountsOnly(): void
    {
        self::assertSame([EXIT_SUCCESS, 'scanned=12 recorded=3 processed=2 failed=0'], $this->execute());
        self::assertSame(['scan', 100], $this->command->calls);
    }

    public function testNamedCliOptionsSupportDispatchOnlyAndBoundedLimit(): void
    {
        (new ReflectionProperty(CLI::class, 'options'))->setValue(null, ['limit' => '7', 'scan' => '0']);
        self::assertSame([EXIT_SUCCESS, 'scanned=0 recorded=0 processed=2 failed=0'], $this->execute());
        self::assertSame([7], $this->command->calls);
    }

    public function testInvalidOptionsDoNotInvokeScannerOrDispatcher(): void
    {
        foreach ([['limit' => '0'], ['limit' => '-1'], ['limit' => '1001'], ['limit' => 'abc'], ['limit' => null], ['scan' => 'yes'], ['unexpected' => null], ['positional']] as $params) {
            self::assertSame([EXIT_ERROR, 'scanned=0 recorded=0 processed=0 failed=1'], $this->execute($params));
        }
        self::assertSame([], $this->command->calls);
    }

    public function testFailureIsRedactedAndHasNonzeroExit(): void
    {
        $this->command->fail = true;
        self::assertSame([EXIT_ERROR, 'scanned=0 recorded=0 processed=0 failed=1'], $this->execute());
        self::assertSame(['scan'], $this->command->calls);
    }

    public function testRowFailuresStillDispatchAndReportFailureCount(): void
    {
        $this->command->failed = 2;
        self::assertSame([EXIT_ERROR, 'scanned=12 recorded=3 processed=2 failed=2'], $this->execute(['scan' => null]));
        self::assertSame(['scan', 100], $this->command->calls);
    }

    public function testGlobalDisableDoesNotRunAnything(): void
    {
        $this->command->enabled = false;
        self::assertSame([EXIT_SUCCESS, 'scanned=0 recorded=0 processed=0 failed=0'], $this->execute());
        self::assertSame([], $this->command->calls);
    }
}
