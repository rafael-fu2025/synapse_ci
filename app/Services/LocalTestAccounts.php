<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use RuntimeException;
use Throwable;

/** Explicit local-only fixtures; only an explicit reset updates recognized fixture passwords. */
final class LocalTestAccounts
{
    public function __construct(
        private ?BaseConnection $db = null,
        // Trusted dependency injection for isolated tests, never a command-line option.
        private ?string $environment = null,
    ) {
    }

    /**
     * @return array{tenant_id: int, accounts: list<array<string, mixed>>, password?: string}
     * Status is would_create, created, skipped or password_reset. Password exists only after committed inserts.
     */
    public function run(bool $create = false, ?int $tenantId = null, ?string $resetPassword = null): array
    {
        if (($this->environment ?? (defined('ENVIRONMENT') ? ENVIRONMENT : '')) !== 'development') {
            throw new DomainException('Test accounts require the development environment.');
        }
        if ($resetPassword !== null) {
            if ($create) {
                throw new DomainException('Create and password reset are mutually exclusive.');
            }
            // PASSWORD_DEFAULT currently uses bcrypt, which truncates beyond 72 bytes.
            if (strlen($resetPassword) < 8 || strlen($resetPassword) > 72 || str_contains($resetPassword, "\0")) {
                throw new DomainException('Reset password must be 8 to 72 bytes without NUL characters.');
            }
        }
        $tenantId ??= CurrentTenant::id();
        if ($tenantId <= 0) {
            throw new DomainException('Select a positive tenant ID.');
        }

        $transaction = false;
        $ownsConnection = $this->db === null;
        try {
            $db = $this->connection();
            if ($create || $resetPassword !== null) {
                $this->requireTransactionalTables($db);
                if (!$db->transBegin()) {
                    throw new RuntimeException();
                }
                $transaction = true;
            }

            $lock = $resetPassword !== null;
            $tenants = $this->rows($db, $db->table('tenants')->where('id', $tenantId)->where('is_active', 1)
                ->where('deleted_at', null), $lock);
            if ($tenants === []) {
                throw new DomainException('The selected tenant is missing or inactive.');
            }
            $fixtures = $this->fixtures($tenantId);
            $accounts = [];
            // Raw builders deliberately include deleted, suspended and cross-tenant collisions.
            foreach ($fixtures as $fixture) {
                $existing = $this->preflight($db, $fixture, $lock);
                if ($lock && $existing === null) {
                    throw new DomainException('Password reset requires all 15 exact fixtures; missing ' . $fixture['user']['username'] . '; nothing was changed.');
                }
                $accounts[] = [
                    'username' => $fixture['user']['username'],
                    'email' => $fixture['user']['email'],
                    'mis_id' => $fixture['person']['mis_id'],
                    'role' => $fixture['user']['role'],
                    'kind' => $fixture['person']['kind'],
                    'user_id' => $existing['id'] ?? null,
                    'person_id' => $existing['person_id'] ?? null,
                    'status' => $existing === null ? 'would_create' : 'skipped',
                    'newly_created' => false,
                ];
            }

            $password = null;
            if ($resetPassword !== null) {
                // Recheck the entire set after acquiring all identity/profile locks.
                foreach ($fixtures as $i => $fixture) {
                    $existing = $this->preflight($db, $fixture, true);
                    if ($existing === null || (int) $existing['id'] !== (int) $accounts[$i]['user_id']) {
                        throw new DomainException('Password reset requires all 15 exact fixtures; nothing was changed.');
                    }
                }
                $users = new UserModel($db);
                foreach ($accounts as $i => $account) {
                    if (!$users->update($account['user_id'], ['password_hash' => password_hash($resetPassword, PASSWORD_DEFAULT)])) {
                        throw new RuntimeException();
                    }
                    $accounts[$i]['status'] = 'password_reset';
                }
            }
            if ($create) {
                foreach ($fixtures as $i => $fixture) {
                    if ($accounts[$i]['status'] === 'skipped') {
                        continue;
                    }
                    $password ??= bin2hex(random_bytes(16));
                    $now = date('Y-m-d H:i:s');
                    $timestamps = ['created_at' => $now, 'updated_at' => $now];
                    $this->insert($db, 'people', $fixture['person'] + $timestamps);
                    $personId = (int) $db->insertID();
                    $this->insert($db, $fixture['table'], $fixture['profile'] + ['person_id' => $personId] + $timestamps);
                    $this->insert($db, 'users', $fixture['user'] + [
                        'person_id' => $personId,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ] + $timestamps);
                    $accounts[$i]['user_id'] = (int) $db->insertID();
                    $accounts[$i]['person_id'] = $personId;
                    $accounts[$i]['status'] = 'created';
                    $accounts[$i]['newly_created'] = true;
                }
            }
            if ($transaction) {
                if (!$db->transStatus() || !$db->transCommit()) {
                    throw new RuntimeException();
                }
                $transaction = false;
            }
            $result = ['tenant_id' => $tenantId, 'accounts' => $accounts];
            if ($password !== null) {
                $result['password'] = $password;
            }
            return $result;
        } catch (Throwable $error) {
            if ($transaction) {
                try {
                    $this->db->transRollback();
                } catch (Throwable) {
                    // Never expose driver errors, SQL, connection details or credentials.
                }
            }
            if ($error instanceof DomainException) {
                throw $error;
            }
            throw new RuntimeException('Test account operation failed; no accounts were committed.');
        } finally {
            if ($ownsConnection && $this->db !== null) {
                try {
                    $this->db->close();
                } catch (Throwable) {
                    // Cleanup must not expose driver internals or hide the operation's result.
                }
                $this->db = null;
            }
        }
    }

