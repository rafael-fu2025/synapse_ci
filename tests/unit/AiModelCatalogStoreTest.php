<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\ModelCapabilities;
use App\Services\Ai\ModelCatalogStore;
use App\Services\Ai\Profile;
use Config\Ai;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class AiModelCatalogStoreTest extends TestCase
{
    private string $directory;
    private int $time = 1800000000;
    private ModelCatalogStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/humus-catalog-' . bin2hex(random_bytes(12));
        $this->store = new ModelCatalogStore($this->directory, fn (): int => $this->time);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) { unlink($path); }
        if (is_dir($this->directory)) { rmdir($this->directory); }
    }

    private function models(array $ids = ['org/a', 'org/b']): array
    {
        $config = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        return ModelCapabilities::normalize(array_map(static fn (string $id): array => ['id' => $id], $ids), Profile::fromConfig($config));
    }

    private function stage(array $ids = ['org/a', 'org/b'], string $base = 'https://gateway.example.test/v1', int $tenant = 17, int $actor = 23): array
    {
        return $this->store->stage($tenant, $actor, $base, $this->models($ids));
    }

    private function failure(string $reason, callable $call): void
    {
        try { $call(); self::fail('Expected ' . $reason); }
        catch (RuntimeException $error) { self::assertSame($reason, $error->getMessage()); }
    }

    public function testStagingDoesNotImportAndSelectionUsesPersistedDefaults(): void
    {
        $preview = $this->stage();
        self::assertSame($this->time + 600, $preview['expires_at']);
        self::assertSame(['base_url' => '', 'models' => [], 'imported_at' => null], $this->store->summary(17));
        $preview['models'][0]['defaults']['max_output_tokens'] = 1;
        $result = $this->store->import(17, 23, $preview['preview_token'], ['org/a']);
        self::assertSame(1, $result['imported_count']); self::assertSame(1, $result['selected_count']);
        self::assertSame(['org/a'], array_column($result['models'], 'id'));
        self::assertSame(2000, $result['models'][0]['defaults']['max_output_tokens']);
        self::assertSame($this->store->summary(17), (new ModelCatalogStore($this->directory))->summary(17));
        self::assertNotNull($result['imported_at']);
        $this->failure('catalog_preview_invalid', fn () => $this->store->import(17, 23, $preview['preview_token'], [], true));
    }

    public function testSameBaseMergesButChangedBaseReplaces(): void
    {
        $first = $this->stage(['a']); $this->store->import(17, 23, $first['preview_token'], [], true);
        $second = $this->stage(['b'], 'https://GATEWAY.example.test:443/v1/');
        $merged = $this->store->import(17, 23, $second['preview_token'], ['b']);
        self::assertSame(['a', 'b'], array_column($merged['models'], 'id'));
        self::assertSame(1, $merged['imported_count']);
        $third = $this->stage(['c'], 'https://other.example.test/v1');
        $replaced = $this->store->import(17, 23, $third['preview_token'], [], true);
        self::assertSame(['c'], array_column($replaced['models'], 'id'));
    }

    public function testOpaqueIdsAndUnicodeNamesRoundTripThroughSelectedImport(): void
    {
        $ids = ['llama3.2:latest', 'org/model@revision', 'model+variant', '模型/日本語:最新版',
            'model?variant=fast&revision=2', '../opaque//id', '1', '01'];
        $models = $this->models($ids);
        $models[0]['name'] = '模型 日本語: 最新版';
        $preview = $this->store->stage(17, 23, 'https://gateway.example.test/v1', $models);
        $imported = $this->store->import(17, 23, $preview['preview_token'], $ids);
        self::assertCount(count($ids), $imported['models']);
        self::assertSame($preview['models'], $this->store->summary(17)['models']);
    }

    public function testCaseDistinctIdsSurviveStagingSelectionAndMerging(): void
    {
        $first = $this->stage(['Foo', 'foo']);
        self::assertCount(2, $first['models']);
        $this->store->import(17, 23, $first['preview_token'], ['Foo']);
        self::assertSame(['Foo'], array_column($this->store->summary(17)['models'], 'id'));
        $second = $this->stage(['foo']);
        $merged = $this->store->import(17, 23, $second['preview_token'], ['foo']);
        self::assertSame(['Foo', 'foo'], array_column($merged['models'], 'id'));
        $third = $this->stage(['Foo']);
        $updated = $this->store->import(17, 23, $third['preview_token'], ['Foo']);
        self::assertSame(['Foo', 'foo'], array_column($updated['models'], 'id'));
    }

    public function testExactDuplicateIdsAreRejected(): void
    {
        $rows = $this->models(['Foo']); $rows[] = $rows[0];
        $this->failure('catalog_invalid_models', fn () => $this->store->stage(17, 23, 'https://gateway.example.test/v1', $rows));
    }

    public function testTenantActorAndTokenBindingAndExpiry(): void
    {
        $p = $this->stage();
        $this->failure('catalog_preview_invalid', fn () => $this->store->import(18, 23, $p['preview_token'], [], true));
        $this->failure('catalog_preview_invalid', fn () => $this->store->import(17, 24, $p['preview_token'], [], true));
        $this->failure('catalog_preview_invalid', fn () => $this->store->import(17, 23, str_repeat('a', 64), [], true));
        self::assertSame([], $this->store->summary(18)['models']);
        $this->time += 600;
        $this->failure('catalog_preview_expired', fn () => $this->store->import(17, 23, $p['preview_token'], [], true));
    }

    public function testSelectionRejectsUnknownDuplicatesAndClientDefaultObjects(): void
    {
        $p = $this->stage();
        foreach ([[], ['unknown'], ['org/a', 'org/a'], [['id' => 'org/a', 'defaults' => ['max_input_tokens' => 999999]]]] as $ids) {
            $this->failure('catalog_invalid_selection', fn () => $this->store->import(17, 23, $p['preview_token'], $ids));
        }
        $this->failure('catalog_invalid_selection', fn () => $this->store->import(17, 23, $p['preview_token'], ['org/a'], true));
        self::assertSame(2, $this->store->import(17, 23, $p['preview_token'], [], true)['imported_count']);
    }

    public function testNewPreviewInvalidatesOldTokenWithoutChangingCatalog(): void
    {
        $old = $this->stage(); $new = $this->stage(['c']);
        $this->failure('catalog_preview_invalid', fn () => $this->store->import(17, 23, $old['preview_token'], [], true));
        self::assertSame([], $this->store->summary(17)['models']);
        self::assertSame(['c'], array_column($this->store->import(17, 23, $new['preview_token'], [], true)['models'], 'id'));
    }

    public function testCapAppliesToStagesAndMergedCatalog(): void
    {
        $ids = array_map(static fn (int $n): string => 'm' . $n, range(1, 200));
        $first = $this->stage($ids);
        self::assertSame(200, $this->store->import(17, 23, $first['preview_token'], [], true)['imported_count']);
        $second = $this->stage(['extra']);
        try {
            $this->store->import(17, 23, $second['preview_token'], ['extra']);
            self::fail('Expected selected import overflow');
        } catch (RuntimeException $error) {
            self::assertSame('catalog_too_large', $error->getMessage());
            self::assertSame(422, $error->getCode());
        }
        self::assertCount(200, $this->store->summary(17)['models']);
        $rows = $this->models($ids); $rows[] = $this->models(['extra'])[0];
        $this->failure('catalog_too_large', fn () => $this->store->stage(17, 23, 'https://gateway.example.test', $rows));
    }

    public function testImportAllReplacesRetiredModelsAtTheCatalogLimit(): void
    {
        $original = array_map(static fn (int $n): string => 'm' . $n, range(1, 200));
        $first = $this->stage($original);
        $this->store->import(17, 23, $first['preview_token'], [], true);
        $fresh = array_slice($original, 1); $fresh[] = 'replacement';
        $second = $this->stage($fresh);
        $before = $this->store->summary(17);
        $this->failure('catalog_too_large', fn () => $this->store->import(17, 23, $second['preview_token'], ['replacement']));
        self::assertSame($before, $this->store->summary(17));
        // Overflow must not consume the preview: Import all can clean stale IDs using the same token.
        $result = $this->store->import(17, 23, $second['preview_token'], [], true);
        self::assertSame(200, $result['imported_count']);
        self::assertSame(200, $result['selected_count']);
        self::assertCount(200, $result['models']);
        self::assertNotContains('m1', array_column($result['models'], 'id'));
        self::assertContains('replacement', array_column($result['models'], 'id'));
        self::assertSame($second['models'], $result['models']);
        self::assertSame($result['models'], $this->store->summary(17)['models']);
    }

    public function testEmptyPreviewCanBeStagedWithoutChangingExistingCatalog(): void
    {
        $first = $this->stage(['chat']);
        $this->store->import(17, 23, $first['preview_token'], [], true);
        $before = $this->store->summary(17);
        $preview = $this->stage([]);
        self::assertSame([], $preview['models']);
        self::assertSame($before, $this->store->summary(17));
        $this->failure('catalog_invalid_selection', fn () => $this->store->import(17, 23, $preview['preview_token'], [], true));
        self::assertSame($before, $this->store->summary(17));
    }

    public function testRateReservationsPersistAreTenantBoundAndExpire(): void
    {
        for ($i = 0; $i < 10; $i++) { $this->store->reserveDiscovery(17, 23 + $i); }
        $other = new ModelCatalogStore($this->directory, fn (): int => $this->time);
        $this->failure('catalog_rate_limited', fn () => $other->reserveDiscovery(17, 99));
        $other->reserveDiscovery(18, 23);
        $this->time += 59;
        $this->failure('catalog_rate_limited', fn () => $other->reserveDiscovery(17, 23));
        $this->time++;
        $other->reserveDiscovery(17, 23);
        self::assertSame([], $other->summary(17)['models']);
    }

    public function testEncryptedSeparateStorageAndNoApprovalsOrSecrets(): void
    {
        $p = $this->stage(); $this->store->import(17, 23, $p['preview_token'], [], true);
        self::assertFileExists($this->directory . '/17.preview.enc');
        self::assertFileExists($this->directory . '/17.catalog.enc');
        foreach (glob($this->directory . '/*.enc') as $path) {
            $bytes = file_get_contents($path);
            self::assertStringStartsWith('v1.', $bytes);
            self::assertStringNotContainsString('org/a', $bytes);
            self::assertStringNotContainsString($p['preview_token'], $bytes);
        }
        self::assertSame(['base_url', 'models', 'imported_at'], array_keys($this->store->summary(17)));
        self::assertFileDoesNotExist($this->directory . '/provider.enc');
    }

    public function testForeignCiphertextAndTamperingFailClosed(): void
    {
        $p = $this->stage(); $this->store->import(17, 23, $p['preview_token'], [], true);
        copy($this->directory . '/17.catalog.enc', $this->directory . '/18.catalog.enc');
        $this->failure('payload_unavailable', fn () => $this->store->summary(18));
        file_put_contents($this->directory . '/17.catalog.enc', 'v1.corrupt');
        $this->failure('payload_unavailable', fn () => $this->store->summary(17));
    }

    public function testMissingKeyIsNotRecreated(): void
    {
        $this->stage(); unlink($this->directory . '/catalog.key');
        $this->failure('store_unavailable', fn () => $this->store->summary(17));
        self::assertFileDoesNotExist($this->directory . '/catalog.key');
    }

    public function testExclusiveLockContentionFailsSafely(): void
    {
        $this->stage(); $lock = fopen($this->directory . '/catalog.lock', 'r+b');
        try {
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            $this->failure('settings_busy', fn () => $this->store->summary(17));
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function testStageDropsUnknownFieldsButRejectsUntrustedProse(): void
    {
        $rows = $this->models(); $rows[0]['api_key'] = 'must-not-persist';
        $preview = $this->store->stage(17, 23, 'https://gateway.example.test', $rows);
        self::assertArrayNotHasKey('api_key', $preview['models'][0]);
        $rows[0]['warnings'] = ['private provider prose'];
        $this->failure('catalog_invalid_models', fn () => $this->store->stage(17, 23, 'https://gateway.example.test', $rows));
    }

    public function testSymlinkLeafIsRejected(): void
    {
        $this->stage();
        if (!@symlink($this->directory . '/catalog.key', $this->directory . '/18.catalog.enc')) {
            self::markTestSkipped('Symlink creation unavailable');
        }
        $this->failure('store_unavailable', fn () => $this->store->summary(18));
    }
}
