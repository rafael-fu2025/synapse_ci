<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Align mobile credential owners with users.id and add tenant-scoped referential integrity. */
final class AlignMobileTokenUserReferences extends Migration
{
    private const TABLES = [
        'api_tokens' => ['api_tokens_user_owner_fk', 'api_tokens_user'],
        'push_tokens' => ['push_tokens_user_owner_fk', 'push_tokens_user'],
    ];

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Mobile token owner alignment requires MySQL/MariaDB with InnoDB.');
        }
        foreach (['users', ...array_keys(self::TABLES)] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Mobile token owner preflight STOP: missing table ' . $table . '; no DDL performed.');
            }
            $engine = $this->sql('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                [$this->db->prefixTable($table)])->getRowArray();
            if (strtoupper((string) ($engine['ENGINE'] ?? '')) !== 'INNODB') {
                throw new RuntimeException('Mobile token owner preflight STOP: InnoDB required for ' . $table . '; no DDL performed.');
            }
        }

        $this->assertColumn('users', 'id', 'bigint unsigned', false);
        $this->assertColumn('users', 'tenant_id', 'int unsigned', false);
        foreach (array_keys(self::TABLES) as $table) {
            $this->assertColumn($table, 'tenant_id', 'int unsigned', false);
            $column = $this->column($table, 'user_id');
            if ($column === null || !in_array($this->type($column), ['int unsigned', 'bigint unsigned'], true)
                || ($column['IS_NULLABLE'] ?? '') !== 'NO') {
                throw new RuntimeException('Mobile token owner preflight STOP: incompatible ' . $table . '.user_id; no DDL performed.');
            }
            $orphan = $this->sql('SELECT t.id FROM {' . $table . '} t LEFT JOIN {users} u ON u.tenant_id=t.tenant_id AND u.id=t.user_id WHERE u.id IS NULL LIMIT 1')->getRowArray();
            if ($orphan !== null) {
                throw new RuntimeException('Mobile token owner preflight STOP: ' . $table . ' contains an invalid tenant/user owner; repair explicitly before rerunning; no DDL performed.');
            }
        }

        if (!$this->index('users', ['tenant_id', 'id'], true)) {
            $this->sql('ALTER TABLE {users} ADD UNIQUE KEY mobile_token_user_scope (tenant_id,id)');
        }
        foreach (self::TABLES as $table => [$constraint, $index]) {
            $scopedConstraint = $this->constraint($constraint);
            if ($this->type($this->column($table, 'user_id') ?? []) !== 'bigint unsigned') {
                $this->sql('ALTER TABLE {' . $table . '} MODIFY user_id BIGINT UNSIGNED NOT NULL');
            }
            if (!$this->index($table, ['tenant_id', 'user_id'], false)) {
                $this->sql('ALTER TABLE {' . $table . '} ADD KEY ' . $index . ' (tenant_id,user_id)');
            }
            if (!$this->foreignKey($table, $scopedConstraint, ['tenant_id', 'user_id'], 'users', ['tenant_id', 'id'])) {
                if ($this->foreignKeyNamed($table, $scopedConstraint)) {
                    throw new RuntimeException('Mobile token owner migration found an incompatible constraint: ' . $scopedConstraint);
                }
                $this->sql('ALTER TABLE {' . $table . '} ADD CONSTRAINT ' . $scopedConstraint
                    . ' FOREIGN KEY (tenant_id,user_id) REFERENCES {users}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT');
            }
        }
        $this->validate();
    }

    public function down(): void
    {
        throw new RuntimeException('Mobile token owner alignment is forward-only; narrowing identifiers or removing integrity would be unsafe.');
    }

    private function validate(): void
    {
        foreach (self::TABLES as $table => [$constraint]) {
            $constraint = $this->constraint($constraint);
            $this->assertColumn($table, 'user_id', 'bigint unsigned', false);
            if (!$this->foreignKey($table, $constraint, ['tenant_id', 'user_id'], 'users', ['tenant_id', 'id'])) {
                throw new RuntimeException('Mobile token owner migration validation failed: ' . $table);
            }
        }
    }

    private function assertColumn(string $table, string $column, string $type, bool $nullable): void
    {
        $actual = $this->column($table, $column);
        if ($actual === null || $this->type($actual) !== $type
            || ($actual['IS_NULLABLE'] ?? '') !== ($nullable ? 'YES' : 'NO')) {
            throw new RuntimeException('Mobile token owner preflight STOP: incompatible ' . $table . '.' . $column . '; no DDL performed.');
        }
    }

    private function column(string $table, string $column): ?array
    {
        return $this->sql('SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$this->db->prefixTable($table), $column])->getRowArray();
    }

    private function type(array $column): string
    {
        return preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower((string) ($column['COLUMN_TYPE'] ?? '')));
    }

    private function tableExists(string $table): bool
    {
        return $this->sql('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$this->db->prefixTable($table)])->getRowArray() !== null;
    }

    private function index(string $table, array $columns, bool $unique): bool
    {
        $rows = $this->sql('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',
            [$this->db->prefixTable($table)])->getResultArray();
        $grouped = [];
        foreach ($rows as $row) $grouped[$row['INDEX_NAME']][] = $row;
        foreach ($grouped as $index) {
            $actual = array_column($index, 'COLUMN_NAME');
            if (array_slice($actual, 0, count($columns)) === $columns
                && ((int) $index[0]['NON_UNIQUE'] === 0) === $unique) return true;
        }
        return false;
    }

    private function constraint(string $name): string
    {
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $this->db->DBPrefix);
        $candidate = $prefix . $name;
        return strlen($candidate) <= 64 ? $candidate : substr($prefix, 0, 16) . substr(hash('sha256', $prefix), 0, 8) . '_' . $name;
    }

    private function foreignKeyNamed(string $table, string $name): bool
    {
        return $this->sql('SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?',
            [$this->db->prefixTable($table), $name])->getRowArray() !== null;
    }

    private function foreignKey(string $table, string $name, array $columns, string $parent, array $parentColumns): bool
    {
        $rows = $this->sql('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? ORDER BY ORDINAL_POSITION',
            [$this->db->prefixTable($table), $name])->getResultArray();
        return array_column($rows, 'COLUMN_NAME') === $columns
            && array_column($rows, 'REFERENCED_COLUMN_NAME') === $parentColumns
            && array_unique(array_column($rows, 'REFERENCED_TABLE_NAME')) === [$this->db->prefixTable($parent)];
    }

    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn (array $match): string => $this->db->protectIdentifiers($this->db->prefixTable($match[1])), $sql);
        $result = $this->db->query($sql, $binds);
        if ($result === false) throw new RuntimeException('Mobile token owner migration failed.');
        return $result;
    }
}
