<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Mobile API bearer credentials. Only SHA-256 hashes are stored; rotation lineage is the family_id. */
final class CreateApiTokens extends Migration
{
    private const COLUMNS = [
        'id' => ['bigint unsigned', false, null, 'auto_increment'],
        'tenant_id' => ['int unsigned', false, null, ''],
        'user_id' => ['int unsigned', false, null, ''],
        'auth_version' => ['int unsigned', false, '1', ''],
        'token_hash' => ['char(64)', false, null, ''],
        'refresh_hash' => ['char(64)', false, null, ''],
        'family_id' => ['char(32)', false, null, ''],
        'device_label' => ['varchar(120)', true, null, ''],
        'created_ip' => ['varchar(45)', true, null, ''],
        'last_used_at' => ['datetime', true, null, ''],
        'access_expires_at' => ['datetime', false, null, ''],
        'refresh_expires_at' => ['datetime', false, null, ''],
        'rotated_at' => ['datetime', true, null, ''],
        'revoked_at' => ['datetime', true, null, ''],
        'created_at' => ['datetime', false, null, ''],
        'updated_at' => ['datetime', true, null, ''],
    ];

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('API tokens require InnoDB.');
        }
        $exists = $this->sql('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$this->db->prefixTable('api_tokens')])->getRowArray() !== null;
        if (!$exists) {
            $this->sql('CREATE TABLE {api_tokens} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                auth_version INT UNSIGNED NOT NULL DEFAULT 1,
                token_hash CHAR(64) NOT NULL,
                refresh_hash CHAR(64) NOT NULL,
                family_id CHAR(32) NOT NULL,
                device_label VARCHAR(120) NULL,
                created_ip VARCHAR(45) NULL,
                last_used_at DATETIME NULL,
                access_expires_at DATETIME NOT NULL,
                refresh_expires_at DATETIME NOT NULL,
                rotated_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY api_tokens_token_hash (token_hash),
                UNIQUE KEY api_tokens_refresh_hash (refresh_hash),
                KEY api_tokens_user (tenant_id, user_id),
                KEY api_tokens_family (family_id),
                FOREIGN KEY (tenant_id) REFERENCES {tenants}(id) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        $this->validate();
    }

    public function down(): void
    {
        // Rolling back removes every mobile credential; that is the intent of removal.
        $this->sql('DROP TABLE IF EXISTS {api_tokens}');
    }

    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($match) => $this->db->protectIdentifiers($this->db->prefixTable($match[1])), $sql);
        $result = $this->db->query($sql, $binds);
        if ($result === false) {
            throw new RuntimeException('API token migration failed.');
        }
        return $result;
    }

    private function validate(): void
    {
        $table = $this->db->prefixTable('api_tokens');
        $columns = $this->sql('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->getResultArray();
        $columns = array_column($columns, null, 'COLUMN_NAME');
        foreach (self::COLUMNS as $name => [$type, $nullable, $default, $extra]) {
            $column = $columns[$name] ?? [];
            // MariaDB reports integer display widths; char/varchar widths must survive.
            $actual = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower($column['COLUMN_TYPE'] ?? ''));
            $actualDefault = $column['COLUMN_DEFAULT'] ?? null;
            if ($actualDefault === 'NULL') {
                $actualDefault = null; // MariaDB represents nullable defaults as SQL text.
            }
            if ($actual !== $type || ($column['IS_NULLABLE'] ?? '') !== ($nullable ? 'YES' : 'NO')
                || $actualDefault !== $default || strtolower($column['EXTRA'] ?? '') !== $extra) {
                throw new RuntimeException('Incompatible API token column: ' . $name);
            }
        }
        $indexes = $this->sql('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SUB_PART,INDEX_TYPE
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table])->getResultArray();
        $grouped = [];
        foreach ($indexes as $index) {
            $grouped[$index['INDEX_NAME']][] = $index;
        }
        $expected = [
            'PRIMARY' => ['id'],
            'api_tokens_token_hash' => ['token_hash'],
            'api_tokens_refresh_hash' => ['refresh_hash'],
            'api_tokens_user' => ['tenant_id', 'user_id'],
            'api_tokens_family' => ['family_id'],
        ];
        foreach ($expected as $name => $fields) {
            $rows = $grouped[$name] ?? [];
            if (array_column($rows, 'COLUMN_NAME') !== $fields) {
                throw new RuntimeException('Incompatible API token index: ' . $name);
            }
        }
        $uniqueExpected = ['PRIMARY', 'api_tokens_token_hash', 'api_tokens_refresh_hash'];
        foreach ($grouped as $name => $rows) {
            if (((int) $rows[0]['NON_UNIQUE'] === 0) !== in_array($name, $uniqueExpected, true)) {
                throw new RuntimeException('Unexpected API token index uniqueness: ' . $name);
            }
        }
    }
}
