<?php

declare(strict_types=1);

namespace App\Models;

final class CarouselSlideModel extends TenantModel
{
    protected $table = 'carousel_slides';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'title',
        'subtitle',
        'light_image',
        'dark_image',
        'fit_mode',
        'sort_order',
        'is_active',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Get active slides ordered by sequence for display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveSlides(): array
    {
        return $this->scoped()
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * Get all slides (active and inactive) for admin management.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllSlides(): array
    {
        return $this->scoped()
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * Next sort order sequence.
     */
    public function nextSortOrder(): int
    {
        $last = $this->scoped()->orderBy('sort_order', 'DESC')->first();
        return $last ? ((int) $last['sort_order'] + 1) : 1;
    }
}
