<?php

declare(strict_types=1);

namespace App\Services\Directory;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Local development, insert-only directory transfer. Never serialize/log a plan:
 * only report($plan) is suitable for output or a local provenance ledger.
 * Use dedicated, non-persistent connections, preferably with SELECT-only source credentials.
 */
final class LegacyDirectoryImporter
{
    private const SOURCE_COLUMNS = 'u.id, u.tenant_id, u.kind, u.first_name, u.last_name, u.middle_name,
        u.date_of_birth, u.gender, u.address, u.archived_at, u.deleted_at, u.student_number, u.course,
        u.year_level, u.section, u.employee_number, u.department, u.position,
        u.employment_status, u.is_teaching';
    private const OFFICIAL_ID = "TRIM(CASE kind WHEN 'student' THEN student_number WHEN 'employee' THEN employee_number END) COLLATE utf8mb4_unicode_ci";
    private const LOCK = 'synapse_legacy_directory_import_1';

    private bool $applying = false;

    public function __construct(private BaseConnection $source, private BaseConnection $target)
    {
    }

    /** Pure mapper: no connections, clock reads, legacy PKs, or non-whitelisted output. */
    public static function mapRow(array $row, int $targetTenant = 1, ?string $today = null): array
    {
        $warnings = [];
        $skip = static fn (string $reason): array => ['reason' => $reason, 'warnings' => [], 'person' => null, 'profile' => null];
        $kind = $row['kind'] ?? null;
        if (! in_array($kind, ['student', 'employee'], true)) {
            return $skip('unsupportedkind');
        }
        if (($row['archived_at'] ?? null) !== null || ($row['deleted_at'] ?? null) !== null) {
            return $skip('archived');
        }
        $id = $row[$kind === 'student' ? 'student_number' : 'employee_number'] ?? null;
        if ($id === null || (is_string($id) && trim($id) === '')) {
            return $skip('sourcemissingid');
        }
        // Never coerce numeric IDs: a leading zero is part of the identifier.
        if (! is_string($id) || ! self::validString($id) || mb_strlen(trim($id), 'UTF-8') > 50
            || preg_match('/[\x00-\x1F\x7F]/', $id)) {
            return $skip('invalidfield');
        }
        foreach (['first_name', 'last_name'] as $field) {
            if (! isset($row[$field]) || (is_string($row[$field]) && trim($row[$field]) === '')) {
                return $skip('missingnames');
            }
            if (! is_string($row[$field]) || ! self::validString($row[$field]) || mb_strlen(trim($row[$field]), 'UTF-8') > 100) {
                return $skip('invalidfield');
            }
        }
        $person = [
            'tenant_id' => $targetTenant, 'kind' => $kind, 'mis_id' => trim($id),
            'first_name' => trim($row['first_name']), 'last_name' => trim($row['last_name']),
            'email' => null, 'phone' => null,
        ];
        $profile = ['tenant_id' => $targetTenant];
        $strings = ['middle_name' => ['person', 'middle_name', 100], 'address' => ['person', 'address', 65535]];
        if ($kind === 'student') {
            $strings += ['course' => ['profile', 'program', 100], 'section' => ['profile', 'section', 50], 'department' => ['profile', 'department', 100]];
        } else {
            $strings += ['department' => ['profile', 'department', 100], 'position' => ['profile', 'position', 100]];
        }
        foreach ($strings as $source => [$destination, $field, $limit]) {
            $value = $row[$source] ?? null;
            if ($value !== null && (! is_string($value) || ! self::validString($value)
                || ($source === 'address' ? strlen($value) : mb_strlen(trim($value), 'UTF-8')) > $limit)) {
                return $skip('invalidfield');
            }
            $value = $value === null ? null : ($source === 'address' ? $value : trim($value));
            if ($destination === 'person') {
                $person[$field] = $value === '' ? null : $value;
            } else {
                $profile[$field] = $value === '' ? null : $value;
            }
        }
        $gender = $row['gender'] ?? null;
        if ($gender !== null && ! in_array($gender, ['male', 'female', 'other'], true)) {
            $warnings[] = 'gender_invalid';
            $gender = null;
        }
        $person['gender'] = $gender;
        $dob = $row['date_of_birth'] ?? null;
        if ($dob !== null) {
            $date = is_string($dob) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $dob)
                            ? DateTimeImmutable::createFromFormat('!Y-m-d', $dob) : false;
            if (! $date || $date->format('Y-m-d') !== $dob || $dob < '1000-01-01' || $dob > ($today ?? gmdate('Y-m-d'))) {
                $warnings[] = 'date_of_birth_invalid';
                $dob = null;
            }
        }
        $person['date_of_birth'] = $dob;
        if ($kind === 'student') {
            $year = $row['year_level'] ?? null;
            if ($year !== null && ! ((is_int($year) && $year >= 0 && $year <= 255)
                || (is_string($year) && preg_match('/^[0-9]{1,3}$/D', $year) && (int) $year <= 255))) {
                $warnings[] = 'year_level_invalid';
                $year = null;
            }
            $profile['year_level'] = $year === null ? null : (int) $year;
        } else {
            $status = $row['employment_status'] ?? null;
            if ($status === null) {
                $status = 'unknown';
                $warnings[] = 'employment_status_unknown';
            } elseif (! in_array($status, ['active', 'inactive', 'on_leave'], true)) {
                return $skip('invalidfield');
            }
            $teaching = $row['is_teaching'] ?? null;
            if ($teaching === null) {
                // An unknown teaching flag is explicitly defaulted, not inferred.
                $teaching = 0;
                $warnings[] = 'is_teaching_defaulted';
            } elseif (! in_array($teaching, [0, 1, '0', '1', false, true], true)) {
                return $skip('invalidfield');
            }
            $profile['employment_status'] = $status;
            $profile['is_teaching'] = (int) $teaching;
        }

