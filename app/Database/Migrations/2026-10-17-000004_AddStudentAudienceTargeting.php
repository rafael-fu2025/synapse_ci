<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Unknown profiles and existing publications remain unrestricted; no historical inference. */
final class AddStudentAudienceTargeting extends Migration
{
    // Target TEXT holds JSON arrays: years 1–6; new/continuing/transferee/graduating.
    // NULL means unrestricted content, or unknown profile status; no inferred defaults.
    public static function columns(): array
    {
        return ['student_profiles' => ['enrollment_type' => "enum('new','continuing','transferee')", 'is_graduating' => 'tinyint unsigned'],
            'announcements' => ['target_year_levels' => 'text', 'target_student_categories' => 'text'],
            'surveys' => ['target_year_levels' => 'text', 'target_student_categories' => 'text']];
    }

    private function sql(string $sql, array $binds = [])
    {
        $result = $this->db->query($sql, $binds);
        if ($result === false) { throw new RuntimeException('Audience schema operation failed.'); }
        return $result;
    }

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') { throw new RuntimeException('Audience targeting requires MySQL/MariaDB.'); }
        // Validate every existing definition before any DDL, including a partially completed attempt.
        $missing = [];
        foreach (self::columns() as $table => $columns) {
            $name = $this->db->prefixTable($table);
            $engine = $this->sql('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$name])->getRow('ENGINE');
            if (strtoupper($engine ?? '') !== 'INNODB') { throw new RuntimeException('Missing InnoDB audience prerequisite: ' . $table); }
            foreach ($columns as $column => $type) {
                $meta = $this->sql('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$name, $column])->getRowArray();
                if ($meta === null) { $missing[] = [$name, $column, $type]; continue; }
                if (preg_replace('/tinyint\(\d+\)/', 'tinyint', strtolower($meta['COLUMN_TYPE'])) !== $type
                    || $meta['IS_NULLABLE'] !== 'YES' || !in_array($meta['COLUMN_DEFAULT'], [null, 'NULL'], true) || $meta['EXTRA'] !== '') {
                    throw new RuntimeException('Incompatible audience column: ' . $table . '.' . $column);
                }
            }
        }
        foreach ($missing as [$table, $column, $type]) {
            $this->sql('ALTER TABLE ' . $this->db->protectIdentifiers($table) . ' ADD ' . $this->db->protectIdentifiers($column) . ' ' . $type . ' NULL DEFAULT NULL');
        }
        // CHECK is portable on the deployed MariaDB; never rewrite existing values to satisfy it.
        $table = $this->db->prefixTable('student_profiles');
        $constraint = $this->db->prefixTable('student_audience_graduating_check');
        $check = $this->sql('SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?', [$table, $constraint])->getRowArray();
        if ($check === null) {
            $this->sql('ALTER TABLE ' . $this->db->protectIdentifiers($table) . ' ADD CONSTRAINT ' . $this->db->protectIdentifiers($constraint) . ' CHECK (is_graduating IS NULL OR is_graduating IN (0,1))');
        } elseif (preg_replace('/[\s`()]+/', '', strtolower($check['CHECK_CLAUSE'])) !== 'is_graduatingisnulloris_graduatingin0,1') {
            throw new RuntimeException('Incompatible graduating audience check.');
        }
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('Audience targeting is forward-only; never discard profile or publication targeting.');
    }
}
