<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiFailure;
use App\Services\Ai\ChatClient;
use App\Services\Ai\EndpointPolicy;
use App\Services\Ai\InsightGenerator;
use App\Services\Ai\PayloadCipher;
use App\Services\Ai\Profile;
use App\Services\Ai\SettingsStore;
use Config\Ai;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/** Synthetic settings only: explicit config, disposable files, injected DNS/HTTP, no dotenv or DB. */
final class AiSettingsStoreTest extends TestCase
{
    private const TENANT = 17;
    private const ACTOR = 23;
    private const SECRET = 'synthetic-settings-secret-never-display';

    private string $root;
    private string $directory;
    private Ai $defaults;
    private SettingsStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/humus-ai-settings-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->root, 0700));
        $this->directory = $this->root . '/settings';
        $this->defaults = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        $this->store = new SettingsStore($this->directory, $this->defaults);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                if ($file->isDir() && !$file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    private function input(array $changes = []): array
    {
        return array_replace([
            'version' => '',
            'base_url' => 'https://gateway.example.test/team/v1',
            'api_key' => self::SECRET,
            'remove_api_key' => '0',
            'model' => 'org/synthetic-model',
            'reasoning_effort' => 'high',
            'thinking_mode' => 'disabled',
            'max_input_tokens' => '16384',
            'max_output_tokens' => '3072',
            'auth_mode' => 'bearer',
            'token_field' => 'max_completion_tokens',
            'structured_output' => 'json_object',
            'allow_private_network' => '0',
        ], $changes);
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

    private function failure(string $reason, int $status, callable $action): AiFailure
    {
        try {
            $action();
            self::fail('Expected controlled failure: ' . $reason);
        } catch (AiFailure $error) {
            self::assertSame($reason, $error->reason);
            self::assertSame($reason, $error->getMessage());
            self::assertSame($status, $error->getCode());
            self::assertNull($error->getPrevious());
            $publicError = ['reason' => $error->reason, 'message' => $error->getMessage(), 'status' => $error->getCode()];
            foreach ([$error->getMessage(), $error->getTraceAsString(), json_encode($error, JSON_THROW_ON_ERROR), serialize($publicError)] as $public) {
                self::assertStringNotContainsString(self::SECRET, $public);
            }
            return $error;
        }
    }

    /** Inspect only our encrypted fixture, never the deployment's settings. */
    private function record(): array
    {
        $cipher = new PayloadCipher(file_get_contents($this->directory . '/settings.key'));
        return $cipher->decrypt(1, 'configuration', 'provider-settings', file_get_contents($this->directory . '/provider.enc'));
    }

    private function replaceRecord(array $record): void
    {
        $cipher = new PayloadCipher(file_get_contents($this->directory . '/settings.key'));
        file_put_contents($this->directory . '/provider.enc', $cipher->encrypt(1, 'configuration', 'provider-settings', $record));
        clearstatcache();
    }

    /** Excludes access time: reading is allowed, creating/changing files is not. */
    private function diskSnapshot(): array
    {
        clearstatcache();
        $snapshot = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $snapshot[substr($file->getPathname(), strlen($this->root))] = [
                'contents' => file_get_contents($file->getPathname()),
                'modified' => $file->getMTime(),
                'mode' => $file->getPerms() & 0777,
            ];
        }
        ksort($snapshot);
        return $snapshot;
    }

    public function testUnsavedReadsReturnDisabledDefaultsWithoutCreatingStorage(): void
    {
        self::assertSame([], $this->diskSnapshot());
        $summary = $this->store->summary(self::TENANT);
        self::assertSame([
            'saved' => false, 'version' => '', 'base_url' => '', 'model' => '', 'has_api_key' => false,
            'reasoning_effort' => '', 'thinking_mode' => '', 'max_input_tokens' => 8192, 'max_output_tokens' => 2000,
            'auth_mode' => 'bearer', 'token_field' => 'max_tokens', 'structured_output' => 'none',
            'allow_private_network' => false, 'approved_units' => ['clinic'], 'connection_tested' => false, 'tested_at' => null,
            'enabled' => false, 'storage_key_ready' => false,
        ], $summary);
        $config = $this->store->configuration();
        self::assertNotSame($this->defaults, $config);
        self::assertSame(get_object_vars($this->defaults), get_object_vars($config));
        $config->model = 'caller-mutation';
        self::assertSame('', $this->store->configuration()->model);
        $this->failure('settings_not_saved', 422, fn () => $this->store->testingProfile(self::TENANT, ''));
        self::assertDirectoryDoesNotExist($this->directory);
        self::assertSame([], $this->diskSnapshot());
    }

    public function testConstructorSnapshotsExplicitDefaultsAndNeverExposesDefaultSecretInSummary(): void
    {
        $this->defaults->baseUrl = 'https://defaults.example.test/v1';
        $this->defaults->model = 'default-model';
        $this->defaults->apiKey = self::SECRET;
        $store = new SettingsStore($this->directory, $this->defaults);
        $this->defaults->model = 'changed-after-construction';
        self::assertSame('default-model', $store->configuration()->model);
        self::assertTrue($store->summary(self::TENANT)['has_api_key']);
        self::assertStringNotContainsString(self::SECRET, serialize($store->summary(self::TENANT)));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testSaveRoundTripsEveryFieldEncryptsSecretsAndBindsOnlyOwnerTenant(): void
    {
        $summary = $this->save();
        self::assertTrue($summary['saved']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $summary['version']);
        foreach (['base_url', 'model', 'reasoning_effort', 'thinking_mode', 'auth_mode', 'token_field', 'structured_output'] as $field) {
            self::assertSame($this->input()[$field], $summary[$field]);
        }
        self::assertSame(16384, $summary['max_input_tokens']);
        self::assertSame(3072, $summary['max_output_tokens']);
        self::assertTrue($summary['has_api_key']);
        self::assertTrue($summary['storage_key_ready']);
        self::assertFalse($summary['allow_private_network']);
        self::assertFalse($summary['enabled']);
        self::assertFalse($summary['connection_tested']);
        self::assertNull($summary['tested_at']);
        self::assertArrayNotHasKey('api_key', $summary);
        self::assertArrayNotHasKey('storage_key', $summary);
        self::assertStringNotContainsString(self::SECRET, serialize($summary));
        $config = $this->store->configuration();
        self::assertSame(self::SECRET, $config->apiKey);
        self::assertSame((string) self::TENANT, $config->approvedTenants);
        self::assertSame($summary['version'], $config->credentialVersion);
        self::assertSame('https://gateway.example.test', $config->allowedOrigins);
        self::assertSame('', $config->privateOrigins);
        self::assertFalse($config->allowLocalHttp);
        self::assertFalse($config->allowPrivateHttpDispatch);
        self::assertFalse($config->governanceApproved);
        self::assertFalse($config->programLibraryApproved);
        self::assertSame('', $config->approvedRevision);
        self::assertSame('', $config->compatibilityApprovedRevision);
        self::assertSame($summary, (new SettingsStore($this->directory, $this->defaults))->summary(self::TENANT));
        self::assertSame(self::TENANT, $this->record()['tenant_id']);
        self::assertSame(self::ACTOR, $this->record()['history'][0]['actor_id']);
        foreach ($this->diskSnapshot() as $file) {
            self::assertStringNotContainsString(self::SECRET, $file['contents']);
            self::assertStringNotContainsString($config->encryptionKey, $file['contents']);
        }
        self::assertStringStartsWith('v1.', file_get_contents($this->directory . '/provider.enc'));
        self::assertCount(3, $this->diskSnapshot());
    }

    public function testSaveDefaultsOptionalFieldsAndNormalizesFormStrings(): void
    {
        $summary = $this->store->save(self::TENANT, self::ACTOR, [
            'base_url' => '  https://gateway.example.test/v1  ',
            'model' => '  synthetic-model  ', 'api_key' => '  ' . self::SECRET . '  ',
        ]);
        self::assertSame('https://gateway.example.test/v1', $summary['base_url']);
        self::assertSame('synthetic-model', $summary['model']);
        self::assertSame('', $summary['reasoning_effort']);
        self::assertSame('', $summary['thinking_mode']);
        self::assertSame(8192, $summary['max_input_tokens']);
        self::assertSame(2000, $summary['max_output_tokens']);
        self::assertSame('bearer', $summary['auth_mode']);
        self::assertSame('max_tokens', $summary['token_field']);
        self::assertSame('none', $summary['structured_output']);
        self::assertSame(self::SECRET, $this->store->configuration()->apiKey);
    }

    public function testLegacyRecordWithoutThinkingModeKeepsProviderDefaultAndApproval(): void
    {
        $tested = $this->tested(['thinking_mode' => '']);
        $record = $this->record();
        unset($record['thinking_mode']);
        $this->replaceRecord($record);
        $this->defaults->thinkingMode = 'enabled';
        $store = new SettingsStore($this->directory, $this->defaults);
        self::assertSame('', $store->configuration()->thinkingMode);
        self::assertSame('', $store->summary(self::TENANT)['thinking_mode']);
        self::assertTrue($store->summary(self::TENANT)['connection_tested']);
        self::assertSame($tested['version'], $store->summary(self::TENANT)['version']);
        $store->enable(self::TENANT, self::ACTOR, $tested['version'])->assertDispatchAllowed(self::TENANT, 'clinic');
    }

    public function testThinkingChangesRequireRetestAndCannotReusePreviousEvidence(): void
    {
        $tested = $this->tested(['thinking_mode' => '']);
        $revision = $this->store->testingProfile(self::TENANT, $tested['version'])->revision();
        $this->store->enable(self::TENANT, self::ACTOR, $tested['version']);
        $saved = $this->save(['version' => $tested['version'], 'thinking_mode' => 'disabled']);
        self::assertSame('disabled', $saved['thinking_mode']);
        self::assertSame('disabled', $this->record()['thinking_mode']);
        self::assertSame('disabled', $this->store->configuration()->thinkingMode);
        self::assertFalse($saved['enabled']);
        self::assertFalse($saved['connection_tested']);
        $this->failure('settings_conflict', 409, fn () => $this->store->markTested(self::TENANT, self::ACTOR, $saved['version'], $revision));
        $this->failure('compatibility_required', 422, fn () => $this->store->enable(self::TENANT, self::ACTOR, $saved['version']));
        // The thinking value itself invalidates evidence even without a version change.
        $profile = $this->store->testingProfile(self::TENANT, $saved['version']);
        $this->store->markTested(self::TENANT, self::ACTOR, $saved['version'], $profile->revision());
        $record = $this->record();
        $record['thinking_mode'] = 'enabled';
        $this->replaceRecord($record);
        self::assertFalse($this->store->summary(self::TENANT)['connection_tested']);
        $this->failure('compatibility_required', 422, fn () => $this->store->enable(self::TENANT, self::ACTOR, $saved['version']));
    }

    public function testBlankKeyPreservesSavedOrDefaultSecretAndExplicitRemovalIsRequired(): void
    {
        $this->defaults->apiKey = self::SECRET;
        $this->store = new SettingsStore($this->directory, $this->defaults);
        $saved = $this->save(['api_key' => '']);
        self::assertSame(self::SECRET, $this->store->configuration()->apiKey);
        $saved = $this->save(['version' => $saved['version'], 'api_key' => '   ']);
        self::assertTrue($saved['has_api_key']);
        self::assertSame(self::SECRET, $this->store->configuration()->apiKey);
        $before = $this->diskSnapshot();
        $this->failure('invalid_settings', 422, fn () => $this->save(['version' => $saved['version'], 'remove_api_key' => '1']));
        self::assertSame($before, $this->diskSnapshot());
        $this->failure('invalid_config', 503, fn () => $this->save(['version' => $saved['version'], 'api_key' => '', 'remove_api_key' => '1']));
        self::assertSame($before, $this->diskSnapshot());
        $removed = $this->save(['version' => $saved['version'], 'api_key' => '', 'remove_api_key' => '1', 'auth_mode' => 'none']);
        self::assertFalse($removed['has_api_key']);
        self::assertSame('', $this->store->configuration()->apiKey);
        self::assertSame('none', $this->store->configuration()->authMode);
    }

    public static function secretFailurePaths(): iterable
    {
        yield 'owner read failure' => ['summary'];
        yield 'stale write failure' => ['save'];
    }

    #[DataProvider('secretFailurePaths')]
    public function testSerializedFailureFramesNeverContainSavedSecret(string $operation): void
    {
        $this->save();
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $error = $operation === 'summary'
                ? $this->failure('access_denied', 403, fn () => $this->store->summary(self::TENANT + 1))
                : $this->failure('settings_conflict', 409, fn () => $this->save());
            foreach ($error->getTrace() as $frame) {
                if (($frame['class'] ?? '') === SettingsStore::class) {
                    // Check production frames only; the caller deliberately supplied a synthetic secret.
                    self::assertFalse(
                        str_contains(json_encode($frame, JSON_THROW_ON_ERROR), self::SECRET),
                        'SettingsStore::' . $frame['function'] . ' captures the saved API key in serialized error frames.',
                    );
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testReturnedTestingProfileIsGuardedAndCannotMutateStoredConfiguration(): void
    {
        $saved = $this->save();
        $profile = $this->store->testingProfile(self::TENANT, $saved['version']);
        $before = $this->diskSnapshot();
        $profile->config->model = 'mutated-caller-model';
        $this->failure('invalid_config', 503, fn () => $profile->revision());
        self::assertSame('org/synthetic-model', $this->store->configuration()->model);
        self::assertSame($before, $this->diskSnapshot());
    }

    public static function storageKeys(): iterable
    {
        yield 'generated independently' => [''];
        yield 'explicit deployment key' => [base64_encode(str_repeat('k', 32))];
    }

    #[DataProvider('storageKeys')]
    public function testStorageKeySurvivesProviderAndSecretChanges(string $initialKey): void
    {
        $this->defaults->encryptionKey = $initialKey;
        $this->store = new SettingsStore($this->directory, $this->defaults);
        $saved = $this->save();
        $storageKey = $this->store->configuration()->encryptionKey;
        self::assertSame(32, strlen(base64_decode($storageKey, true)));
        if ($initialKey !== '') {
            self::assertSame($initialKey, $storageKey);
        }
        $fileKey = file_get_contents($this->directory . '/settings.key');
        $cipher = new PayloadCipher($storageKey);
        $payload = $cipher->encrypt(self::TENANT, 'clinic', 'test', ['synthetic' => true]);
        $saved = $this->save(['version' => $saved['version'], 'api_key' => 'synthetic-rotated-key', 'base_url' => 'https://replacement.example.test/v1']);
        self::assertSame($storageKey, $this->store->configuration()->encryptionKey);
        $this->save(['version' => $saved['version'], 'api_key' => '', 'remove_api_key' => '1', 'auth_mode' => 'none']);
        $reopened = new SettingsStore($this->directory, $this->defaults);
        self::assertSame($storageKey, $reopened->configuration()->encryptionKey);
        self::assertSame($fileKey, file_get_contents($this->directory . '/settings.key'));
        self::assertSame(['synthetic' => true], (new PayloadCipher($reopened->configuration()->encryptionKey))->decrypt(self::TENANT, 'clinic', 'test', $payload));
    }

    public function testEnableRequiresExactTestedRevisionAndCreatesFreshApprovedEnabledProfile(): void
    {
        $saved = $this->save();
        $this->failure('compatibility_required', 422, fn () => $this->store->enable(self::TENANT, self::ACTOR, $saved['version']));
        $testing = $this->store->testingProfile(self::TENANT, $saved['version']);
        self::assertFalse($testing->config->enabled);
        $this->failure('disabled', 503, fn () => $testing->assertDispatchAllowed(self::TENANT, 'clinic'));
        $this->failure('settings_conflict', 409, fn () => $this->store->markTested(self::TENANT, self::ACTOR, $saved['version'], str_repeat('0', 64)));
        self::assertFalse($this->store->summary(self::TENANT)['connection_tested']);
        $tested = $this->store->markTested(self::TENANT, self::ACTOR, $saved['version'], $testing->revision());
        self::assertSame($saved['version'], $tested['version']);
        self::assertTrue($tested['connection_tested']);
        self::assertNotEmpty($tested['tested_at']);
        self::assertFalse($tested['enabled']);
        $enabled = $this->store->enable(self::TENANT, self::ACTOR, $saved['version']);
        self::assertTrue($enabled->config->enabled);
        self::assertTrue($enabled->config->governanceApproved);
        self::assertTrue($enabled->config->programLibraryApproved);
        self::assertNotSame($testing->revision(), $enabled->revision());
        self::assertSame($enabled->revision(), $enabled->config->approvedRevision);
        self::assertSame($enabled->revision(), $enabled->config->compatibilityApprovedRevision);
        $enabled->assertDispatchAllowed(self::TENANT, 'clinic');
        (new InsightGenerator($enabled))->assertProfile($enabled);
        $this->failure('tenant_not_approved', 503, fn () => $enabled->assertDispatchAllowed(self::TENANT + 1, 'clinic'));
        $this->failure('unit_not_approved', 503, fn () => $enabled->assertDispatchAllowed(self::TENANT, 'guidance'));
        self::assertTrue($this->store->summary(self::TENANT)['enabled']);
        self::assertSame($enabled->revision(), Profile::fromConfig($this->store->configuration())->revision());
        self::assertSame(['saved', 'connection_tested', 'analytics_enabled'], array_column($this->record()['history'], 'action'));
        // DeploymentControl is deliberately not resumed here: activation is owned by the caller.
        self::assertCount(3, $this->diskSnapshot());
    }

    public function testDisableAndRetestRevokeApprovalWithoutLosingSettingsOrTestEvidence(): void
    {
        $tested = $this->tested();
        $enabled = $this->store->enable(self::TENANT, self::ACTOR, $tested['version']);
        $this->store->disable(self::TENANT, self::ACTOR, $tested['version']);
        $disabled = $this->store->summary(self::TENANT);
        self::assertFalse($disabled['enabled']);
        self::assertTrue($disabled['connection_tested']);
        self::assertSame($tested['version'], $disabled['version']);
        self::assertSame($tested['tested_at'], $disabled['tested_at']);
        $config = $this->store->configuration();
        self::assertFalse($config->governanceApproved);
        self::assertFalse($config->programLibraryApproved);
        self::assertSame('', $config->approvedRevision);
        self::assertSame('', $config->compatibilityApprovedRevision);
        self::assertSame(self::SECRET, $config->apiKey);
        $this->store->enable(self::TENANT, self::ACTOR, $tested['version']);
        $testing = $this->store->testingProfile(self::TENANT, $tested['version']);
        $retested = $this->store->markTested(self::TENANT, self::ACTOR, $tested['version'], $testing->revision());
        self::assertFalse($retested['enabled']);
        self::assertTrue($retested['connection_tested']);
        // Previously returned objects stay immutable snapshots; fresh reads must reflect revocation.
        self::assertTrue($enabled->config->enabled);
        self::assertFalse($this->store->configuration()->enabled);
    }

    public function testEverySaveChangesVersionAndInvalidatesEvenAnUnchangedPreviouslyTestedProfile(): void
    {
        $tested = $this->tested();
        $this->store->enable(self::TENANT, self::ACTOR, $tested['version']);
        $oldRevision = $this->store->testingProfile(self::TENANT, $tested['version'])->revision();
        $saved = $this->save(['version' => $tested['version'], 'api_key' => '']);
        self::assertNotSame($tested['version'], $saved['version']);
        self::assertNotSame($oldRevision, $this->store->testingProfile(self::TENANT, $saved['version'])->revision());
        self::assertFalse($saved['enabled']);
        self::assertFalse($saved['connection_tested']);
        self::assertNull($saved['tested_at']);
        $this->failure('settings_conflict', 409, fn () => $this->store->markTested(self::TENANT, self::ACTOR, $saved['version'], $oldRevision));
        $this->failure('compatibility_required', 422, fn () => $this->store->enable(self::TENANT, self::ACTOR, $saved['version']));
    }

    public static function operations(): iterable
    {
        foreach (['summary', 'save', 'testingProfile', 'markTested', 'enable', 'disable'] as $operation) {
            yield $operation => [$operation];
        }
    }

    private function operation(string $operation, int $tenant, int $actor, string $version, string $revision): mixed
    {
        return match ($operation) {
            'summary' => $this->store->summary($tenant),
            'save' => $this->store->save($tenant, $actor, $this->input(['version' => $version])),
            'testingProfile' => $this->store->testingProfile($tenant, $version),
            'markTested' => $this->store->markTested($tenant, $actor, $version, $revision),
            'enable' => $this->store->enable($tenant, $actor, $version),
            'disable' => $this->store->disable($tenant, $actor, $version),
        };
    }

    #[DataProvider('operations')]
    public function testDifferentTenantCannotReadTestChangeOrEnableSavedSettings(string $operation): void
    {
        $tested = $this->tested();
        $revision = $this->store->testingProfile(self::TENANT, $tested['version'])->revision();
        $before = $this->diskSnapshot();
        foreach ([0, -1, self::TENANT + 1] as $tenant) {
            $this->failure('access_denied', 403, fn () => $this->operation($operation, $tenant, self::ACTOR, $tested['version'], $revision));
            self::assertSame($before, $this->diskSnapshot());
        }
    }

    #[DataProvider('operations')]
    public function testVersionedOperationsRejectStaleAndMissingVersionsWithoutChangingRecord(string $operation): void
    {
        if ($operation === 'summary') {
            self::assertSame(1, (new \ReflectionMethod(SettingsStore::class, 'summary'))->getNumberOfParameters());
            return;
        }
        $tested = $this->tested();
        $revision = $this->store->testingProfile(self::TENANT, $tested['version'])->revision();
        $before = $this->diskSnapshot();
        foreach (['', str_repeat('f', 32), $tested['version'] . 'x'] as $version) {
            $this->failure('settings_conflict', 409, fn () => $this->operation($operation, self::TENANT, self::ACTOR, $version, $revision));
            self::assertSame($before, $this->diskSnapshot());
        }
    }

    public static function writes(): iterable
    {
        foreach (['save', 'markTested', 'enable', 'disable'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('writes')]
    public function testEveryMutationRequiresPositiveActor(string $operation): void
    {
        $tested = $this->tested();
        $revision = $this->store->testingProfile(self::TENANT, $tested['version'])->revision();
        $before = $this->diskSnapshot();
        foreach ([0, -1] as $actor) {
            $this->failure('access_denied', 403, fn () => $this->operation($operation, self::TENANT, $actor, $tested['version'], $revision));
            self::assertSame($before, $this->diskSnapshot());
        }
    }

    public static function protectedFields(): iterable
    {
        foreach (['enabled', 'governanceApproved', 'programLibraryApproved', 'approvedRevision', 'compatibilityApprovedRevision',
            'approvedTenants', 'allowedOrigins', 'privateOrigins', 'allowLocalHttp', 'allowPrivateHttpDispatch', 'encryptionKey',
            'storage_key', 'tenant_id', 'actor_id', 'tested_revision', 'tested_at', 'history', 'resolverPhpBinary', 'provider'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('protectedFields')]
    public function testProviderMetadataAndApprovalCannotBeInjectedAsSaveFields(string $field): void
    {
        $before = $this->diskSnapshot();
        $this->failure('invalid_settings', 422, fn () => $this->save([$field => 'attacker-controlled']));
        self::assertSame($before, $this->diskSnapshot());
    }

    public static function invalidInputs(): iterable
    {
        foreach (['base_url', 'api_key', 'model', 'reasoning_effort', 'thinking_mode', 'auth_mode', 'token_field', 'structured_output'] as $field) {
            foreach ([null, [], 12, true, "bad\0value", "bad\nvalue", "\xff"] as $i => $value) {
                yield $field . '/invalid-' . $i => [[$field => $value], 'invalid_settings', 422];
            }
        }
        foreach (['base_url' => 2048, 'api_key' => 4096, 'model' => 191, 'reasoning_effort' => 32, 'thinking_mode' => 32, 'auth_mode' => 32, 'token_field' => 40, 'structured_output' => 32] as $field => $max) {
            yield $field . '/too-long' => [[$field => str_repeat('x', $max + 1)], 'invalid_settings', 422];
        }
        foreach (['base_url', 'model'] as $field) {
            yield $field . '/empty' => [[$field => '  '], 'invalid_settings', 422];
        }
        foreach (['max_input_tokens' => [512, 131072], 'max_output_tokens' => [1, 32768]] as $field => [$min, $max]) {
            foreach ([null, [], true, 512.0, '1e3', '+512', '0512', ' 512', '512 ', '512.0', -1, $min - 1, $max + 1, str_repeat('9', 40)] as $i => $value) {
                yield $field . '/invalid-' . $i => [[$field => $value], 'invalid_settings', 422];
            }
        }
        foreach (['allow_private_network', 'remove_api_key'] as $field) {
            foreach ([true, false, 'true', 'false', 'yes', 'on', '', null, [], 2] as $i => $value) {
                yield $field . '/invalid-' . $i => [[$field => $value], 'invalid_settings', 422];
            }
        }
        foreach (['version' => null, 'reasoning_effort' => 'ultra', 'thinking_mode' => 'none', 'auth_mode' => 'basic', 'token_field' => 'max_input_tokens',
            'structured_output' => 'json_schema', 'model' => 'model with spaces', 'api_key' => 'key with spaces'] as $field => $value) {
            yield $field . '/unsupported' => [[$field => $value], $field === 'version' ? 'settings_conflict' : 'invalid_config', $field === 'version' ? 409 : 503];
        }
        foreach (['https://user:pass@gateway.example.test/v1', 'https://gateway.example.test/v1?key=secret',
            'https://gateway.example.test/v1#fragment', 'https://gateway.example.test/v1/chat/completions',
            'file:///etc/passwd', 'https://gateway.example.test/../v1'] as $i => $url) {
            yield 'unsafe-url-' . $i => [['base_url' => $url], 'invalid_config', 503];
        }
    }

    #[DataProvider('invalidInputs')]
    public function testMalformedSaveFailsClosedAndPreservesExistingSettings(array $changes, string $reason, int $status): void
    {
        $saved = $this->save();
        $before = $this->diskSnapshot();
        $this->failure($reason, $status, fn () => $this->save(array_replace(['version' => $saved['version']], $changes)));
        self::assertSame($before, $this->diskSnapshot());
    }

    public static function tokenBoundaries(): iterable
    {
        yield 'minimums' => [512, 1];
        yield 'maximums' => [131072, 32768];
    }

    #[DataProvider('tokenBoundaries')]
    public function testValidTokenBoundariesRoundTripAsIntegers(int $input, int $output): void
    {
        $saved = $this->save(['max_input_tokens' => $input, 'max_output_tokens' => (string) $output]);
        self::assertSame($input, $saved['max_input_tokens']);
        self::assertSame($output, $saved['max_output_tokens']);
        $config = $this->store->testingProfile(self::TENANT, $saved['version'])->config;
        self::assertSame($input, $config->maxInputTokens);
        self::assertSame($output, $config->maxOutputTokens);
    }

    public function testPrivateHttpRequiresExplicitCheckboxAndStaysTenantBoundAfterEnable(): void
    {
        $url = 'http://127.0.0.1:11434/v1';
        $this->failure('https_required', 422, fn () => $this->save(['base_url' => $url]));
        $tested = $this->tested(['base_url' => $url, 'allow_private_network' => '1', 'auth_mode' => 'none', 'api_key' => '', 'reasoning_effort' => '']);
        $profile = $this->store->enable(self::TENANT, self::ACTOR, $tested['version']);
        self::assertTrue($profile->config->allowLocalHttp);
        self::assertTrue($profile->config->allowPrivateHttpDispatch);
        self::assertSame('http://127.0.0.1:11434', $profile->config->privateOrigins);
        self::assertSame((string) self::TENANT, $profile->config->approvedTenants);
        $profile->assertDispatchAllowed(self::TENANT, 'clinic');
        $endpoint = (new EndpointPolicy($profile, static function (): never {
            self::fail('Literal loopback must not resolve DNS.');
        }))->validate();
        self::assertSame(['127.0.0.1'], $endpoint['addresses']);
        $saved = $this->save(['version' => $tested['version']]);
        self::assertFalse($saved['allow_private_network']);
        self::assertSame('', $this->store->configuration()->privateOrigins);
        self::assertFalse($this->store->configuration()->allowPrivateHttpDispatch);
    }

    public static function metadataAddresses(): iterable
    {
        foreach (['169.254.169.254', '169.254.170.2', '[fd00:ec2::254]', '[fd20:ce::254]'] as $host) {
            yield $host => [$host];
        }
    }

    #[DataProvider('metadataAddresses')]
    public function testPrivateOptInNeverLetsMetadataReachTransport(string $host): void
    {
        $saved = $this->save(['base_url' => 'http://' . $host . '/v1', 'allow_private_network' => '1']);
        $profile = $this->store->testingProfile(self::TENANT, $saved['version']);
        $client = new ChatClient($profile, static function (): never {
            self::fail('Metadata must never reach HTTP transport.');
        }, static function (): never {
            self::fail('Literal metadata must never use DNS.');
        });
        $this->failure('address_not_allowed', 503, fn () => $client->probe());
    }

    public function testPrivateHostnameResolvingToMetadataNeverReachesTransport(): void
    {
        $saved = $this->save(['base_url' => 'https://private.example.test/v1', 'allow_private_network' => '1']);
        $profile = $this->store->testingProfile(self::TENANT, $saved['version']);
        $resolved = 0;
        $client = new ChatClient($profile, static function (): never {
            self::fail('Metadata DNS answer must never reach HTTP transport.');
        }, static function (string $host) use (&$resolved): array {
            self::assertSame('private.example.test', $host);
            ++$resolved;
            return ['127.0.0.1', '169.254.169.254'];
        });
        $this->failure('address_not_allowed', 503, fn () => $client->probe());
        self::assertSame(1, $resolved);
    }

    public function testLostEncryptedProfileCannotSilentlyGenerateAnotherStorageKey(): void
    {
        $this->save();
        $key = file_get_contents($this->directory . '/settings.key');
        unlink($this->directory . '/provider.enc');
        $before = $this->diskSnapshot();
        $this->failure('settings_unavailable', 503, fn () => $this->store->configuration());
        $this->failure('settings_unavailable', 503, fn () => $this->store->summary(self::TENANT));
        $this->failure('settings_unavailable', 503, fn () => $this->save());
        self::assertSame($key, file_get_contents($this->directory . '/settings.key'));
        self::assertSame($before, $this->diskSnapshot());
    }

    public function testSavedReadsAndTestingProfileDoNotWriteOrRepairFiles(): void
    {
        $saved = $this->save();
        $before = $this->diskSnapshot();
        $this->store->summary(self::TENANT);
        $this->store->configuration();
        $this->store->testingProfile(self::TENANT, $saved['version']);
        self::assertSame($before, $this->diskSnapshot());
        unlink($this->directory . '/settings.lock');
        $before = $this->diskSnapshot();
        $this->failure('settings_busy', 409, fn () => $this->store->summary(self::TENANT));
        self::assertSame($before, $this->diskSnapshot());
        self::assertFileDoesNotExist($this->directory . '/settings.lock');
    }

    public function testReadDoesNotChangeExistingDirectoryOrLockPermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows chmod does not implement POSIX permission bits.');
        }
        $this->save();
        self::assertTrue(chmod($this->directory, 0750));
        self::assertTrue(chmod($this->directory . '/settings.lock', 0640));
        clearstatcache();
        $directoryMode = fileperms($this->directory) & 0777;
        $before = $this->diskSnapshot();
        $this->store->summary(self::TENANT);
        clearstatcache();
        self::assertSame($directoryMode, fileperms($this->directory) & 0777, 'GET-like reads must not chmod the directory.');
        self::assertSame($before, $this->diskSnapshot());
    }

    public static function corruptedEnvelopes(): iterable
    {
        yield 'empty' => [''];
        yield 'not an envelope' => ['not-encrypted'];
        yield 'truncated' => ['v1.AAAA'];
        yield 'invalid base64' => ['v1.!!!!'];
        yield 'oversized' => [str_repeat('x', 262145)];
    }

    #[DataProvider('corruptedEnvelopes')]
    public function testCorruptStorageNeverFallsBackToDefaultsOrLeaksSecrets(string $contents): void
    {
        $this->save();
        file_put_contents($this->directory . '/provider.enc', $contents);
        $before = $this->diskSnapshot();
        $reason = strlen($contents) > 262144 ? 'settings_unavailable' : 'payload_unavailable';
        $this->failure($reason, 503, fn () => $this->store->configuration());
        $this->failure($reason, 503, fn () => $this->store->summary(self::TENANT));
        self::assertSame($before, $this->diskSnapshot());
    }

    public static function malformedRecords(): iterable
    {
        foreach (['schema' => 2, 'tenant_id' => '17', 'version' => 'not-a-version', 'enabled' => 'true',
            'allow_private_network' => 1, 'history' => null] as $field => $value) {
            yield $field => [$field, $value];
        }
    }

    #[DataProvider('malformedRecords')]
    public function testMalformedDecryptedRecordIsRejectedWithoutRewrite(string $field, mixed $value): void
    {
        $this->save();
        $record = $this->record();
        $record[$field] = $value;
        $this->replaceRecord($record);
        $before = $this->diskSnapshot();
        $this->failure('settings_unavailable', 503, fn () => $this->store->configuration());
        self::assertSame($before, $this->diskSnapshot());
    }

    public function testMissingStorageKeyIsNeverRegeneratedForExistingCiphertext(): void
    {
        $this->save();
        unlink($this->directory . '/settings.key');
        $before = $this->diskSnapshot();
        $this->failure('settings_unavailable', 503, fn () => $this->store->configuration());
        $this->failure('settings_unavailable', 503, fn () => $this->save());
        self::assertSame($before, $this->diskSnapshot());
        self::assertFileDoesNotExist($this->directory . '/settings.key');
    }

    public function testEnabledRecordWithoutMatchingTestEvidenceFailsClosed(): void
    {
        $tested = $this->tested();
        $this->store->enable(self::TENANT, self::ACTOR, $tested['version']);
        $record = $this->record();
        $record['tested_revision'] = '';
        $this->replaceRecord($record);
        self::assertFalse($this->store->summary(self::TENANT)['enabled']);
        $this->failure('compatibility_required', 503, fn () => $this->store->configuration());
        $this->failure('compatibility_required', 422, fn () => $this->store->enable(self::TENANT, self::ACTOR, $tested['version']));
    }

    public function testMissingTestEvidenceFailsClosedWithControlledStorageError(): void
    {
        $tested = $this->tested();
        $record = $this->record();
        unset($record['tested_revision']);
        $this->replaceRecord($record);
        $before = $this->diskSnapshot();
        $this->failure('settings_unavailable', 503, fn () => $this->store->summary(self::TENANT));
        $this->failure('settings_unavailable', 503, fn () => $this->store->enable(self::TENANT, self::ACTOR, $tested['version']));
        self::assertSame($before, $this->diskSnapshot());
    }

    public function testLockContentionIsControlledAndDoesNotRewriteSettings(): void
    {
        $this->save();
        $before = $this->diskSnapshot();
        $lock = fopen($this->directory . '/settings.lock', 'rb');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->failure('settings_busy', 409, fn () => $this->store->configuration());
            $this->failure('settings_busy', 409, fn () => $this->save());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertSame($before, $this->diskSnapshot());
        self::assertTrue($this->store->summary(self::TENANT)['saved']);
    }
}
