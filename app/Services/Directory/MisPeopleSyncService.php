<?php

declare(strict_types=1);

namespace App\Services\Directory;

use App\Integrations\Mis\MisClientInterface;
use App\Integrations\Mis\MisProfileMapper;
use App\Services\Audit\AuditService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;
use RuntimeException;
use Throwable;

/** Directory only. Never provision accounts, permissions, or clinical records. */
final class MisPeopleSyncService
{
    public function __construct(private ?MisClientInterface $client = null, private ?BaseConnection $db = null) {}

    public function actor(): array
    {
        return (new MisSyncService(null, $this->db))->actor();
    }

    /** Local configuration only, including on GET: never resolve an adapter. */
    public static function unavailableReason(): ?string
    {
        if (filter_var(env('FUMIS_ENABLED', false), FILTER_VALIDATE_BOOL)) {
            return null;
        }
        if (filter_var(env('FUMIS_FAKE_ENABLED', false), FILTER_VALIDATE_BOOL)
            && in_array(ENVIRONMENT, ['development', 'testing'], true)) {
            return null;
        }
        return 'People sync is unavailable: MIS is disabled. Development fixtures require explicit opt-in.';
    }

    public function status(): array
    {
        $actor = $this->actor();
        $db = $this->db ??= Database::connect();
        $counts = [];
        foreach (['student', 'employee'] as $kind) {
            $counts[$kind] = $db->table('people')->where('tenant_id', $actor['tenant_id'])
                ->where('kind', $kind)->where('deleted_at', null)->countAllResults();
        }
        $last = $db->table('audit_events')->select('occurred_at')->where('tenant_id', $actor['tenant_id'])
            ->where('event_type', 'mis.people.synced')->orderBy('id', 'DESC')->get(1)->getRowArray();
        return ['counts' => $counts, 'lastSync' => $last, 'unavailableReason' => self::unavailableReason()];
    }

