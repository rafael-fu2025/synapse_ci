<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Commands\AiProbe;
use App\Commands\DispatchAiInsights;
use App\Commands\PauseAiInsights;
use App\Commands\PurgeAiInsights;
use App\Commands\ResumeAiInsights;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use Psr\Log\NullLogger;
use ReflectionProperty;

/** Command adapters only: no deployment files, provider configuration, database, DNS or HTTP. */
final class AiDepartmentRuntimeCommandsTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private array $savedOptions;

    protected function setUp(): void
    {
        parent::setUp();
        $options = new ReflectionProperty(CLI::class, 'options');
        $this->savedOptions = $options->getValue();
        $options->setValue(null, []);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(CLI::class, 'options'))->setValue(null, $this->savedOptions);
        parent::tearDown();
    }

    private function dispatch(): DispatchAiInsights
    {
        return new class(new NullLogger(), new Commands()) extends DispatchAiInsights {
            public array $calls = [];
            protected function dispatch(int $tenantId): array
            {
                $this->calls[] = [$tenantId, $this->unit];
                return ['processed' => 1, 'failed' => 0, 'outcome_unknown' => 0];
            }
        };
    }

    private function purge(): PurgeAiInsights
    {
        return new class(new NullLogger(), new Commands()) extends PurgeAiInsights {
            public array $calls = [];
            protected function purge(int $tenantId): array
            {
                $this->calls[] = [$tenantId, $this->unit];
                return ['ai_snapshots' => 2, 'ai_insights' => 1];
            }
        };
    }

    private function resume(): ResumeAiInsights
    {
        return new class(new NullLogger(), new Commands()) extends ResumeAiInsights {
            public array $calls = [];
            protected function resume(int $tenantId, ?string $unit): void
            {
                $this->calls[] = [$tenantId, $unit];
            }
        };
    }

    private function probe(): AiProbe
    {
        return new class(new NullLogger(), new Commands()) extends AiProbe {
            public array $calls = [];
            protected function probe(int $tenantId): array
            {
                $this->calls[] = $tenantId;
                return ['revision' => str_repeat('a', 64)];
            }
        };
    }

    private function execute($command, array $params): array
    {
        $this->resetStreamFilterBuffer();
        $exit = $command->run($params);
        return [$exit, trim($this->getStreamFilterBuffer())];
    }

    public function testDispatchForwardsSelectedUnitAndOmissionSharesApprovedUnits(): void
    {
        $command = $this->dispatch();
        foreach (['clinic', 'guidance', 'bmg'] as $unit) {
            self::assertSame([EXIT_SUCCESS, 'processed=1 failed=0 outcome_unknown=0'], $this->execute($command, ['unit' => $unit, 'tenant' => '7']));
        }
        self::assertSame(EXIT_SUCCESS, $this->execute($command, ['tenant' => 8])[0]);
        self::assertSame([[7, 'clinic'], [7, 'guidance'], [7, 'bmg'], [8, null]], $command->calls);
    }

    public function testPurgeDefaultsToClinicAndCanSelectOtherUnitsWithoutApproval(): void
    {
        $command = $this->purge();
        foreach (['guidance', 'bmg'] as $unit) {
            self::assertSame([EXIT_SUCCESS, 'ai_snapshots=2 ai_insights=1 failed=0'], $this->execute($command, ['tenant' => '7', 'unit' => $unit]));
        }
        self::assertSame(EXIT_SUCCESS, $this->execute($command, ['tenant' => 9])[0]);
        self::assertSame([[7, 'guidance'], [7, 'bmg'], [9, 'clinic']], $command->calls);
    }

    public function testSparkMergedOptionsReachRuntimeForAllTenantCommands(): void
    {
        foreach ([$this->dispatch(), $this->purge(), $this->resume()] as $command) {
            foreach (['clinic', 'guidance', 'bmg', null] as $unit) {
                $options = ['tenant' => '7'];
                if ($unit !== null) { $options['unit'] = $unit; }
                (new ReflectionProperty(CLI::class, 'options'))->setValue(null, $options);
                // Console::run merges CLI options into the params passed to the command.
                $params = array_merge([], CLI::getOptions());
                self::assertSame(EXIT_SUCCESS, $this->execute($command, $params)[0]);
            }
            self::assertSame([[7, 'clinic'], [7, 'guidance'], [7, 'bmg'],
                [7, $command instanceof PurgeAiInsights ? 'clinic' : null]], $command->calls);
        }
        (new ReflectionProperty(CLI::class, 'options'))->setValue(null, ['tenant' => '7']);
        $probe = $this->probe();
        self::assertSame([EXIT_SUCCESS, 'success=1 verified=1 revision=' . str_repeat('a', 64)],
            $this->execute($probe, array_merge([], CLI::getOptions())));
        self::assertSame([7], $probe->calls);
    }

    public function testMalformedSparkMergedOptionsNeverInvokeRuntime(): void
    {
        foreach ([$this->dispatch(), $this->purge(), $this->resume(), $this->probe()] as $command) {
            foreach ([['tenant' => null], ['tenant' => true], ['tenant' => []], ['tenant' => '01'],
                ['tenant' => str_repeat('9', 30)], ['tenant' => '7', 'url' => 'untrusted'], ['7'],
                ['tenant' => '7', 'unit' => null], ['tenant' => '7', 'unit' => []],
                ['tenant' => '7', 'unit' => 'all']] as $options) {
                (new ReflectionProperty(CLI::class, 'options'))->setValue(null, $options);
                self::assertSame(EXIT_ERROR, $this->execute($command, array_merge([], CLI::getOptions()))[0]);
            }
            self::assertSame([], $command->calls);
        }
    }

    public function testMalformedUnitAndTenantNeverInvokeRuntimeOrReadConfiguration(): void
    {
        $dispatch = $this->dispatch();
        $purge = $this->purge();
        $resume = $this->resume();
        foreach ([$dispatch, $purge, $resume] as $command) {
            foreach ([null, true, [], 1, '', 'main', 'all', 'Clinic', 'guidance ', "bmg\n", 'clinic,guidance'] as $unit) {
                self::assertSame(EXIT_ERROR, $this->execute($command, ['tenant' => 7, 'unit' => $unit])[0]);
            }
            foreach ([null, true, [], 0, '01', '+7', '7.0', ' 7', str_repeat('9', 30)] as $tenant) {
                self::assertSame(EXIT_ERROR, $this->execute($command, ['tenant' => $tenant, 'unit' => 'bmg'])[0]);
            }
            self::assertSame(EXIT_ERROR, $this->execute($command, ['unit' => 'bmg'])[0]);
            self::assertSame(EXIT_ERROR, $this->execute($command, ['tenant' => 7, 'unit' => 'bmg', 'url' => 'untrusted'])[0]);
            self::assertSame(EXIT_ERROR, $this->execute($command, ['7', 'bmg'])[0]);
        }
        self::assertSame([], $dispatch->calls);
        self::assertSame([], $purge->calls);
        self::assertSame([], $resume->calls);
    }

    public function testDuplicateOptionsFailClosedIncludingResume(): void
    {
        (new ReflectionProperty(CLI::class, 'options'))->setValue(null, ['tenant' => '7', 'unit' => 'guidance']);
        foreach ([$this->dispatch(), $this->purge(), $this->resume()] as $command) {
            self::assertSame(EXIT_ERROR, $this->execute($command, ['unit' => 'bmg'])[0]);
            self::assertSame(EXIT_ERROR, $this->execute($command, ['tenant' => 8])[0]);
            self::assertSame(EXIT_ERROR, $this->execute($command, ['tenant' => 7])[0]);
            self::assertSame([], $command->calls);
        }
        (new ReflectionProperty(CLI::class, 'options'))->setValue(null, ['tenant' => '7']);
        $probe = $this->probe();
        foreach (['8', 7] as $tenant) {
            self::assertSame(EXIT_ERROR, $this->execute($probe, ['tenant' => $tenant])[0]);
        }
        self::assertSame([], $probe->calls);
    }

    public function testPauseCannotBeMisreadAsUnitScopedAndNeverReadsProfile(): void
    {
        $command = new PauseAiInsights(new NullLogger(), new Commands());
        foreach ([['unit' => 'clinic'], ['tenant' => 1], ['guidance']] as $params) {
            self::assertSame(EXIT_ERROR, $this->execute($command, $params)[0]);
        }
        (new ReflectionProperty(CLI::class, 'options'))->setValue(null, ['unit' => 'bmg']);
        self::assertSame(EXIT_ERROR, $this->execute($command, [])[0]);
    }
}
