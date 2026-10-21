<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateAnnouncements extends Migration
{
    private function sql(string $sql): void
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        if ($this->db->query($sql) === false) { throw new RuntimeException('Announcement migration failed.'); }
    }

    /** A restart may adopt our completed tables, never an incompatible pre-existing schema. */
    private function existing(string $table, array $columns, array $unique): void
    {
        if (!$this->db->tableExists($table)) { return; }
        $engine = $this->db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$this->db->prefixTable($table)])->getRowArray();
        if (strtoupper($engine['ENGINE'] ?? '') !== 'INNODB') { throw new RuntimeException('Announcements require InnoDB: ' . $table); }
        $fields = $this->db->query('SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->db->prefixTable($table)])->getResultArray();
        $fields = array_column($fields, 'COLUMN_TYPE', 'COLUMN_NAME');
        foreach ($columns as $name => $type) {
            $actual = preg_replace('/\b(bigint|int)\([0-9]+\)/', '$1', strtolower($fields[$name] ?? ''));
            if ($actual !== $type) { throw new RuntimeException('Incompatible announcement column: ' . $table . '.' . $name); }
        }
        foreach ($unique as $name => $expected) {
            $index = $this->db->query('SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
                [$this->db->prefixTable($table), $name])->getResultArray();
            if (array_column($index, 'COLUMN_NAME') !== $expected || (int) ($index[0]['NON_UNIQUE'] ?? 1) !== 0) {
                throw new RuntimeException('Incompatible announcement unique index: ' . $table . '.' . $name);
            }
        }
    }

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') { throw new RuntimeException('Announcements require MySQL/MariaDB with InnoDB.'); }
        foreach (['tenants', 'users', 'people'] as $table) {
            $row = $this->db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                [$this->db->prefixTable($table)])->getRowArray();
            if (strtoupper($row['ENGINE'] ?? '') !== 'INNODB') { throw new RuntimeException('Announcements require InnoDB: ' . $table); }
        }
        $this->existing('announcements', [
            'id' => 'bigint unsigned', 'tenant_id' => 'int unsigned', 'unit' => "enum('clinic','guidance')",
            'title' => 'varchar(160)', 'body' => 'text', 'audience' => "enum('all','students','employees')",
            'status' => "enum('draft','published','archived')", 'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'published_at' => 'datetime', 'created_by' => 'bigint unsigned', 'created_at' => 'datetime',
            'updated_at' => 'datetime', 'version' => 'int unsigned',
        ], ['PRIMARY' => ['id'], 'announcement_tenant' => ['tenant_id', 'id']]);
        $this->existing('announcements_reads', [
            'id' => 'bigint unsigned', 'tenant_id' => 'int unsigned', 'announcement_id' => 'bigint unsigned',
            'person_id' => 'bigint unsigned', 'read_at' => 'datetime',
        ], ['PRIMARY' => ['id'], 'announcement_person_read' => ['tenant_id', 'announcement_id', 'person_id']]);
        // Dedicated additive indexes make this independent of notification migration ordering.
        foreach (['users', 'people'] as $table) {
            $name = 'announcement_' . $table . '_tenant';
            $index = $this->db->query('SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
                [$this->db->prefixTable($table), $name])->getResultArray();
            if ($index === []) {
                $this->sql('ALTER TABLE {' . $table . '} ADD UNIQUE KEY ' . $name . ' (tenant_id,id)');
            } elseif (array_column($index, 'COLUMN_NAME') !== ['tenant_id', 'id'] || (int) $index[0]['NON_UNIQUE'] !== 0) {
                throw new RuntimeException('Incompatible announcement parent index: ' . $name);
            }
        }
        $this->sql("CREATE TABLE IF NOT EXISTS {announcements} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            unit ENUM('clinic','guidance') NOT NULL,
            title VARCHAR(160) NOT NULL,
            body TEXT NOT NULL,
            audience ENUM('all','students','employees') NOT NULL,
            status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            published_at DATETIME NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            UNIQUE KEY announcement_tenant (tenant_id,id),
            KEY announcement_staff_page (tenant_id,unit,id),
            KEY announcement_portal_page (tenant_id,status,id),
            CONSTRAINT announcement_tenant_fk FOREIGN KEY (tenant_id) REFERENCES {tenants}(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT announcement_creator_fk FOREIGN KEY (tenant_id,created_by) REFERENCES {users}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->sql("CREATE TABLE IF NOT EXISTS {announcements_reads} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            announcement_id BIGINT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            read_at DATETIME NOT NULL,
            UNIQUE KEY announcement_person_read (tenant_id,announcement_id,person_id),
            KEY announcement_person_inbox (tenant_id,person_id,announcement_id),
            CONSTRAINT announcement_read_notice_fk FOREIGN KEY (tenant_id,announcement_id) REFERENCES {announcements}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT announcement_read_person_fk FOREIGN KEY (tenant_id,person_id) REFERENCES {people}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        throw new RuntimeException('Announcement migrations are forward-only; no announcements or read history may be dropped.');
    }
}
