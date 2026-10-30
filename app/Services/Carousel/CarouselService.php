<?php

declare(strict_types=1);

namespace App\Services\Carousel;

use App\Models\CarouselSlideModel;
use App\Services\CurrentTenant;
use App\Services\Realtime\Channel;
use App\Services\Realtime\Publisher;
use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;

final class CarouselService
{
    private CarouselSlideModel $slideModel;
    private string $uploadPath;
    private ?Closure $publisher;

    public function __construct(?CarouselSlideModel $slideModel = null, ?callable $publisher = null)
    {
        $this->slideModel = $slideModel ?? new CarouselSlideModel();
        $this->uploadPath = FCPATH . 'uploads/carousel/';
        $this->publisher = $publisher === null ? null : Closure::fromCallable($publisher);
    }

    private function publishChanged(int $tenantId): void
    {
        try {
            $db = $this->slideModel->db;
            // Caller-owned transactions have no after-commit hook here; never hint early.
            if ($tenantId < 1 || $db->transDepth !== 0 || !$db->transStatus()) {
                return;
            }
            $channel = Channel::loginCarousel($tenantId);
            if ($this->publisher !== null) {
                ($this->publisher)($channel, 'login.carousel.changed', []);
            } else {
                (new Publisher())->publish($channel, 'login.carousel.changed', []);
            }
        } catch (\Throwable) {
            // Invalidation is best-effort and must never turn a saved write into a failure.
        }
    }

    /**
     * Get slides for rendering on the login screen.
     * Falls back to Foundation University when no active slides are available.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSlidesForLogin(): array
    {
        $slides = $this->slideModel->getActiveSlides();
        if (!empty($slides)) {
            return $slides;
        }

        // Safe fallback list so login screen never breaks
        return [
            [
                'id'          => 1,
                'title'       => 'Foundation University',
                'subtitle'    => 'Health & Guidance Services Portal',
                'light_image' => 'assets/img/brand/fuel-bg.jpg',
                'dark_image'  => 'assets/img/brand/FoundationU-Dark.png',
                'fit_mode'    => 'cover',
            ],

        ];
    }

    /**
     * Create a new slide with optional uploaded files.
     *
     * @param array{
     *     title?: ?string,
     *     subtitle?: ?string,
     *     fit_mode: string,
     *     light_image?: ?string,
     *     dark_image?: ?string,
     * } $data
     * @param UploadedFile|null $lightFile
     * @param UploadedFile|null $darkFile
     * @return array<string, mixed>
     */
    public function createSlide(array $data, ?UploadedFile $lightFile = null, ?UploadedFile $darkFile = null): array
    {
        return $this->saveSlide(null, $data, $lightFile, $darkFile);
    }