    public function sync(?callable $progress = null): array
    {
        $actor = $this->actor();
        $db = $this->db ??= Database::connect();
        if ($db->transDepth !== 0 || !in_array($db->DBDriver, ['MySQLi', 'SQLite3'], true)) {
            throw new RuntimeException('People sync is unavailable.', 503);
        }
        try {
            $client = $this->client ??= Services::misClient();
            $this->progress($progress, 5, 'Fetching and validating student directory.');
            $rows = ['student' => $this->fetch($client, 'student')];
            $this->progress($progress, 40, 'Fetching and validating employee directory.');
            $rows['employee'] = $this->fetch($client, 'employee');
        } catch (Throwable) {
            throw new RuntimeException('MIS people directory is unavailable or incomplete. No people were changed.', 503);
        }
        $this->progress($progress, 80, 'Saving directory and count-only audit.');
        $audit = new AuditService(null, $db);
        $db->resetTransStatus();
        if (!$db->transBegin()) { throw new RuntimeException('People sync is unavailable.', 503); }
        try {
            $audit->lockTenant($actor['tenant_id']);
            $fresh = $this->actor();
            if ($fresh['id'] !== $actor['id'] || $fresh['tenant_id'] !== $actor['tenant_id'] || $fresh['role'] !== $actor['role']) {
                throw new RuntimeException('Access changed.', 403);
            }
            $syncedAt = date('Y-m-d H:i:s');
            $counts = [];
            foreach ($rows as $kind => $profiles) {
                $counts[$kind] = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_deleted' => 0];
                foreach ($profiles as $mapped) {
                    $person = $db->table('people')->where('tenant_id', $fresh['tenant_id'])->where('kind', $kind)
                        ->where('mis_id', $mapped['mis_id'])->get()->getRowArray();
                    if ($person !== null && $person['deleted_at'] !== null) {
                        $counts[$kind]['skipped_deleted']++;
                        continue;
                    }
                    $values = [];
                    foreach (['first_name', 'middle_name', 'last_name'] as $field) {
                        if ($mapped[$field] !== null) { $values[$field] = $mapped[$field]; }
                    }
                    foreach (['first_name', 'last_name'] as $field) {
                        if (!isset($values[$field]) && empty($person[$field])) { throw new RuntimeException('Required name missing.'); }
                    }
                    $table = $kind . '_profiles';
                    $existing = $person === null ? null : $db->table($table)->where('tenant_id', $fresh['tenant_id'])
                        ->where('person_id', $person['id'])->get()->getRowArray();
                    $profile = [];
                    $fields = $kind === 'student'
                        ? ['program', 'year_level', 'section', 'department', 'enrollment_type', 'is_graduating']
                        : ['department', 'position', 'employment_status', 'is_teaching'];
                    foreach ($fields as $field) {
                        if ($mapped[$field] !== null || ($mapped[$field . '_provided'] ?? false)) {
                            $profile[$field] = is_bool($mapped[$field]) ? (int) $mapped[$field] : $mapped[$field];
                        }
                    }
                    $change = $person === null ? 'inserted' : 'unchanged';
                    foreach ([$values, $profile] as $index => $data) {
                        $old = $index === 0 ? $person : $existing;
                        foreach ($data as $key => $value) {
                            if ($change !== 'inserted' && ($old === null || ($old[$key] === null) !== ($value === null)
                                || ($value !== null && (string) $old[$key] !== (string) $value))) { $change = 'updated'; }
                        }
                    }
                    $values += ['mis_synced_at' => $syncedAt, 'mis_payload_hash' => $mapped['raw_hash'], 'updated_at' => $syncedAt];
                    if ($person === null) {
                        $this->saved($db->table('people')->insert($values + ['tenant_id' => $fresh['tenant_id'],
                            'kind' => $kind, 'mis_id' => $mapped['mis_id'], 'created_at' => $syncedAt]));
                        $personId = $db->insertID();
                    } else {
                        $personId = $person['id'];
                        $this->saved($db->table('people')->where('tenant_id', $fresh['tenant_id'])->where('id', $personId)->update($values));
                    }
                    $profile['updated_at'] = $syncedAt;
                    if ($existing === null) {
                        // Unknown employment must not silently activate an imported person.
                        if ($kind === 'employee') { $profile += ['employment_status' => 'inactive', 'is_teaching' => 0]; }
                        $this->saved($db->table($table)->insert($profile + ['tenant_id' => $fresh['tenant_id'],
                            'person_id' => $personId, 'created_at' => $syncedAt]));
                    } else {
                        $this->saved($db->table($table)->where('tenant_id', $fresh['tenant_id'])->where('id', $existing['id'])->update($profile));
                    }
                    $counts[$kind][$change]++;
                }
            }
            $audit->log('mis.people.synced', 'mis_people_directory', (string) $fresh['tenant_id'], 'sync', [
                'scope' => 'directory_only', 'source' => 'mis', 'counts' => $counts, 'synced_at' => $syncedAt,
                'deletion_policy' => 'retain_missing_skip_deleted',
            ], $fresh['id'], $fresh['role']);
            if (!$db->transStatus() || !$db->transCommit()) { throw new RuntimeException('Commit failed.'); }
        } catch (Throwable $error) {
            $db->transRollback();
            $db->resetTransStatus();
            if (in_array($error->getCode(), [401, 403], true)) { throw new RuntimeException('Access changed. Reload before trying again.', 403); }
            throw new RuntimeException('MIS people synchronization could not be committed. No people were changed.', 503);
        }
        $this->progress($progress, 100, 'MIS people directory synchronized.');
        return compact('counts', 'syncedAt');
    }

    private function fetch(MisClientInterface $client, string $kind): array
    {
        $result = $seen = [];
        $total = $max = null;
        $mapper = new MisProfileMapper();
        for ($page = 1; $page <= 400; $page++) {
            $response = $kind === 'student' ? $client->fetchStudentsPage($page, 25) : $client->fetchEmployeesPage($page, 25);
            foreach (['current_page', 'max_page', 'limit'] as $field) {
                if (!is_int($response[$field] ?? null)) { throw new RuntimeException('Invalid pagination.'); }
            }
            $max ??= $response['max_page'];
            $data = $response['data'] ?? null;
            if ($max < 0 || $max > 400 || $response['max_page'] !== $max || $response['limit'] !== 25
                || $response['current_page'] !== $page || !is_array($data) || !array_is_list($data)
                || count($data) > 25 || count($result) + count($data) > 10000
                || ($max === 0 && ($page !== 1 || $data !== []))
                || ($max > 0 && ($page > $max || ($page < $max && count($data) !== 25)
                    || ($page > 1 && $data === [])))) {
                throw new RuntimeException('Incomplete directory.');
            }
            // total is not documented/required, but validate it when supplied.
            if (array_key_exists('total', $response)) {
                $pageTotal = $response['total'];
                if (!is_int($pageTotal) || $pageTotal < 0 || $pageTotal > 10000
                    || ($total !== null && $total !== $pageTotal)
                    || max(1, $max) !== max(1, (int) ceil($pageTotal / 25))
                    || ($page === max(1, $max) && count($result) + count($data) !== $pageTotal)) {
                    throw new RuntimeException('Inconsistent directory total.');
                }
                $total = $pageTotal;
            }
            if ($page === max(1, $max) && $total !== null && count($result) + count($data) !== $total) {
                throw new RuntimeException('Incomplete directory total.');
            }
            foreach ($response['data'] as $row) {
                if (!is_array($row) || array_is_list($row) || isset($row['data'])) { throw new RuntimeException('Invalid directory row.'); }
                $mapped = $kind === 'student' ? $mapper->mapStudent($row) : $mapper->mapEmployee($row);
                $this->validate($row, $mapped, $kind);
                $key = mb_strtolower($mapped['mis_id'], 'UTF-8');
                if (isset($seen[$key])) { throw new RuntimeException('Duplicate official ID.'); }
                $seen[$key] = true;
                $result[] = $mapped;
            }
            if ($page === max(1, $max)) { return $result; }
        }
        throw new RuntimeException('Directory bound exceeded.');
    }

