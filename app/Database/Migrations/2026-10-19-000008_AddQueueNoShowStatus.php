<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddQueueNoShowStatus extends Migration
{
    public function up()
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new \RuntimeException('Queue no-show status requires MySQL/MariaDB with InnoDB.');
        }
        $table = $this->db->prefixTable('queue_entries');
        $metadata = $this->db->query('SELECT ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->getRowArray();
        if ($metadata === null || strtoupper($metadata['ENGINE'] ?? '') !== 'INNODB' || $metadata['TABLE_TYPE'] !== 'BASE TABLE') {
            throw new \RuntimeException('Queue no-show status requires an existing InnoDB queue_entries table.');
        }
        $columns = $this->db->query('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'status'])->getRowArray();
        $expected = ["enum('waiting','called','in_consultation','done','skipped')", "enum('waiting','called','in_consultation','done','skipped','no_show')"];
        $type = strtolower($columns['COLUMN_TYPE'] ?? '');
        if ($columns === null || $columns['IS_NULLABLE'] !== 'NO' || !in_array($type, $expected, true)
            || !in_array((string) $columns['COLUMN_DEFAULT'], ['waiting', "'waiting'"], true)) {
            throw new \RuntimeException('Unexpected queue_entries.status definition; refusing implicit status conversion.');
        }
        if ($type === $expected[0] && !$this->db->query("ALTER TABLE `{$table}` MODIFY status ENUM('waiting','called','in_consultation','done','skipped','no_show') NOT NULL DEFAULT 'waiting'")) {
            throw new \RuntimeException('Unable to add the queue no-show state; keep queue writers stopped.');
        }
        $verified = $this->db->query('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'status'])->getRowArray();
        if (strtolower($verified['COLUMN_TYPE'] ?? '') !== $expected[1] || $verified['IS_NULLABLE'] !== 'NO'
            || !in_array((string) $verified['COLUMN_DEFAULT'], ['waiting', "'waiting'"], true)) {
            throw new \RuntimeException('Queue no-show status installation was not verified; keep queue writers stopped.');
        }
    }

    public function down()
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new \RuntimeException('Queue no-show status requires MySQL/MariaDB with InnoDB.');
        }
        $table = $this->db->prefixTable('queue_entries');
        $columns = $this->db->query('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'status'])->getRowArray();
        if (strtolower($columns['COLUMN_TYPE'] ?? '') !== "enum('waiting','called','in_consultation','done','skipped','no_show')"
            || $columns['IS_NULLABLE'] !== 'NO' || !in_array((string) $columns['COLUMN_DEFAULT'], ['waiting', "'waiting'"], true)) {
            throw new \RuntimeException('Unexpected queue_entries.status definition; refusing to replace it.');
        }
        // Never coerce a documented absence into completed care or a recallable skip.
        if ($this->db->table('queue_entries')->where('status', 'no_show')->countAllResults() > 0) {
            throw new \RuntimeException('Cannot remove the no-show state while no-show ticket history exists.');
        }
        if (!$this->db->query("ALTER TABLE `{$table}` MODIFY status ENUM('waiting','called','in_consultation','done','skipped') NOT NULL DEFAULT 'waiting'")) {
            throw new \RuntimeException('Unable to remove the queue no-show state; keep queue writers stopped.');
        }
    }
}