    /**
     * Validate and persist both create and update operations before retiring files.
     *
     * @param array<string, mixed>|null $slide
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function saveSlide(?array $slide, array $data, ?UploadedFile $lightFile, ?UploadedFile $darkFile): array
    {
        $values = [
            'title'    => $this->validateText($data['title'] ?? null, 'Title', 150),
            'subtitle' => $this->validateText($data['subtitle'] ?? null, 'Subtitle', 255),
            'fit_mode' => $data['fit_mode'] ?? 'cover',
        ];
        if (!in_array($values['fit_mode'], ['cover', 'contain_blur'], true)) {
            throw new \InvalidArgumentException('Fit mode must be cover or contain_blur.');
        }

        $files = ['light_image' => $lightFile, 'dark_image' => $darkFile];
        $extensions = [];
        foreach ($files as $field => $file) {
            $extensions[$field] = $this->validateUpload($file);
            if ($extensions[$field] !== null) {
                continue;
            }

            $path = $field === 'light_image'
                ? ($data[$field] ?? $slide[$field] ?? '')
                : (array_key_exists($field, $data) ? $data[$field] : ($slide[$field] ?? null));
            if ($field === 'dark_image' && ($path === null || $path === '')) {
                $values[$field] = null;
            } else {
                $values[$field] = $this->validateImagePath($path);
            }
        }

        $newPaths = [];
        $persisted = false;
        try {
            foreach ($files as $field => $file) {
                if ($extensions[$field] === null) {
                    continue;
                }
                $variant = $field === 'light_image' ? 'light' : 'dark';
                do {
                    $name = 'slide_' . $variant . '_' . bin2hex(random_bytes(16)) . '.' . $extensions[$field];
                } while (file_exists($this->uploadPath . $name));
                $path = 'uploads/carousel/' . $name;
                // Track before moving so a partially failed move can also be cleaned up.
                $newPaths[] = $path;
                if (!$file->move($this->uploadPath, $name)) {
                    throw new \RuntimeException('Unable to move carousel image upload.');
                }
                $values[$field] = $path;
            }

            if ($slide === null) {
                $values['tenant_id'] = CurrentTenant::id();
                $values['sort_order'] = $this->slideModel->nextSortOrder();
                $values['is_active'] = 1;
                $id = $this->slideModel->insert($values);
                if ($id === false || (int) $id <= 0) {
                    throw new \RuntimeException('Unable to create carousel slide.');
                }
                $id = (int) $id;
            } else {
                $id = (int) $slide['id'];
                if (!$this->slideModel->scoped()->update($id, $values)) {
                    throw new \RuntimeException('Unable to update carousel slide.');
                }
            }
            $persisted = true;
        } finally {
            if (!$persisted) {
                foreach ($newPaths as $path) {
                    $this->cleanupUpload($path);
                }
            }
        }

        if ($slide !== null) {
            foreach (['light_image', 'dark_image'] as $field) {
                if (($slide[$field] ?? null) !== $values[$field]) {
                    $this->cleanupUpload($slide[$field] ?? null);
                }
            }
        }

        $saved = $this->slideModel->scoped()->find($id);
        if ($saved === null) {
            throw new \RuntimeException('Saved carousel slide could not be loaded.');
        }
        $this->publishChanged((int) $saved['tenant_id']);
        return $saved;
    }

    private function validateText(mixed $value, string $label, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($label . ' must be text.');
        }
        $value = trim($value);
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit) {
            throw new \InvalidArgumentException($label . ' must not exceed ' . $limit . ' characters of valid UTF-8 text.');
        }
        return $value === '' ? null : $value;
    }

    private function validateUpload(?UploadedFile $file): ?string
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file->getError() !== UPLOAD_ERR_OK || !$file->isValid() || $file->hasMoved()) {
            throw new \InvalidArgumentException('Carousel image upload failed or has already been moved.');
        }
        return $this->rasterExtension($file->getTempName());
    }

    private function rasterExtension(string $path): string
    {
        $extensions = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $image = @getimagesize($path);
        if (!isset($extensions[$mime]) || $image === false || ($image['mime'] ?? null) !== $mime) {
            throw new \InvalidArgumentException('Carousel images must be PNG, JPEG, WEBP, or GIF raster images.');
        }
        return $extensions[$mime];
    }

    private function validateImagePath(mixed $path): string
    {
        if (!is_string($path) || strlen($path) > 255
            || !preg_match('#\A(assets/img/brand|uploads/carousel)/([A-Za-z0-9_-][A-Za-z0-9._-]*(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)*)\z#D', $path, $matches)
        ) {
            throw new \InvalidArgumentException('A safe local carousel image path is required.');
        }
        $root = realpath(FCPATH . $matches[1]);
        $fullPath = realpath(FCPATH . $path);
        if ($root === false || $fullPath === false || !is_file($fullPath)
            || !str_starts_with($fullPath, $root . DIRECTORY_SEPARATOR)
            || ($matches[1] === 'uploads/carousel' && str_contains($matches[2], '/'))
        ) {
            throw new \InvalidArgumentException('Carousel image must exist inside its local image directory.');
        }
        $extension = $this->rasterExtension($fullPath);
        $pathExtension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($pathExtension !== $extension && !($extension === 'jpg' && $pathExtension === 'jpeg')) {
            throw new \InvalidArgumentException('Carousel image extension must match its raster MIME type.');
        }
        return $path;
    }

    private function cleanupUpload(mixed $path): void
    {
        if (!is_string($path)
            || !preg_match('#\Auploads/carousel/[A-Za-z0-9_-][A-Za-z0-9._-]*\.(?:png|jpe?g|webp|gif)\z#D', $path)
        ) {
            return;
        }
        $fullPath = FCPATH . $path;
        $root = realpath($this->uploadPath);
        $resolved = realpath($fullPath);
        if ($root === false || $resolved === false || is_link($fullPath) || !is_file($fullPath)
            || dirname($resolved) !== $root
        ) {
            return;
        }
        try {
            // References are deliberately checked across ALL tenants, not just the owner.
            $references = $this->slideModel->builder()
                ->resetQuery()
                ->groupStart()
                ->where('light_image', $path)
                ->orWhere('dark_image', $path)
                ->groupEnd()
                ->countAllResults();
            if ($references === 0) {
                @unlink($fullPath);
            }
        } catch (\Throwable $e) {
            // If reference safety cannot be established, retain the file.
        }
    }


    /**
     * Update a slide, optionally replacing its uploaded images.
     *
     * Existing files are only removed when they are replaced by a new upload
     * (or explicitly cleared) and only when they live in our own uploads dir.
     *
     * @param array<string, mixed> $data
     * @param UploadedFile|null $lightFile
     * @param UploadedFile|null $darkFile
     * @return array<string, mixed>|null The updated slide, or null if not found.
     */
    public function updateSlide(int $id, array $data, ?UploadedFile $lightFile = null, ?UploadedFile $darkFile = null): ?array
    {
        $slide = $this->slideModel->scoped()->find($id);
        if ($slide === null) {
            return null;
        }

        return $this->saveSlide($slide, $data, $lightFile, $darkFile);
    }

