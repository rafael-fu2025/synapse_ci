<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Pre-authentication counters only: no credential or identity columns. */
final class CreateLoginAttemptBuckets extends Migration
{
    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Login attempt limits require MySQL/MariaDB with InnoDB.');
        }
        $table = $this->db->protectIdentifiers($this->db->prefixTable('login_attempt_buckets'));
        $existing = $this->db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable('login_attempt_buckets')])->getRowArray();
        if ($existing === null) {
            if (!$this->db->query("CREATE TABLE {$table} (
                bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                expires_at BIGINT UNSIGNED NOT NULL,
                KEY idx_login_throttle_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin")) {
                throw new RuntimeException('Unable to create login attempt counters.');
            }
        }
        $engine = $this->db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable('login_attempt_buckets')])->getRowArray();
        $fields = $this->db->query("SHOW COLUMNS FROM {$table}")->getResultArray();
        $fields = array_column($fields, null, 'Field');
        if (strtoupper($engine['ENGINE'] ?? '') !== 'INNODB' || count($fields) !== 3
            || !preg_match('/^char\(64\)$/i', $fields['bucket_hash']['Type'] ?? '')
            || ($fields['bucket_hash']['Null'] ?? '') !== 'NO' || ($fields['bucket_hash']['Key'] ?? '') !== 'PRI'
            || !preg_match('/^int(?:\([0-9]+\))? unsigned$/i', $fields['attempts']['Type'] ?? '')
            || ($fields['attempts']['Null'] ?? '') !== 'NO' || (string) ($fields['attempts']['Default'] ?? '') !== '0'
            || !preg_match('/^bigint(?:\([0-9]+\))? unsigned$/i', $fields['expires_at']['Type'] ?? '')
            || ($fields['expires_at']['Null'] ?? '') !== 'NO') {
            throw new RuntimeException('Unexpected login counter schema; refusing automatic replacement.');
        }
        $indexes = $this->db->query('SHOW INDEX FROM ' . $table)->getResultArray();
        $primary = array_values(array_filter($indexes, static fn (array $row): bool => $row['Key_name'] === 'PRIMARY'));
        $expiry = array_values(array_filter($indexes, static fn (array $row): bool => $row['Key_name'] === 'idx_login_throttle_expiry'));
        if (count($primary) !== 1 || $primary[0]['Column_name'] !== 'bucket_hash'
            || $primary[0]['Sub_part'] !== null || (int) $primary[0]['Non_unique'] !== 0
            || count($expiry) !== 1 || $expiry[0]['Column_name'] !== 'expires_at'
            || $expiry[0]['Sub_part'] !== null || (int) $expiry[0]['Non_unique'] !== 1) {
            throw new RuntimeException('Unexpected login counter indexes; refusing automatic replacement.');
        }
    }

    public function down(): void
    {
        // Stop login traffic first. This deliberately removes all temporary limits.
        $this->forge->dropTable('login_attempt_buckets', true);
    }
}
