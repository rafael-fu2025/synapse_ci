<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\CarouselSlideModel;
use App\Services\Carousel\CarouselAssetStore;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Duplicate the login carousel artwork into the managed private store.
 *
 * Every image a slide points at is copied out of the public directory into the
 * tenant-scoped store and the slide is repointed at the managed copy. The
 * permanent default image is installed as well. The command is idempotent and
 * can be re-run after new artwork is added.
 */
final class InstallCarouselAssets extends BaseCommand
{
    protected $group       = 'Synapse';
    protected $name        = 'synapse:carousel-assets';
    protected $description = 'Duplicate the login carousel artwork into the managed private store.';
    protected $usage       = 'synapse:carousel-assets [--dry-run]';
    protected $options     = [
        'dry-run' => 'Report what would change without writing anything.',
    ];

    public function run(array $params)
    {
        $dryRun = in_array('--dry-run', $params, true);
        $store = new CarouselAssetStore();
        $slides = new CarouselSlideModel();

        $pending = array_filter(
            $store->defaultAssets(),
            static fn (string $asset): bool => !$store->exists($asset)
        );
        if ($pending !== []) {
            if ($dryRun) {
                CLI::write('Would install the permanent default login images.', 'yellow');
            } elseif ($store->ensureDefaultAssets()) {
                CLI::write('Installed the permanent default login images.', 'green');
            } else {
                CLI::error('Unable to install the permanent default login images.');
                return;
            }
        } else {
            CLI::write('Permanent default login images are already installed.', 'green');
        }

        $migrated = 0;
        $failed = 0;
        foreach ($slides->builder()->resetQuery()->get()->getResultArray() as $slide) {
            foreach (['light_image', 'dark_image'] as $field) {
                $path = $slide[$field] ?? null;
                if (!is_string($path) || $path === '' || $store->isManaged($path)) {
                    continue;
                }

                $managed = $store->import($path, (int) ($slide['tenant_id'] ?? 1));
                if ($managed === null) {
                    CLI::error("Could not import [{$path}] referenced by slide {$slide['id']}.");
                    ++$failed;
                    continue;
                }

                if (!$dryRun) {
                    $slides->builder()->resetQuery()->where('id', $slide['id'])->update([$field => $managed]);
                }

                CLI::write("Slide {$slide['id']} {$field}: {$path} -> {$managed}");
                ++$migrated;
            }
        }

        $removed = $dryRun ? 0 : $this->removeUnreferencedLegacyUploads($slides);

        CLI::write(sprintf(
            'Carousel artwork migration complete: %d reference(s) moved, %d legacy upload(s) removed, %d failure(s).',
            $migrated,
            $removed,
            $failed
        ), $failed > 0 ? 'yellow' : 'green');
    }

    /**
     * Delete legacy public uploads that no slide points at any more.
     */
    private function removeUnreferencedLegacyUploads(CarouselSlideModel $slides): int
    {
        $directory = FCPATH . CarouselAssetStore::LEGACY_UPLOAD;
        if (!is_dir($directory)) {
            return 0;
        }

        $referenced = $slides->builder()->resetQuery()->get()->getResultArray();
        $paths = [];
        foreach ($referenced as $slide) {
            foreach (['light_image', 'dark_image'] as $field) {
                $path = $slide[$field] ?? null;
                if (is_string($path) && $path !== '') {
                    $paths[$path] = true;
                }
            }
        }

        $removed = 0;
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (!is_file($file) || basename($file) === '.gitkeep') {
                continue;
            }

            $logical = CarouselAssetStore::LEGACY_UPLOAD . '/' . basename($file);
            if (isset($paths[$logical])) {
                continue;
            }

            if (@unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }
}