    private function validate(array $raw, array $mapped, string $kind): void
    {
        // Bulk imports require the canonical official ID, never a generic database id
        // or a tolerant login alias. Keep leading zeros and reject coerced/trimmed IDs.
        $idField = $kind === 'student' ? 'student_id' : 'employee_id';
        $id = $raw[$idField] ?? null;
        if (!is_string($id) || $id === '' || trim($id) !== $id || $mapped['mis_id'] !== $id) {
            throw new RuntimeException('Invalid official ID.');
        }
        foreach ($kind === 'student' ? ['studentId', 'student_number'] : ['employeeId', 'employee_number'] as $alias) {
            if (array_key_exists($alias, $raw) && $raw[$alias] !== $id) {
                throw new RuntimeException('Conflicting official ID.');
            }
        }
        foreach ($mapped as $field => $value) {
            if (!is_string($value)) { continue; }
            $max = match ($field) { 'mis_id', 'section' => 50, 'raw_hash' => 64, default => 100 };
            if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                throw new RuntimeException('Invalid directory value.');
            }
        }
        // Reject malformed source types rather than letting the tolerant login mapper erase them.
        foreach ($raw as $key => $value) {
            if (!is_string($key) || is_array($value) || is_object($value)) { throw new RuntimeException('Invalid source field.'); }
            if (is_string($value) && (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $value))) {
                throw new RuntimeException('Invalid source text.');
            }
        }
        foreach (['first_name', 'firstName', 'fname', 'middle_name', 'middleName', 'middlename', 'last_name', 'lastName', 'lname',
            'program', 'course', 'program_name', 'section', 'block', 'block_section', 'department', 'department_name',
            'departmentName', 'department_code', 'position', 'job_title', 'position_name'] as $key) {
            if (isset($raw[$key]) && !is_string($raw[$key])) { throw new RuntimeException('Invalid source text type.'); }
        }
        if ($kind === 'student' && $mapped['year_level'] !== null && ($mapped['year_level'] < 1 || $mapped['year_level'] > 255)) {
            throw new RuntimeException('Invalid year level.');
        }
        foreach (['level', 'year_level', 'yearLevel'] as $key) {
            if (isset($raw[$key]) && !(is_int($raw[$key]) || (is_string($raw[$key]) && preg_match('/^[0-9]{1,3}$/D', $raw[$key])))) {
                throw new RuntimeException('Invalid year level.');
            }
        }
        foreach (['employment_status', 'employmentStatus', 'status'] as $key) {
            if ($kind === 'employee' && array_key_exists($key, $raw) && $mapped['employment_status'] === null) {
                throw new RuntimeException('Invalid employment status.');
            }
        }
        foreach (['is_teaching', 'isTeaching', 'teaching', 'is_faculty'] as $key) {
            if ($kind === 'employee' && array_key_exists($key, $raw) && $mapped['is_teaching'] === null) {
                throw new RuntimeException('Invalid teaching status.');
            }
        }
    }

    private function saved(bool $saved): void
    {
        if (!$saved) { throw new RuntimeException('Directory write failed.'); }
    }

    private function progress(?callable $observer, int $percent, string $message): void
    {
        try { if ($observer !== null) { $observer(compact('percent', 'message')); } } catch (Throwable) {}
    }
}
