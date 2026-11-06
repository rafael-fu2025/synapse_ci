<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Make the Foundation University brand artwork the permanent login carousel default.
 *
 * The light and dark images are addressed through the managed store, so the
 * slide that carries them is protected: it cannot be edited by anyone and only
 * the platform owner may delete or hide it.
 */
final class SetCarouselDefaultImage extends Migration
{
    /** Logical paths of the permanent default pair inside the managed store. */
    private const DEFAULT_LIGHT_ASSET = 'uploads/carousel/default/FoundationU-Light.jpg';
    private const DEFAULT_DARK_ASSET  = 'uploads/carousel/default/FoundationU-Dark.png';

    /** Default artwork that an earlier revision of this migration installed. */
    private const SUPERSEDED_LIGHT_ASSET = 'uploads/carousel/default/fu-social-garden.jpg';

    /** The seeded default slide and the artwork it originally carried. */
    private const SEEDED_TITLE       = 'Foundation University';
    private const SEEDED_LIGHT_IMAGE = 'assets/img/brand/fuel-bg.jpg';
    private const SEEDED_DARK_IMAGE  = 'assets/img/brand/FoundationU-Dark.png';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Already carrying the permanent default pair.
        if ($this->db->table('carousel_slides')
            ->where('light_image', self::DEFAULT_LIGHT_ASSET)
            ->countAllResults() > 0) {
            return;
        }

        // A database migrated by the earlier revision still carries the
        // superseded default; move that slide onto the pair in place.
        $superseded = $this->db->table('carousel_slides')
            ->groupStart()
            ->where('light_image', self::SUPERSEDED_LIGHT_ASSET)
            ->orWhere('dark_image', self::SUPERSEDED_LIGHT_ASSET)
            ->groupEnd()
            ->get()
            ->getRowArray();

        if ($superseded !== null) {
            $this->db->table('carousel_slides')->where('id', $superseded['id'])->update([
                'light_image' => self::DEFAULT_LIGHT_ASSET,
                'dark_image'  => self::DEFAULT_DARK_ASSET,
                'updated_at'  => $now,
            ]);

            return;
        }

        $seeded = $this->db->table('carousel_slides')
            ->where('title', self::SEEDED_TITLE)
            ->where('light_image', self::SEEDED_LIGHT_IMAGE)
            ->get()
            ->getRowArray();

        if ($seeded !== null) {
            $this->db->table('carousel_slides')->where('id', $seeded['id'])->update([
                'light_image' => self::DEFAULT_LIGHT_ASSET,
                'dark_image'  => self::DEFAULT_DARK_ASSET,
                'updated_at'  => $now,
            ]);

            return;
        }

        // Otherwise guarantee the permanent default slide is present.
        $this->db->table('carousel_slides')->insert([
            'tenant_id'   => 1,
            'title'       => self::SEEDED_TITLE,
            'subtitle'    => 'Health & Guidance Services Portal',
            'light_image' => self::DEFAULT_LIGHT_ASSET,
            'dark_image'  => self::DEFAULT_DARK_ASSET,
            'fit_mode'    => 'cover',
            'sort_order'  => 1,
            'is_active'   => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('carousel_slides')
            ->where('light_image', self::DEFAULT_LIGHT_ASSET)
            ->update([
                'light_image' => self::SEEDED_LIGHT_IMAGE,
                'dark_image'  => self::SEEDED_DARK_IMAGE,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
    }
}
