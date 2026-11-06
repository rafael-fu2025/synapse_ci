<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Carousel\CarouselAssetStore;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The managed carousel store: private location, strict path handling and an
 * immutable default scope.
 *
 * @internal
 */
final class CarouselAssetStoreTest extends CIUnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/carousel-store-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }

    private function store(): CarouselAssetStore
    {
        return new CarouselAssetStore($this->root);
    }

    public function testDefaultAssetsAreIdentifiedAndNeverRemoved(): void
    {
        $store = $this->store();

        $this->assertTrue($store->isDefault(CarouselAssetStore::DEFAULT_LIGHT_ASSET));
        $this->assertTrue($store->isDefault(CarouselAssetStore::DEFAULT_DARK_ASSET));
        $this->assertFalse($store->isDefault('uploads/carousel/1/slide_light_abc.jpg'));
        $this->assertFalse($store->isDefault('assets/img/brand/fuel-bg.jpg'));
        $this->assertFalse($store->isDefault(null));

        // The reserved scope is installed, then removal is still refused.
        $this->assertTrue($store->ensureDefaultAssets());
        foreach ($store->defaultAssets() as $slot => $asset) {
            $this->assertFileExists($store->absolute($asset), "The {$slot} default must be installed.");
            $this->assertFalse($store->remove($asset), "The {$slot} default must never be removable.");
            $this->assertFileExists($store->absolute($asset), "The {$slot} default must survive a removal attempt.");
        }
    }

    public function testDefaultAssetsAreInstalledFromTheCommittedPublicSources(): void
    {
        $store = $this->store();
        $defaults = $store->defaultAssets();

        $this->assertFalse($store->exists($defaults['light']));
        $this->assertFalse($store->exists($defaults['dark']));
        $this->assertTrue($store->ensureDefaultAssets());

        foreach ($defaults as $slot => $asset) {
            $this->assertTrue($store->exists($asset), "The {$slot} default must be installed.");

            $absolute = $store->absolute($asset);
            $this->assertIsString($absolute);
            $this->assertStringStartsWith(realpath($this->root), $absolute);
            $this->assertNotFalse(@getimagesize($absolute));
        }

        // Each installed copy keeps the format of the source it was seeded from,
        // so the bytes the route serves always match the published Content-Type.
        $this->assertSame('image/jpeg', getimagesize($store->absolute($defaults['light']))['mime'] ?? null);
        $this->assertSame('image/png', getimagesize($store->absolute($defaults['dark']))['mime'] ?? null);
    }

    public function testEnsureDefaultAssetsIsIdempotent(): void
    {
        $store = $this->store();

        $this->assertTrue($store->ensureDefaultAssets());
        $first = array_map(static fn (string $asset): ?string => $store->absolute($asset), $store->defaultAssets());
        $this->assertTrue($store->ensureDefaultAssets());
        $this->assertSame(
            $first,
            array_map(static fn (string $asset): ?string => $store->absolute($asset), $store->defaultAssets())
        );
    }

    public function testImportDuplicatesPublicArtworkIntoTheTenantScope(): void
    {
        $store = $this->store();

        $managed = $store->import('assets/img/brand/fuel-bg.jpg', 1);
        $this->assertIsString($managed);
        $this->assertSame('uploads/carousel/1/', substr($managed, 0, 19));
        $this->assertTrue($store->isManaged($managed));
        $this->assertFileExists($store->absolute($managed));

        // The original stays where it was committed.
        $this->assertFileExists(FCPATH . 'assets/img/brand/fuel-bg.jpg');
    }

    public function testImportIsDeterministicForTheSameSource(): void
    {
        $store = $this->store();

        $first = $store->import('assets/img/brand/fuel-bg.jpg', 1);
        $second = $store->import('assets/img/brand/fuel-bg.jpg', 1);
        $this->assertSame($first, $second);

        // A different tenant receives its own copy.
        $other = $store->import('assets/img/brand/fuel-bg.jpg', 2);
        $this->assertNotSame($first, $other);
        $this->assertStringContainsString('/2/', $other);
    }

    public function testImportRejectsPathsOutsideTheApprovedDirectories(): void
    {
        $store = $this->store();

        $this->assertNull($store->import('../env', 1));
        $this->assertNull($store->import('assets/img/brand/../../../env.jpg', 1));
        $this->assertNull($store->import('assets/img/brand/missing.jpg', 1));
        $this->assertNull($store->import('private/secret.jpg', 1));
        $this->assertNull($store->import('', 1));
    }

    public function testManagedPathsResolveOnlyInsideTheStore(): void
    {
        $store = $this->store();
        $managed = $store->import('assets/img/brand/fuel-bg.jpg', 1);
        $this->assertIsString($managed);

        $this->assertNotNull($store->absolute($managed));
        $this->assertNull($store->absolute('uploads/carousel/1/../1/../../secret.jpg'));
        $this->assertNull($store->absolute('uploads/carousel/../../secret.jpg'));
        $this->assertNull($store->absolute('uploads/carousel/1/missing.jpg'));
        $this->assertNull($store->absolute('assets/img/brand/fuel-bg.jpg'));
        $this->assertNull($store->absolute('uploads/carousel/1/private.php'));
    }

    public function testScopeDirectoryRejectsUnknownScopes(): void
    {
        $store = $this->store();

        $this->assertNotNull($store->scopeDirectory('1'));
        $this->assertNotNull($store->scopeDirectory(CarouselAssetStore::DEFAULT_SCOPE));
        $this->assertNull($store->scopeDirectory('0'));
        $this->assertNull($store->scopeDirectory('99999999999'));
        $this->assertNull($store->scopeDirectory('default/../1'));
        $this->assertNull($store->scopeDirectory('private'));
    }

    public function testReadingAnAssetNeverCreatesDirectories(): void
    {
        $store = $this->store();

        $this->assertFalse($store->exists(CarouselAssetStore::DEFAULT_LIGHT_ASSET));
        $this->assertNull($store->absolute('uploads/carousel/7/slide_light_abc.jpg'));
        $this->assertDirectoryDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'default');
        $this->assertDirectoryDoesNotExist($this->root . DIRECTORY_SEPARATOR . '7');
    }

    public function testRemoveDeletesManagedArtworkOnly(): void
    {
        $store = $this->store();
        $managed = $store->import('assets/img/brand/fuel-bg.jpg', 1);
        $this->assertIsString($managed);

        $this->assertTrue($store->remove($managed));
        $this->assertFalse($store->exists($managed));

        // A public committed asset is never touched by the store.
        $this->assertFalse($store->remove('assets/img/brand/fuel-bg.jpg'));
        $this->assertFileExists(FCPATH . 'assets/img/brand/fuel-bg.jpg');
    }

    public function testAllowedPathsCoverTheManagedAndLegacyNamespaces(): void
    {
        $store = $this->store();

        $this->assertTrue($store->isAllowed('assets/img/brand/fuel-bg.jpg'));
        $this->assertTrue($store->isAllowed('assets/img/brand/campus/health-light.JPEG'));
        $this->assertTrue($store->isAllowed('uploads/carousel/slide_light_abc.jpg'));
        $this->assertTrue($store->isAllowed('uploads/carousel/1/slide_light_abc.jpg'));
        $this->assertTrue($store->isAllowed('uploads/carousel/default/FoundationU-Light.jpg'));
        $this->assertTrue($store->isAllowed('uploads/carousel/default/FoundationU-Dark.png'));

        $this->assertFalse($store->isAllowed('uploads/carousel/private/image.jpg'));
        $this->assertFalse($store->isAllowed('assets/img/brand/../../../env.jpg'));
        $this->assertFalse($store->isAllowed('uploads/private/image.jpg'));
        $this->assertFalse($store->isAllowed('https://example.com/image.jpg'));
        $this->assertFalse($store->isAllowed(''));
        $this->assertFalse($store->isAllowed(null));
    }
}