    /** Reject DSNs entirely: a second connection description is unnecessary and ambiguous. */
    public static function assertLocalConfiguration(array $config): void
    {
        if (!in_array($config['DBDriver'] ?? null, ['MySQLi', 'SQLite3'], true)
            || ($config['DSN'] ?? '') !== '' || ($config['failover'] ?? []) !== []
            || (($config['DBDriver'] ?? null) === 'MySQLi'
                && !in_array($config['hostname'] ?? null, ['localhost', '127.0.0.1', '::1'], true))) {
            throw new DomainException('Test accounts require MySQLi on a literal loopback host or SQLite3, without DSN or failovers.');
        }
    }

    private function connection(): BaseConnection
    {
        if ($this->db === null) {
            $config = config('Database');
            $group = $config->defaultGroup;
            $settings = $config->{$group};
            // BaseConnection can eagerly connect when failovers are present: guard first.
            self::assertLocalConfiguration($settings);
            $this->db = Database::connect($settings, false);
        }
        self::assertLocalConfiguration([
            'DBDriver' => $this->db->DBDriver, 'hostname' => $this->db->hostname,
            'DSN' => $this->db->DSN, 'failover' => $this->db->failover,
        ]);
        return $this->db;
    }

    private function requireTransactionalTables(BaseConnection $db): void
    {
        if ($db->DBDriver !== 'MySQLi') {
            return;
        }
        foreach (['people', 'student_profiles', 'employee_profiles', 'users'] as $table) {
            $row = $db->table('information_schema.TABLES')->select('ENGINE')
                ->where('TABLE_SCHEMA', $db->database)->where('TABLE_NAME', $db->prefixTable($table))
                ->get()->getRowArray();
            if (strtoupper($row['ENGINE'] ?? '') !== 'INNODB') {
                throw new DomainException('Test accounts require transactional InnoDB identity tables.');
            }
        }
    }

    private function insert(BaseConnection $db, string $table, array $data): void
    {
        if (!$db->table($table)->insert($data)) {
            throw new RuntimeException();
        }
    }

    /** Locking reads are current reads on MySQL, including the post-lock preflight. */
    private function rows(BaseConnection $db, BaseBuilder $builder, bool $lock): array
    {
        $sql = $builder->getCompiledSelect();
        if ($lock && $db->DBDriver === 'MySQLi') {
            $sql .= ' FOR UPDATE';
        }
        return $db->query($sql)->getResultArray();
    }