    /**
     * Delete a slide and clean up any uploaded files.
     */
    public function deleteSlide(int $id): bool
    {
        $slide = $this->slideModel->scoped()->find($id);
        if ($slide === null || !$this->slideModel->scoped()->delete($id)) {
            return false;
        }

        foreach (['light_image', 'dark_image'] as $field) {
            $this->cleanupUpload($slide[$field] ?? null);
        }

        $this->publishChanged((int) $slide['tenant_id']);
        return true;
    }

    /**
     * Toggle active status.
     */
    public function toggleActive(int $id): array
    {
        $slide = $this->slideModel->scoped()->find($id);
        if ($slide === null) {
            throw new \InvalidArgumentException("Slide ID {$id} not found.");
        }

        $nextStatus = ((int) $slide['is_active'] === 1) ? 0 : 1;
        if (!$this->slideModel->scoped()->update($id, ['is_active' => $nextStatus])) {
            throw new \RuntimeException('Unable to toggle carousel slide.');
        }

        $saved = $this->slideModel->scoped()->find($id);
        if ($saved === null) {
            throw new \RuntimeException('Saved carousel slide could not be loaded.');
        }
        $this->publishChanged((int) $saved['tenant_id']);
        return $saved;
    }

    /**
     * Reorder slides based on array of IDs in new sequence.
     *
     * @param list<int> $orderedIds
     */
    public function reorderSlides(array $orderedIds): void
    {
        $seq = 1;
        $tenantId = null;
        foreach ($orderedIds as $id) {
            $slide = $this->slideModel->scoped()->find((int) $id);
            if ($slide === null) {
                ++$seq;
                continue;
            }
            if (!$this->slideModel->scoped()->update((int) $id, ['sort_order' => $seq++])) {
                throw new \RuntimeException('Unable to reorder carousel slides.');
            }
            $tenantId = (int) $slide['tenant_id'];
        }
        if ($tenantId !== null) {
            $this->publishChanged($tenantId);
        }
    }
}
