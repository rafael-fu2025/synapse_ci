<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Device tokens for mobile push delivery.
 *
 * Unlike the API bearer tokens, a device token cannot be hashed: the delivery
 * service needs the routable address itself. It is therefore never treated as a
 * credential, never returned by an endpoint, and never logged.
 */
final class CreatePushTokens extends Migration
{
    private const COLUMNS = [
        'id' => ['bigint unsigned', false, null, 'auto_increment'],
        'tenant_id' => ['int unsigned', false, null, ''],
        'user_id' => ['int unsigned', false, null, ''],
        'token' => ['varchar(512)', false, null, ''],
        'platform' => ['varchar(16)', false, null, ''],
        'device_label' => ['varchar(120)', true, null, ''],
        'created_ip' => ['varchar(45)', true, null, ''],
        'last_used_at' => ['datetime', true, null, ''],
        'revoked_at' => ['datetime', true, null, ''],
        'created_at' => ['datetime', false, null, ''],
        'updated_at' => ['datetime', true, null, ''],
    ];

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Push tokens require InnoDB.');
        }
        $exists = $this->sql('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$this->db->prefixTable('push_tokens')])->getRowArray() !== null;
        if (!$exists) {
            $this->sql('CREATE TABLE {push_tokens} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                token VARCHAR(512) NOT NULL,
                platform VARCHAR(16) NOT NULL,
                device_label VARCHAR(120) NULL,
                created_ip VARCHAR(45) NULL,
                last_used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY push_tokens_token (token),
                KEY push_tokens_user (tenant_id, user_id, revoked_at),
                FOREIGN KEY (tenant_id) REFERENCES {tenants}(id) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        $this->validate();
    }

    public function down(): void
    {
        // Rolling back removes every registered device; that is the intent of removal.
        $this->sql('DROP TABLE IF EXISTS {push_tokens}');
    }

    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($match) => $this->db->protectIdentifiers($this->db->prefixTable($match[1])), $sql);
        $result = $this->db->query($sql, $binds);
        if ($result === false) {
            throw new RuntimeException('Push token migration failed.');
        }
        return $result;
    }

    private function validate(): void
    {
        $table = $this->db->prefixTable('push_tokens');
        $columns = $this->sql('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->getResultArray();
        $columns = array_column($columns, null, 'COLUMN_NAME');
        foreach (self::COLUMNS as $name => [$type, $nullable, $default, $extra]) {
            $column = $columns[$name] ?? [];
            $actual = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower($column['COLUMN_TYPE'] ?? ''));
            $actualDefault = $column['COLUMN_DEFAULT'] ?? null;
            if ($actualDefault === 'NULL') {
                $actualDefault = null;
            }
            if ($actual !== $type || ($column['IS_NULLABLE'] ?? '') !== ($nullable ? 'YES' : 'NO')
                || $actualDefault !== $default || strtolower($column['EXTRA'] ?? '') !== $extra) {
                throw new RuntimeException('Incompatible push token column: ' . $name);
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
            'push_tokens_token' => ['token'],
            'push_tokens_user' => ['tenant_id', 'user_id', 'revoked_at'],
        ];
        foreach ($expected as $name => $fields) {
            $rows = $grouped[$name] ?? [];
            if (array_column($rows, 'COLUMN_NAME') !== $fields) {
                throw new RuntimeException('Incompatible push token index: ' . $name);
            }
        }
    }
}
