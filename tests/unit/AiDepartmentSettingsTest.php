<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiFailure;
use App\Services\Ai\AnalysisCatalog;
use App\Services\Ai\InsightGenerator;
use App\Services\Ai\PayloadCipher;
use App\Services\Ai\Profile;
use App\Services\Ai\ProgramLibrary;
use App\Services\Ai\SettingsStore;
use Config\Ai;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Reflection-only config, disposable encrypted fixtures, no dotenv, database, or provider requests. */
final class AiDepartmentSettingsTest extends TestCase
{
    private const TENANT = 17;
    private const ACTOR = 23;
    private const SECRET = 'synthetic-department-settings-secret';
    private string $directory;
    private Ai $defaults;
    private SettingsStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/humus-ai-departments-' . bin2hex(random_bytes(12));
        $this->defaults = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        $this->store = new SettingsStore($this->directory, $this->defaults);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (new \FilesystemIterator($this->directory) as $file) { unlink($file->getPathname()); }
            rmdir($this->directory);
        }
    }

    private function input(array $changes = []): array
    {
        return array_replace(['version' => '', 'base_url' => 'https://gateway.example.test/v1',
            'model' => 'synthetic-model', 'api_key' => self::SECRET], $changes);
    }

    private function save(array $changes = []): array
    {
        return $this->store->save(self::TENANT, self::ACTOR, $this->input($changes));
    }

    private function tested(array $changes = []): array
    {
        $saved = $this->save($changes);
        $profile = $this->store->testingProfile(self::TENANT, $saved['version']);
        return $this->store->markTested(self::TENANT, self::ACTOR, $saved['version'], $profile->revision());
    }

    private function failure(string $reason, int $status, callable $action): void
    {
        try {
            $action();
            self::fail('Expected ' . $reason);
        } catch (AiFailure $error) {
            self::assertSame($reason, $error->reason);
            self::assertSame($status, $error->getCode());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString(self::SECRET, $error->getMessage());
        }
    }

    private function cipher(): PayloadCipher
    {
        return new PayloadCipher(file_get_contents($this->directory . '/settings.key'));
    }

    private function record(): array
    {
        return $this->cipher()->decrypt(1, 'configuration', 'provider-settings', file_get_contents($this->directory . '/provider.enc'));
    }

    private function replaceRecord(array $record): void
    {
        file_put_contents($this->directory . '/provider.enc', $this->cipher()->encrypt(1, 'configuration', 'provider-settings', $record));
        clearstatcache();
    }

    private function files(): array
    {
        if (!is_dir($this->directory)) { return []; }
        $files = [];
        foreach (new \FilesystemIterator($this->directory) as $file) {
            $files[$file->getFilename()] = file_get_contents($file->getPathname());
        }
        ksort($files);
        return $files;
    }

    private function configured(string $units = 'clinic'): Ai
    {
        $c = clone $this->defaults;
        $c->baseUrl = 'https://gateway.example.test/v1';
        $c->allowedOrigins = 'https://gateway.example.test';
        $c->model = 'synthetic-model';
        $c->apiKey = self::SECRET;
        $c->encryptionKey = base64_encode(str_repeat('k', 32));
        $c->approvedTenants = (string) self::TENANT;
        $c->approvedUnits = $units;
        return $c;
    }

    private function approve(Ai $c): Profile
    {
        $c->enabled = true;
        $c->governanceApproved = true;
        $c->programLibraryApproved = true;
        $c->approvedRevision = Profile::fromConfig($c)->revision();
        $c->compatibilityApprovedRevision = $c->approvedRevision;
        return Profile::fromConfig($c);
    }

    public function testDefaultsStayClinicOnlyAndUnsavedReadCreatesNothing(): void
    {
        self::assertSame('clinic', $this->defaults->approvedUnits);
        self::assertSame('department-programs-v2:prompt-v2', $this->defaults->capabilityRevision);
        self::assertSame(['clinic'], Profile::fromConfig($this->defaults)->approvedUnits());
        self::assertSame(['clinic'], $this->store->summary(self::TENANT)['approved_units']);
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public static function invalidCsv(): iterable
    {
        foreach ([null, [], ['clinic'], true, 1, 'main', '*', 'Clinic', 'clinic,unknown', 'clinic,', ',clinic',
            'clinic,,guidance', 'clinic,clinic', 'clinic, clinic', ' ', "clinic\0", "clinic\n", "clinic\t", "clinic\xff"] as $i => $value) {
            yield 'invalid-' . $i => [$value];
        }
    }

    #[DataProvider('invalidCsv')]
    public function testProfileRejectsMalformedOrUnknownUnitCsv(mixed $value): void
    {
        $c = $this->configured();
        $c->approvedUnits = $value;
        $this->failure('invalid_config', 503, fn () => Profile::fromConfig($c));
    }

    public function testEmptyScopeAllowedOnlyWhileDisabledAndAccessorIsMutationGuarded(): void
    {
        $c = $this->configured('');
        self::assertSame([], Profile::fromConfig($c)->approvedUnits());
        $c->enabled = true;
        $this->failure('invalid_config', 503, fn () => Profile::fromConfig($c));
        $profile = Profile::fromConfig($this->configured(' guidance, bmg '));
        self::assertSame(['guidance', 'bmg'], $profile->approvedUnits());
        $returned = $profile->approvedUnits();
        $returned[] = 'clinic';
        self::assertSame(['guidance', 'bmg'], $profile->approvedUnits());
        $profile->config->approvedUnits = 'clinic,guidance,bmg';
        $this->failure('invalid_config', 503, fn () => $profile->approvedUnits());
    }

    public function testDispatchRequiresApprovedDepartmentTenantAndFreshProof(): void
    {
        $c = $this->configured('guidance,bmg');
        $profile = $this->approve($c);
        foreach (['guidance', 'bmg'] as $unit) {
            self::assertNotEmpty(AnalysisCatalog::forUnit($unit));
            $profile->assertDispatchAllowed(self::TENANT, $unit);
        }
        foreach (['clinic', 'main', 'unknown', '', 'Guidance', ' guidance', 'guidance,bmg'] as $unit) {
            $this->failure('unit_not_approved', 503, fn () => $profile->assertDispatchAllowed(self::TENANT, $unit));
        }
        foreach ([0, -1, self::TENANT + 1] as $tenant) {
            $this->failure('tenant_not_approved', 503, fn () => $profile->assertDispatchAllowed($tenant, 'guidance'));
        }
        $c->approvedUnits = 'clinic,guidance,bmg';
        $changed = Profile::fromConfig($c);
        self::assertNotSame($profile->revision(), $changed->revision());
        $this->failure('revision_not_approved', 503, fn () => $changed->assertDispatchAllowed(self::TENANT, 'guidance'));
        $c->approvedRevision = $changed->revision();
        $this->failure('compatibility_required', 503, fn () => Profile::fromConfig($c)->assertDispatchAllowed(self::TENANT, 'guidance'));
        $this->approve($c)->assertDispatchAllowed(self::TENANT, 'clinic');
        $c->capabilityRevision = 'department-programs-v2:prompt-v999';
        $this->failure('revision_not_approved', 503, fn () => Profile::fromConfig($c)->assertDispatchAllowed(self::TENANT, 'clinic'));
    }

    public static function selections(): iterable
    {
        yield 'legacy form' => [[], ['clinic']];
        yield 'clinic' => [['unit_selection' => '1', 'allow_clinic' => '1'], ['clinic']];
        yield 'guidance without clinic' => [['unit_selection' => '1', 'allow_guidance' => '1'], ['guidance']];
        yield 'bmg without clinic' => [['unit_selection' => 1, 'allow_bmg' => 1], ['bmg']];
        yield 'guidance and bmg' => [['unit_selection' => '1', 'allow_clinic' => '0', 'allow_guidance' => 1, 'allow_bmg' => '1'], ['guidance', 'bmg']];
        yield 'all' => [['unit_selection' => '1', 'allow_clinic' => 1, 'allow_guidance' => '1', 'allow_bmg' => 1], ['clinic', 'guidance', 'bmg']];
    }

    #[DataProvider('selections')]
    public function testSelectionsPersistAndEnableExactlyTheChosenUnits(array $input, array $units): void
    {
        $tested = $this->tested($input);
        self::assertSame($units, $tested['approved_units']);
        self::assertSame($units, $this->record()['approved_units']);
        $c = $this->store->configuration();
        self::assertSame(implode(',', $units), $c->approvedUnits);
        self::assertSame(ProgramLibrary::version() . ':' . InsightGenerator::PROMPT_VERSION, $c->capabilityRevision);
        $profile = $this->store->enable(self::TENANT, self::ACTOR, $tested['version']);
        foreach (['clinic', 'guidance', 'bmg'] as $unit) {
            if (in_array($unit, $units, true)) { $profile->assertDispatchAllowed(self::TENANT, $unit); }
            else { $this->failure('unit_not_approved', 503, fn () => $profile->assertDispatchAllowed(self::TENANT, $unit)); }
        }
        self::assertTrue($this->store->summary(self::TENANT)['enabled']);
        self::assertSame(['saved', 'connection_tested', 'analytics_enabled'], array_column($this->record()['history'], 'action'));
        self::assertStringNotContainsString(self::SECRET, json_encode($this->record()['history'], JSON_THROW_ON_ERROR));
        $this->failure('access_denied', 403, fn () => $this->store->summary(self::TENANT + 1));
    }

    public static function invalidFlags(): iterable
    {
        foreach (['unit_selection', 'allow_clinic', 'allow_guidance', 'allow_bmg'] as $field) {
            foreach ([null, [], ['1'], true, false, 1.0, 0.0, 'true', 'false', 'yes', 'on', '', ' 1', '1 ', '01', '2', 2] as $i => $value) {
                yield $field . '-' . $i => [[$field => $value]];
            }
        }
        yield 'false marker string' => [['unit_selection' => '0']];
        yield 'false marker integer' => [['unit_selection' => 0]];
        yield 'no selection' => [['unit_selection' => '1']];
        yield 'all explicitly unchecked' => [['unit_selection' => '1', 'allow_clinic' => '0', 'allow_guidance' => 0, 'allow_bmg' => '0']];
        yield 'unknown flag' => [['unit_selection' => '1', 'allow_unknown' => '1']];
        yield 'direct scope list' => [['approved_units' => ['guidance']]];
        yield 'direct config csv' => [['approvedUnits' => 'guidance']];
    }

    #[DataProvider('invalidFlags')]
    public function testInvalidFlagsFailStrictlyEvenWithoutMarkerAndNeverRewrite(array $changes): void
    {
        $saved = $this->tested();
        $before = $this->files();
        $this->failure('invalid_settings', 422, fn () => $this->save(array_replace(['version' => $saved['version']], $changes)));
        self::assertSame($before, $this->files());
    }

    public function testExpansionRequiresSaveRetestAndEnableWhilePreservingBothKeys(): void
    {
        $tested = $this->tested();
        $enabled = $this->store->enable(self::TENANT, self::ACTOR, $tested['version']);
        $master = file_get_contents($this->directory . '/settings.key');
        $payloadKey = $enabled->config->encryptionKey;
        $ciphertext = (new PayloadCipher($payloadKey))->encrypt(self::TENANT, 'clinic', 'fixture', ['safe' => true]);
        $oldProof = $this->store->testingProfile(self::TENANT, $tested['version'])->revision();
        $saved = $this->save(['version' => $tested['version'], 'api_key' => '', 'unit_selection' => '1',
            'allow_clinic' => '1', 'allow_guidance' => '1', 'allow_bmg' => '1']);
        self::assertFalse($saved['enabled']);
        self::assertFalse($saved['connection_tested']);
        self::assertNull($saved['tested_at']);
        self::assertNotSame($tested['version'], $saved['version']);
        $this->failure('settings_conflict', 409, fn () => $this->store->markTested(self::TENANT, self::ACTOR, $saved['version'], $oldProof));
        $this->failure('compatibility_required', 422, fn () => $this->store->enable(self::TENANT, self::ACTOR, $saved['version']));
        $testing = $this->store->testingProfile(self::TENANT, $saved['version']);
        self::assertNotSame($oldProof, $testing->revision());
        $this->store->markTested(self::TENANT, self::ACTOR, $saved['version'], $testing->revision());
        $expanded = $this->store->enable(self::TENANT, self::ACTOR, $saved['version']);
        foreach ($expanded->approvedUnits() as $unit) { $expanded->assertDispatchAllowed(self::TENANT, $unit); }
        self::assertSame(self::SECRET, $expanded->config->apiKey);
        self::assertSame($payloadKey, $expanded->config->encryptionKey);
        self::assertSame($master, file_get_contents($this->directory . '/settings.key'));
        self::assertSame(['safe' => true], (new PayloadCipher($expanded->config->encryptionKey))->decrypt(self::TENANT, 'clinic', 'fixture', $ciphertext));
        foreach ($this->files() as $contents) {
            self::assertStringNotContainsString(self::SECRET, $contents);
            self::assertStringNotContainsString($payloadKey, $contents);
        }
    }

    public function testFirstLegacyFormSaveDoesNotInheritBroaderDeploymentScope(): void
    {
        $this->defaults->approvedUnits = 'clinic,guidance,bmg';
        $this->store = new SettingsStore($this->directory, $this->defaults);
        self::assertSame(['clinic'], $this->save()['approved_units']);
        self::assertSame(['clinic'], $this->record()['approved_units']);
    }

    public function testLegacyFormPreservesSavedScopeEvenIfDefaultAndUnmarkedFlagsDiffer(): void
    {
        $saved = $this->save(['unit_selection' => '1', 'allow_guidance' => '1', 'allow_bmg' => '1']);
        $this->defaults->approvedUnits = 'clinic';
        $this->store = new SettingsStore($this->directory, $this->defaults);
        $saved = $this->save(['version' => $saved['version'], 'allow_clinic' => '1', 'allow_guidance' => '0', 'allow_bmg' => 0]);
        self::assertSame(['guidance', 'bmg'], $saved['approved_units']);
        $saved = $this->save(['version' => $saved['version'], 'unit_selection' => '1', 'allow_bmg' => '1']);
        self::assertSame(['bmg'], $saved['approved_units']);
        self::assertFalse($saved['enabled']);
    }

    public function testMissingLegacyScopeReadsClinicOnlyWithoutChangingVersionKeysOrFiles(): void
    {
        $tested = $this->tested();
        $this->store->enable(self::TENANT, self::ACTOR, $tested['version']);
        $record = $this->record();
        unset($record['approved_units']);
        $this->replaceRecord($record);
        $before = $this->files();
        $this->defaults->approvedUnits = 'clinic,guidance,bmg';
        $this->store = new SettingsStore($this->directory, $this->defaults);
        $summary = $this->store->summary(self::TENANT);
        self::assertSame(['clinic'], $summary['approved_units']);
        self::assertTrue($summary['enabled']);
        self::assertSame($record['version'], $summary['version']);
        $c = $this->store->configuration();
        self::assertSame('clinic', $c->approvedUnits);
        self::assertSame($record['api_key'], $c->apiKey);
        self::assertSame($record['storage_key'], $c->encryptionKey);
        self::assertSame(['clinic'], $this->store->testingProfile(self::TENANT, $summary['version'])->approvedUnits());
        self::assertSame($before, $this->files());
        self::assertArrayNotHasKey('approved_units', $this->record());
        $saved = $this->save(['version' => $summary['version'], 'api_key' => '']);
        self::assertSame(['clinic'], $saved['approved_units']);
        self::assertSame(['clinic'], $this->record()['approved_units']);
    }

    public function testOldFingerprintFailsClosedWithoutRewritingEnabledLegacyRecord(): void
    {
        $saved = $this->save();
        $c = $this->store->testingProfile(self::TENANT, $saved['version'])->config;
        $oldPolicy = get_object_vars($c);
        unset($oldPolicy['apiKey'], $oldPolicy['encryptionKey'], $oldPolicy['approvedRevision'],
            $oldPolicy['compatibilityApprovedRevision'], $oldPolicy['approvedUnits']);
        $oldPolicy['capabilityRevision'] = 'clinic-operations-v1:prompt-v1';
        ksort($oldPolicy);
        $record = $this->record();
        unset($record['approved_units']);
        $record['enabled'] = true;
        $record['tested_revision'] = hash('sha256', json_encode($oldPolicy, JSON_THROW_ON_ERROR));
        $this->replaceRecord($record);
        $before = $this->files();
        $summary = $this->store->summary(self::TENANT);
        self::assertSame(['clinic'], $summary['approved_units']);
        self::assertFalse($summary['enabled']);
        self::assertFalse($summary['connection_tested']);
        $this->failure('compatibility_required', 503, fn () => $this->store->configuration());
        $this->failure('compatibility_required', 422, fn () => $this->store->enable(self::TENANT, self::ACTOR, $saved['version']));
        self::assertSame($before, $this->files());
        $profile = $this->store->testingProfile(self::TENANT, $saved['version']);
        $this->store->markTested(self::TENANT, self::ACTOR, $saved['version'], $profile->revision());
        self::assertSame(['clinic'], $this->store->enable(self::TENANT, self::ACTOR, $saved['version'])->approvedUnits());
    }

    public static function invalidStoredScopes(): iterable
    {
        foreach ([null, [], '', 'clinic', 'clinic,guidance', '["clinic"]', true, 1, ['unknown'], ['main'], ['Clinic'],
            [' clinic'], ['clinic '], ['clinic', 'clinic'], ['clinic', 'guidance', 'bmg', 'clinic'],
            ['clinic', null], ['clinic', 1], [['clinic']], ['allow_clinic' => true], [1 => 'clinic']] as $i => $value) {
            yield 'invalid-' . $i => [$value];
        }
    }

    #[DataProvider('invalidStoredScopes')]
    public function testMalformedEncryptedScopeFailsClosedOnEveryOperationWithoutRepair(mixed $units): void
    {
        $saved = $this->save();
        $record = $this->record();
        $record['approved_units'] = $units;
        $this->replaceRecord($record);
        $before = $this->files();
        foreach ([fn () => $this->store->summary(self::TENANT), fn () => $this->store->configuration(),
            fn () => $this->store->testingProfile(self::TENANT, $saved['version']),
            fn () => $this->save(['version' => $saved['version'], 'unit_selection' => '1', 'allow_clinic' => '1']),
            fn () => $this->store->markTested(self::TENANT, self::ACTOR, $saved['version'], str_repeat('a', 64)),
            fn () => $this->store->enable(self::TENANT, self::ACTOR, $saved['version']),
            fn () => $this->store->disable(self::TENANT, self::ACTOR, $saved['version'])] as $operation) {
            $this->failure('settings_unavailable', 503, $operation);
            self::assertSame($before, $this->files());
        }
    }

    public function testTamperedScopeOrOtherPolicyInvalidatesEnabledSummaryAndConfiguration(): void
    {
        $tested = $this->tested();
        $this->store->enable(self::TENANT, self::ACTOR, $tested['version']);
        $original = $this->record();
        foreach (['approved_units' => ['clinic', 'guidance'], 'model' => 'changed-model', 'max_input_tokens' => 16384] as $field => $value) {
            $record = $original;
            $record[$field] = $value;
            $this->replaceRecord($record);
            $before = $this->files();
            self::assertFalse($this->store->summary(self::TENANT)['enabled']);
            self::assertFalse($this->store->summary(self::TENANT)['connection_tested']);
            $this->failure('compatibility_required', 503, fn () => $this->store->configuration());
            self::assertSame($before, $this->files());
        }
        $this->replaceRecord($original);
        $this->defaults->minimumContributors = 11;
        $this->defaults->retentionDays = 31;
        $this->store = new SettingsStore($this->directory, $this->defaults);
        self::assertFalse($this->store->summary(self::TENANT)['enabled']);
        $this->failure('compatibility_required', 503, fn () => $this->store->configuration());
        $c = $this->store->testingProfile(self::TENANT, $tested['version'])->config;
        self::assertSame(11, $c->minimumContributors);
        self::assertSame(31, $c->retentionDays);
    }
}