    private function preflight(BaseConnection $db, array $fixture, bool $lock = false): ?array
    {
        $user = $fixture['user'];
        $person = $fixture['person'];
        $identifiers = [$user['username'], $user['email'], strtolower($person['mis_id'])];
        $users = $this->rows($db, $db->table('users')->groupStart()->whereIn('LOWER(username)', $identifiers)
            ->orWhereIn('LOWER(email)', $identifiers)->groupEnd(), $lock);
        $people = $this->rows($db, $db->table('people')->groupStart()->whereIn('LOWER(mis_id)', $identifiers)
            ->orWhereIn('LOWER(email)', $identifiers)->groupEnd(), $lock);
        if ($users === [] && $people === []) {
            return null;
        }
        $collision = 'Incompatible fixture collision for ' . $user['username'] . '; nothing was changed.';
        if (count($users) !== 1 || count($people) !== 1) {
            throw new DomainException($collision);
        }
        $existing = $users[0];
        $linked = $people[0];
        if (!$this->matches($existing, $user + ['deleted_at' => null])
            || !$this->matches($linked, $person + ['deleted_at' => null])
            || (int) $existing['person_id'] !== (int) $linked['id']
            || (int) ($existing['auth_version'] ?? 0) < 1
            || empty(password_get_info((string) ($existing['password_hash'] ?? ''))['algo'])
            || count($this->rows($db, $db->table('users')->where('person_id', $linked['id']), $lock)) !== 1) {
            throw new DomainException($collision);
        }
        $profiles = $this->rows($db, $db->table($fixture['table'])->where('person_id', $linked['id']), $lock);
        $other = $fixture['table'] === 'student_profiles' ? 'employee_profiles' : 'student_profiles';
        if (count($profiles) !== 1 || !$this->matches($profiles[0], $fixture['profile'])
            || count($this->rows($db, $db->table($other)->where('person_id', $linked['id']), $lock)) !== 0) {
            throw new DomainException($collision);
        }
        return $existing;
    }

    private function matches(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)
                || ($value === null ? $actual[$key] !== null : (string) $actual[$key] !== (string) $value)) {
                return false;
            }
        }
        return true;
    }

    private function fixtures(int $tenantId): array
    {
        $definitions = [
            ['superadmin', 'superadmin', 'Superadmin', 'Test Administration'],
            ['clinic.admin', 'clinic_admin', 'Clinic Admin', 'Health Services'],
            ['clinic.staff', 'clinic_staff', 'Clinic Staff', 'Health Services'],
            ['clinic.staff2', 'clinic_staff', 'Clinic Staff 2', 'Health Services'],
            ['guidance.admin', 'guidance_admin', 'Guidance Admin', 'Guidance'],
            ['guidance.supervisor', 'guidance_supervisor', 'Guidance Supervisor', 'Guidance'],
            ['counsellor', 'counsellor', 'Counsellor', 'Guidance'],
            ['counsellor2', 'counsellor', 'Counsellor 2', 'Guidance'],
            ['bmg.admin', 'bmg_admin', 'BMG Admin', 'Facilities'],
            ['facilities.op', 'facilities_op', 'Facilities Operator', 'Facilities'],
            ['facilities.op2', 'facilities_op', 'Facilities Operator 2', 'Facilities'],
            ['audit.reader', 'audit_reader', 'Audit Reader', 'Test Administration'],
            ['report.viewer', 'report_viewer', 'Report Viewer', 'Test Administration'],
            ['student', 'student', 'Student', 'CCS'],
            ['employee', 'employee', 'Employee', 'Test Employee'],
        ];
        $fixtures = [];
        foreach ($definitions as [$slug, $role, $label, $department]) {
            $username = 'test.' . $slug;
            $email = $username . '@synapse.invalid';
            $student = $role === 'student';
            $fixtures[] = [
                'user' => ['tenant_id' => $tenantId, 'username' => $username, 'email' => $email,
                    'role' => $role, 'status' => 'active'],
                'person' => ['tenant_id' => $tenantId, 'kind' => $student ? 'student' : 'employee',
                    'mis_id' => 'SYNAPSE-TEST-' . strtoupper(str_replace('.', '-', $slug)),
                    'first_name' => 'Test', 'last_name' => $label, 'email' => $email],
                'table' => $student ? 'student_profiles' : 'employee_profiles',
                'profile' => ['tenant_id' => $tenantId, 'department' => $department] + ($student
                    ? ['program' => 'BSIT', 'year_level' => 3, 'section' => 'TEST']
                    : ['position' => 'Test ' . $label, 'employment_status' => 'active', 'is_teaching' => 0]),
            ];
        }
        return $fixtures;
    }
}
