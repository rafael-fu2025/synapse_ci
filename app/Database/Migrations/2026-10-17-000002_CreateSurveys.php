<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateSurveys extends Migration
{
    private function sql(string $sql): void
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        if ($this->db->query($sql) === false) { throw new RuntimeException('Survey migration failed.'); }
    }

    private function indexes(string $table, array $expected): void
    {
        foreach ($expected as $name => [$columns, $nonUnique]) {
            $index = $this->db->query('SELECT COLUMN_NAME,NON_UNIQUE,SUB_PART FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
                [$this->db->prefixTable($table), $name])->getResultArray();
            if (array_column($index, 'COLUMN_NAME') !== $columns) {
                throw new RuntimeException('Incompatible survey index: ' . $table . '.' . $name);
            }
            foreach ($index as $part) {
                if ((int) $part['NON_UNIQUE'] !== $nonUnique || $part['SUB_PART'] !== null) {
                    throw new RuntimeException('Incompatible survey index: ' . $table . '.' . $name);
                }
            }
        }
    }

    /** A restarted DDL run may adopt only a complete, compatible table; never repair silently. */
    private function existing(string $table, array $columns, array $indexes, array $foreignKeys): void
    {
        // Query metadata directly: the connection's table-name cache can predate this run's DDL.
        $meta = $this->db->query('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->db->prefixTable($table)])->getRowArray();
        if ($meta === null) { return; }
        if (strtoupper($meta['ENGINE'] ?? '') !== 'INNODB' || ($meta['TABLE_COLLATION'] ?? '') !== 'utf8mb4_unicode_ci') {
            throw new RuntimeException('Incompatible survey table engine/collation: ' . $table);
        }
        $fields = $this->db->query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$this->db->prefixTable($table)])->getResultArray();
        $fields = array_column($fields, null, 'COLUMN_NAME');
        if ($table === 'surveys') {
            // A later additive migration owns these fields; validate them if already present.
            foreach (['target_year_levels', 'target_student_categories'] as $name) {
                if (isset($fields[$name])) { $columns[$name] = ['text', true, null]; }
            }
        }
        if (array_diff(array_keys($fields), array_keys($columns)) !== []) {
            throw new RuntimeException('Unexpected survey columns: ' . $table);
        }
        foreach ($columns as $name => [$type, $nullable, $default]) {
            $field = $fields[$name] ?? [];
            $actual = preg_replace('/\b(bigint|smallint|int)\([0-9]+\)/', '$1', strtolower($field['COLUMN_TYPE'] ?? ''));
            $actualDefault = $field['COLUMN_DEFAULT'] ?? null;
            // MariaDB quotes string defaults in information_schema; MySQL does not.
            if (is_string($actualDefault) && strlen($actualDefault) >= 2 && $actualDefault[0] === "'"
                && substr($actualDefault, -1) === "'") { $actualDefault = substr($actualDefault, 1, -1); }
            if ($actualDefault === 'NULL') { $actualDefault = null; }
            if ($actual !== $type || ($field['IS_NULLABLE'] ?? '') !== ($nullable ? 'YES' : 'NO')
                || $actualDefault !== $default || ($field['EXTRA'] ?? '') !== ($name === 'id' ? 'auto_increment' : '')) {
                throw new RuntimeException('Incompatible survey column: ' . $table . '.' . $name);
            }
        }
        $this->indexes($table, $indexes);
        foreach ($foreignKeys as $name => [$local, $parent, $remote]) {
            $key = $this->db->query('SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,
                k.REFERENCED_TABLE_SCHEMA,r.DELETE_RULE,r.UPDATE_RULE
                FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
                WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.CONSTRAINT_NAME=? ORDER BY k.ORDINAL_POSITION',
                [$this->db->prefixTable($table), $name])->getResultArray();
            if (array_column($key, 'COLUMN_NAME') !== $local || array_column($key, 'REFERENCED_COLUMN_NAME') !== $remote) {
                throw new RuntimeException('Incompatible survey foreign key: ' . $table . '.' . $name);
            }
            $schema = $this->db->query('SELECT DATABASE() AS name')->getRow('name');
            foreach ($key as $part) {
                if ($part['REFERENCED_TABLE_NAME'] !== $this->db->prefixTable($parent)
                    || $part['REFERENCED_TABLE_SCHEMA'] !== $schema || $part['DELETE_RULE'] !== 'RESTRICT' || $part['UPDATE_RULE'] !== 'RESTRICT') {
                    throw new RuntimeException('Incompatible survey foreign key: ' . $table . '.' . $name);
                }
            }
        }
    }

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') { throw new RuntimeException('Surveys require MySQL/MariaDB with InnoDB.'); }
        foreach (['tenants', 'users', 'people'] as $table) {
            $row = $this->db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                [$this->db->prefixTable($table)])->getRowArray();
            if (strtoupper($row['ENGINE'] ?? '') !== 'INNODB') { throw new RuntimeException('Surveys require InnoDB: ' . $table); }
        }
        $surveyColumns = [
            'id' => ['bigint unsigned', false, null], 'tenant_id' => ['int unsigned', false, null],
            'title' => ['varchar(200)', false, null], 'description' => ['text', false, null],
            'audience' => ["enum('all','students','employees')", false, null],
            'status' => ["enum('draft','published','closed')", false, 'draft'],
            'questions_json' => ['mediumtext', false, null], 'version' => ['int unsigned', false, '1'],
            'starts_at' => ['datetime', true, null], 'ends_at' => ['datetime', true, null],
            'published_at' => ['datetime', true, null], 'closed_at' => ['datetime', true, null],
            'created_by' => ['bigint unsigned', false, null], 'created_at' => ['datetime', false, null],
            'updated_at' => ['datetime', false, null],
        ];
        $surveyIndexes = ['PRIMARY' => [['id'], 0], 'survey_tenant_id' => [['tenant_id', 'id'], 0],
            'survey_listing' => [['tenant_id', 'status', 'id'], 1]];
        $surveyKeys = ['survey_tenant_fk' => [['tenant_id'], 'tenants', ['id']],
            'survey_creator_fk' => [['tenant_id', 'created_by'], 'users', ['tenant_id', 'id']]];
        $responseColumns = [
            'id' => ['bigint unsigned', false, null], 'tenant_id' => ['int unsigned', false, null],
            'survey_id' => ['bigint unsigned', false, null], 'person_id' => ['bigint unsigned', false, null],
            'submitted_by' => ['bigint unsigned', false, null], 'payload_cipher' => ['mediumblob', false, null],
            'payload_nonce' => ['binary(12)', false, null], 'payload_tag' => ['binary(16)', false, null],
            'payload_key_version' => ['smallint unsigned', false, null], 'submitted_at' => ['datetime', false, null],
        ];
        $responseIndexes = ['PRIMARY' => [['id'], 0], 'survey_person_once' => [['tenant_id', 'survey_id', 'person_id'], 0],
            'survey_response_listing' => [['tenant_id', 'survey_id', 'id'], 1]];
        $responseKeys = ['survey_response_survey_fk' => [['tenant_id', 'survey_id'], 'surveys', ['tenant_id', 'id']],
            'survey_response_person_fk' => [['tenant_id', 'person_id'], 'people', ['tenant_id', 'id']],
            'survey_response_submitter_fk' => [['tenant_id', 'submitted_by'], 'users', ['tenant_id', 'id']]];
        $this->existing('surveys', $surveyColumns, $surveyIndexes, $surveyKeys);
        $this->existing('survey_responses', $responseColumns, $responseIndexes, $responseKeys);
        foreach (['users', 'people'] as $table) {
            $name = 'survey_' . $table . '_tenant';
            $exists = $this->db->query('SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', [$this->db->prefixTable($table), $name])->getRowArray();
            if ($exists === null) { $this->sql('ALTER TABLE {' . $table . '} ADD UNIQUE KEY ' . $name . ' (tenant_id,id)'); }
            $this->indexes($table, [$name => [['tenant_id', 'id'], 0]]);
        }
        $this->sql("CREATE TABLE IF NOT EXISTS {surveys} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NOT NULL,
            audience ENUM('all','students','employees') NOT NULL,
            status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
            questions_json MEDIUMTEXT NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            published_at DATETIME NULL,
            closed_at DATETIME NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY survey_tenant_id (tenant_id,id),
            KEY survey_listing (tenant_id,status,id),
            CONSTRAINT survey_tenant_fk FOREIGN KEY (tenant_id) REFERENCES {tenants}(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT survey_creator_fk FOREIGN KEY (tenant_id,created_by) REFERENCES {users}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->sql("CREATE TABLE IF NOT EXISTS {survey_responses} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            survey_id BIGINT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            submitted_by BIGINT UNSIGNED NOT NULL,
            payload_cipher MEDIUMBLOB NOT NULL,
            payload_nonce BINARY(12) NOT NULL,
            payload_tag BINARY(16) NOT NULL,
            payload_key_version SMALLINT UNSIGNED NOT NULL,
            submitted_at DATETIME NOT NULL,
            UNIQUE KEY survey_person_once (tenant_id,survey_id,person_id),
            KEY survey_response_listing (tenant_id,survey_id,id),
            CONSTRAINT survey_response_survey_fk FOREIGN KEY (tenant_id,survey_id) REFERENCES {surveys}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT survey_response_person_fk FOREIGN KEY (tenant_id,person_id) REFERENCES {people}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT survey_response_submitter_fk FOREIGN KEY (tenant_id,submitted_by) REFERENCES {users}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->existing('surveys', $surveyColumns, $surveyIndexes, $surveyKeys);
        $this->existing('survey_responses', $responseColumns, $responseIndexes, $responseKeys);
    }

    public function down(): void
    {
        throw new RuntimeException('Survey migrations are forward-only; surveys and identified response history may never be dropped.');
    }
}