        return ['reason' => null, 'warnings' => $warnings, 'person' => $person, 'profile' => $profile];
    }

    /** Contains PII internally. Pass only report($plan) to stdout/logging. No target writes. */
    public function plan(int $sourceTenant = 1, int $targetTenant = 1): array
    {
        $context = $this->guard($sourceTenant, $targetTenant);
        $raw = $this->snapshot();
        $fingerprint = self::digest(['context' => $context, 'source' => $raw]);
        $report = [
            'mode' => 'dry-run', 'source_tenant' => 1, 'target_tenant' => 1,
            'source_rows' => count($raw), 'planned' => 0, 'inserted' => 0,
                        'inserted_students' => 0, 'inserted_employees' => 0,
            'students' => 0, 'employees' => 0,
            'skipped' => array_fill_keys(['archived', 'unsupportedkind', 'sourcemissingid', 'missingnames', 'duplicateid', 'invalidfield', 'targetexists'], 0),
            'mismatchednamecounts' => 0, 'warnings' => [], 'fingerprint' => $fingerprint,
        ];
        $rows = [];
        $today = gmdate('Y-m-d');
        foreach ($raw as $row) {
            $mapped = self::mapRow($row, 1, $today);
            $reason = $mapped['reason'];
            // Ambiguity takes priority over other validation failures for active supported rows.
            if (in_array($row['kind'], ['student', 'employee'], true) && $row['archived_at'] === null
                            && $row['deleted_at'] === null && (int) $row['duplicate_count'] > 1) {
                $reason = 'duplicateid';
            }
            if ($reason !== null) {
                $report['skipped'][$reason]++;
                continue;
            }
            foreach ($mapped['warnings'] as $warning) {
                $report['warnings'][$warning] = ($report['warnings'][$warning] ?? 0) + 1;
            }
            $existing = $this->existing($mapped['person']);
            if ($existing !== null) {
                $report['skipped']['targetexists']++;
                if (! self::sameNames($existing, $mapped['person'])) {
                    $report['mismatchednamecounts']++;
                }
                continue;
            }
            $rows[] = ['person' => $mapped['person'], 'profile' => $mapped['profile']];
            $report[$row['kind'] === 'student' ? 'students' : 'employees']++;
        }
        $report['planned'] = count($rows);
        $plan = ['rows' => $rows, 'report' => $report, 'fingerprint' => $fingerprint, 'context' => $context];
        $plan['digest'] = self::digest($plan);

        return $plan;
    }

    /** Safe, aggregate-only provenance payload. Never return internal rows. */
    public function report(array $plan): array
    {
        $this->validatePlan($plan);

        return $plan['report'] + ['digest' => $plan['digest']];
    }

    /** Explicit opt-in by the parent runner; never called by plan(). */
    public function apply(array $plan): array
    {
        $this->validatePlan($plan);
        $this->guard(1, 1);
        $locked = false;
        $transaction = false;
        try {
            $lock = self::select($this->target, 'SELECT GET_LOCK(?, 5) AS acquired', [self::LOCK]);
            if ((int) ($lock[0]['acquired'] ?? 0) !== 1) {
                throw new RuntimeException('Directory import lock unavailable.');
            }
            $locked = true;
            if (! $this->target->transBegin()) {
                throw new RuntimeException('Cannot begin directory transaction.');
            }
            $transaction = true;
            // Re-materialize under a NEW source snapshot. Do not trust caller-supplied rows.
            $this->applying = true;
            $fresh = $this->plan();
            if (! hash_equals($plan['fingerprint'], $fresh['fingerprint'])) {
                throw new RuntimeException('Directory plan stale: source, schema, or tenant changed.');
            }
            $report = $fresh['report'];
            $report['mode'] = 'apply';
            $now = gmdate('Y-m-d H:i:s');
            foreach ($fresh['rows'] as $row) {
                $person = $row['person'];
                // SQL collation equality, including soft-deleted rows, with locking read.
                $existing = $this->existing($person, true);
                if ($existing !== null) {
                    $report['skipped']['targetexists']++;
                    if (! self::sameNames($existing, $person)) {
                        $report['mismatchednamecounts']++;
                    }
                    continue;
                }
                $person += ['created_at' => $now, 'updated_at' => $now];
                $personId = $this->insert('people', $person);
                if (! ctype_digit((string) $personId) || (int) $personId < 1) {
                    throw new RuntimeException('Missing generated person ID.');
                }
                $profile = $row['profile'] + ['person_id' => $personId, 'created_at' => $now, 'updated_at' => $now];
                $table = $person['kind'] === 'student' ? 'student_profiles' : 'employee_profiles';
                $this->insert($table, $profile);
                $this->verifyInsert('people', ['id' => $personId] + $person);
                $this->verifyInsert($table, $profile);
                $report['inserted']++;
                $report[$person['kind'] === 'student' ? 'inserted_students' : 'inserted_employees']++;
            }
            if (! $this->target->transStatus() || ! $this->target->transCommit()) {
                throw new RuntimeException('Directory transaction failed.');
            }
            $transaction = false;
            $report['planned_digest'] = $plan['digest'];
            $report['digest'] = self::digest($report);

            return $report;
        } catch (Throwable $error) {
            if ($transaction) {
                $this->target->transRollback();
            }
            // Only fixed application messages may escape; driver errors can contain PII.
            $safe = [
                'Directory import lock unavailable.',
                'Cannot begin directory transaction.',
                'Directory plan stale: source, schema, or tenant changed.',
                'Expected directory uniqueness/collation not found.',
                'Target directory triggers are not approved.',
                'Directory insert produced database warnings.',
                'Directory write verification failed.',
            ];
            $message = in_array($error->getMessage(), $safe, true)
                ? $error->getMessage() : 'Directory apply failed (database details withheld to protect directory data).';
            throw new RuntimeException($message, 0, $error);
        } finally {
            $this->applying = false;
            if ($locked) {
                self::select($this->target, 'SELECT RELEASE_LOCK(?) AS released', [self::LOCK]);
            }
        }
    }

    private function guard(int $sourceTenant, int $targetTenant): array
    {
        if (! defined('ENVIRONMENT') || ENVIRONMENT !== 'development' || $sourceTenant !== 1 || $targetTenant !== 1
            || $this->source === $this->target) {
            throw new RuntimeException('Only the approved development tenant pair is allowed.');
        }
        foreach ([$this->source, $this->target] as $db) {
            if ($db->DBDriver !== 'MySQLi' || ! in_array($db->hostname, ['localhost', '127.0.0.1', '::1'], true)
                || $db->DBPrefix !== '' || $db->pConnect || ! empty($db->failover)) {
                throw new RuntimeException('Dedicated local MySQL connections without prefixes/failover are required.');
            }
        }
        if ($this->source->database !== 'synapse_zcode' || $this->target->database !== 'humus_db'
            || $this->source->transDepth !== 0 || $this->target->transDepth !== ($this->applying ? 1 : 0)) {
            throw new RuntimeException('Unexpected database names or transaction scope.');
        }
        // Set the dedicated source session read-only before even tenant/schema reads.
        self::execute($this->source, 'SET SESSION TRANSACTION READ ONLY');
        $sourceServer = self::select($this->source, 'SELECT DATABASE() AS db, @@hostname AS host, @@port AS port, CONNECTION_ID() AS connection_id')[0];
        $targetServer = self::select($this->target, 'SELECT DATABASE() AS db, @@hostname AS host, @@port AS port, CONNECTION_ID() AS connection_id')[0];
        if ($sourceServer['db'] !== 'synapse_zcode' || $targetServer['db'] !== 'humus_db'
            || $sourceServer['host'] !== $targetServer['host'] || (string) $sourceServer['port'] !== (string) $targetServer['port']
            || $sourceServer['connection_id'] === $targetServer['connection_id']) {
            throw new RuntimeException('Source and target must be distinct sessions on the same local server.');
        }
        $sourceTenantRow = self::select($this->source, 'SELECT id, slug, name FROM tenants WHERE id = 1 AND is_active = 1');
        $targetTenantRow = self::select($this->target, 'SELECT id, slug, name FROM tenants WHERE id = 1 AND deleted_at IS NULL AND is_active = 1');
        foreach ([[$sourceTenantRow, 'foundation-university'], [$targetTenantRow, 'fu_main']] as [$rows, $slug]) {
            if (count($rows) !== 1 || $rows[0]['slug'] !== $slug
                || strtolower(str_replace(' ', '', $rows[0]['name'])) !== 'foundationuniversity') {
                throw new RuntimeException('Approved Foundation University tenant identity not found.');
            }
        }

        return [
            'source_database' => 'synapse_zcode', 'target_database' => 'humus_db',
            'server' => [$sourceServer['host'], $sourceServer['port']],
            'source_tenant' => $sourceTenantRow, 'target_tenant' => $targetTenantRow,
            'source_schema' => $this->schema($this->source, ['tenants', 'users']),
            'target_schema' => $this->schema($this->target, ['tenants', 'people', 'student_profiles', 'employee_profiles']),
        ];
    }

    private function schema(BaseConnection $db, array $tables): array
    {
        $metadata = [];
        foreach ($tables as $table) {
            $engine = self::select($db, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (count($engine) !== 1 || $engine[0]['ENGINE'] !== 'InnoDB') {
                throw new RuntimeException('Directory tables must exist and use InnoDB.');
            }
            $columns = self::select($db, 'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]);
            $indexes = self::select($db, 'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table]);
            if ($db === $this->target && $table !== 'tenants') {
                $triggers = self::select($db, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', [$table]);
                if ($triggers !== []) {
                    throw new RuntimeException('Target directory triggers are not approved.');
                }
                $byName = array_column($columns, null, 'COLUMN_NAME');
                if (! str_contains($byName['id']['EXTRA'] ?? '', 'auto_increment')) {
                    throw new RuntimeException('Directory primary IDs must be generated.');
                }
                $requiredKey = $table === 'people' ? ['tenant_id', 'kind', 'mis_id'] : ['tenant_id', 'person_id'];
                $unique = [];
                foreach ($indexes as $index) {
                    if ((int) $index['NON_UNIQUE'] === 0 && $index['SUB_PART'] === null) {
                        $unique[$index['INDEX_NAME']][] = $index['COLUMN_NAME'];
                    }
                }
                if (! in_array($requiredKey, $unique, true)
                    || ($table === 'people' && ($byName['mis_id']['COLLATION_NAME'] ?? '') !== 'utf8mb4_unicode_ci')) {
                    throw new RuntimeException('Expected directory uniqueness/collation not found.');
                }
            }
            $foreignKeys = self::select($db, 'SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION', [$table]);
            $rules = self::select($db, 'SELECT CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME', [$table]);
            $metadata[$table] = [$engine, $columns, $indexes, $foreignKeys, $rules];
        }

        return $metadata;
    }

    /** Only users and the explicit directory column whitelist are read here. */
    private function snapshot(): array
    {
        self::execute($this->source, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        self::execute($this->source, 'SET SESSION TRANSACTION READ ONLY');
        self::execute($this->source, 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $id = self::OFFICIAL_ID;
            $columns = self::SOURCE_COLUMNS;
            $rows = self::select($this->source, "SELECT {$columns}, COALESCE(d.duplicate_count, 0) AS duplicate_count
                FROM users u LEFT JOIN (
                    SELECT kind, {$id} AS official_id, COUNT(*) AS duplicate_count
                    FROM users WHERE tenant_id = 1 AND archived_at IS NULL AND deleted_at IS NULL AND kind IN ('student', 'employee')
                    GROUP BY kind, {$id} HAVING COUNT(*) > 1 AND official_id IS NOT NULL AND official_id <> ''
                ) d ON d.kind = u.kind AND d.official_id = TRIM(CASE u.kind WHEN 'student' THEN u.student_number WHEN 'employee' THEN u.employee_number END) COLLATE utf8mb4_unicode_ci
                WHERE u.tenant_id = 1 ORDER BY u.id");
            self::execute($this->source, 'COMMIT');

            return $rows;
        } catch (Throwable $error) {
            self::execute($this->source, 'ROLLBACK');
            throw new RuntimeException('Read-only directory snapshot failed.');
        }
    }

    private function existing(array $person, bool $lock = false): ?array
    {
        $rows = self::select($this->target, 'SELECT id, first_name, middle_name, last_name FROM people WHERE tenant_id = ? AND kind = ? AND mis_id = ? COLLATE utf8mb4_unicode_ci' . ($lock ? ' FOR UPDATE' : ''), [$person['tenant_id'], $person['kind'], $person['mis_id']]);

        return $rows[0] ?? null;
    }

    private function insert(string $table, array $values): int
    {
        if (! in_array($table, ['people', 'student_profiles', 'employee_profiles'], true)
            || ! $this->target->table($table)->insert($values) || $this->target->affectedRows() !== 1) {
            throw new RuntimeException('Directory insert did not write exactly one row.');
        }
        // mysqli's insert ID can be reset by a subsequent statement, including SHOW WARNINGS.
        $insertId = $this->target->insertID();
        if (!ctype_digit((string) $insertId) || (int) $insertId < 1) {
            throw new RuntimeException('Missing generated directory ID.');
        }
        $warnings = self::select($this->target, 'SHOW WARNINGS');
        if ($warnings !== []) {
            throw new RuntimeException('Directory insert produced database warnings.');
        }
        return (int) $insertId;
    }

    private function verifyInsert(string $table, array $expected): void
    {
        $builder = $this->target->table($table);
        $key = $table === 'people' ? ['id' => $expected['id']] : ['tenant_id' => 1, 'person_id' => $expected['person_id']];
        $query = $builder->where($key)->get();
        if ($query === false || count($rows = $query->getResultArray()) !== 1) {
            throw new RuntimeException('Directory inserted row not found.');
        }
        foreach ($expected as $field => $value) {
            $actual = $rows[0][$field] ?? null;
            if ($value === null ? $actual !== null : (string) $actual !== (string) $value) {
                throw new RuntimeException('Directory write verification failed.');
            }
        }
    }

    private function validatePlan(array $plan): void
    {
        $digest = $plan['digest'] ?? null;
        unset($plan['digest']);
        if (! is_string($digest) || ! isset($plan['rows'], $plan['report'], $plan['fingerprint'], $plan['context'])
            || ! hash_equals($digest, self::digest($plan))) {
            throw new RuntimeException('Invalid directory plan digest.');
        }
    }

    private static function sameNames(array $left, array $right): bool
    {
        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if (trim($left[$field] ?? '') !== trim($right[$field] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private static function validString(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0");
    }

    private static function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function execute(BaseConnection $db, string $sql): void
    {
        if ($db->query($sql) === false) {
            throw new RuntimeException('Directory database operation failed.');
        }
    }

    private static function select(BaseConnection $db, string $sql, array $binds = []): array
    {
        try {
            $query = $db->query($sql, $binds);
            if ($query === false || $query === true) {
                throw new RuntimeException('Directory read failed.');
            }

            return $query->getResultArray();
        } catch (Throwable $error) {
            throw new RuntimeException('Directory read failed (details withheld to protect directory data).', 0, $error);
        }
    }
}
