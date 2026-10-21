<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateNotifications extends Migration
{
    private function sql(string $sql): void
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Notification migration failed.');
        }
    }

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Notifications require MySQL/MariaDB with InnoDB.');
        }
        foreach (['tenants', 'people', 'appointments'] as $table) {
            $row = $this->db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$this->db->prefixTable($table)])->getRowArray();
            if (strtoupper($row['ENGINE'] ?? '') !== 'INNODB') {
                throw new RuntimeException('Notifications require InnoDB for ' . $table . '.');
            }
        }
        $index = $this->db->query('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefixTable('appointments'), 'notification_appointment_tenant'])->getRowArray();
        if ($index === null) {
            $this->sql('ALTER TABLE {appointments} ADD UNIQUE KEY notification_appointment_tenant (tenant_id,id)');
        }
        $this->sql("CREATE TABLE IF NOT EXISTS {notifications} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            recipient_person_id BIGINT UNSIGNED NOT NULL,
            appointment_id BIGINT UNSIGNED NOT NULL,
            appointment_version INT UNSIGNED NOT NULL,
            type ENUM('appointment_confirmed') NOT NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY notification_confirmation (tenant_id,appointment_id,type,appointment_version),
            KEY notification_inbox (tenant_id,recipient_person_id,read_at,id),
            FOREIGN KEY (tenant_id) REFERENCES {tenants}(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            FOREIGN KEY (tenant_id,recipient_person_id) REFERENCES {people}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            FOREIGN KEY (tenant_id,appointment_id) REFERENCES {appointments}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB");
    }

    public function down(): void
    {
        $this->sql('DROP TABLE IF EXISTS {notifications}');
        $this->sql('ALTER TABLE {appointments} DROP INDEX notification_appointment_tenant');
    }
}
