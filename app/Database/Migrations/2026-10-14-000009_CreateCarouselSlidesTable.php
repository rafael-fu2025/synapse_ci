<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateCarouselSlidesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
            ],
            'subtitle' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'light_image' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
                'comment'    => 'Relative public path e.g. assets/img/brand/fuel-bg.jpg or uploads/carousel/...',
            ],
            'dark_image' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'comment'    => 'Optional relative public path for dark mode asset',
            ],
            'fit_mode' => [
                'type'       => 'ENUM',
                'constraint' => ['cover', 'contain_blur'],
                'null'       => false,
                'default'    => 'cover',
                'comment'    => 'cover: crisp full-bleed no blur; contain_blur: contained with blurred backdrop',
            ],
            'sort_order' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 0,
            ],
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'is_active', 'sort_order'], false, false, 'idx_slides_active_order');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('carousel_slides', true, ['ENGINE' => 'InnoDB']);

        // Seed default 3 slides with accurate fit_mode settings
        $now = date('Y-m-d H:i:s');
        $this->db->table('carousel_slides')->insertBatch([
            [
                'tenant_id'   => 1,
                'title'       => 'Foundation University',
                'subtitle'    => 'Health & Guidance Services Portal',
                'light_image' => 'assets/img/brand/fuel-bg.jpg',
                'dark_image'  => 'assets/img/brand/FoundationU-Dark.png',
                'fit_mode'    => 'cover', // Clean crisp full-bleed cover (NO BLUR)
                'sort_order'  => 1,
                'is_active'   => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'tenant_id'   => 1,
                'title'       => 'Guidance & Counseling Center',
                'subtitle'    => 'Confidential Support & Mental Wellness Services',
                'light_image' => 'assets/img/brand/guidance-light.png',
                'dark_image'  => 'assets/img/brand/guidance-dark.png',
                'fit_mode'    => 'contain_blur', // Contained with blurred background
                'sort_order'  => 2,
                'is_active'   => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'tenant_id'   => 1,
                'title'       => 'University Health Services',
                'subtitle'    => 'Accessible Campus Medical & Wellness Care',
                'light_image' => 'assets/img/brand/health-light.png',
                'dark_image'  => 'assets/img/brand/health-dark.png',
                'fit_mode'    => 'contain_blur', // Contained with blurred background
                'sort_order'  => 3,
                'is_active'   => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('carousel_slides', true);
    }
}
