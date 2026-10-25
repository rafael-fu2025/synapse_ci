<?php

declare(strict_types=1);

namespace App\Services\Directory;

use App\Integrations\Mis\MisClientInterface;
use App\Services\Audit\AuditService;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;
use RuntimeException;
use Throwable;

/** Reference catalogs only: search endpoints do not establish an exhaustive directory contract. */
final class MisSyncService
{
    private const TABLES = [
        'departments' => 'mis_departments',
        'programs' => 'mis_programs',
        'year_levels' => 'mis_year_levels',
    ];

    public function __construct(private ?MisClientInterface $client = null, private ?BaseConnection $db = null)
    {
    }

    public function actor(): array
    {
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) {
            throw new RuntimeException('Please sign in to continue.', 401);
        }
        // No superadmin bypass: a current, explicit mis.sync grant is required for every role.
        if (!in_array($actor['role'], ['superadmin', 'clinic_admin', 'guidance_admin'], true)
            || !in_array('mis.sync', $actor['permissions'], true)
            || $actor['tenant_id'] < 1 || CurrentTenant::id() !== $actor['tenant_id']) {
            throw new RuntimeException('MIS reference synchronization is not permitted.', 403);
        }
        return $actor;
    }

    /** Local reads only; opening this page never resolves or calls an MIS adapter. */
    public function status(): array
    {
        $actor = $this->actor();
        $db = $this->db ??= Database::connect();
        $catalogs = [];
        foreach (self::TABLES as $catalog => $table) {
            $row = $db->table($table)->select('COUNT(*) AS total, MAX(synced_at) AS last_synced_at', false)
                ->where('tenant_id', $actor['tenant_id'])->get()->getRowArray();
            $catalogs[$catalog] = ['total' => (int) $row['total'], 'last_synced_at' => $row['last_synced_at']];
        }
        $lastSync = $db->table('audit_events')->select('occurred_at, actor_user_id')
            ->where('tenant_id', $actor['tenant_id'])->where('event_type', 'mis.references.synced')
            ->orderBy('id', 'DESC')->get(1)->getRowArray();
        return compact('actor', 'catalogs', 'lastSync');
    }

    /** @param null|callable(array{percent:int,message:string}):void $progress */
    public function sync(?callable $progress = null): array
    {
        $actor = $this->actor();
        $db = $this->db ??= Database::connect();
        if ($db->transDepth !== 0 || !in_array($db->DBDriver, ['MySQLi', 'SQLite3'], true)) {
            throw new RuntimeException('MIS reference synchronization is unavailable.', 503);
        }

        try {
            $client = $this->client ??= Services::misClient();
            // HttpMisClient returns [] for upstream failures; never interpret that as an empty authoritative catalog.
            $this->progress($progress, 5, 'Fetching all departments.');
            $catalogs = ['departments' => $this->validate($client->fetchDepartments(), 'departments')];
            $this->progress($progress, 25, 'Departments validated. Fetching programs.');
            $catalogs['programs'] = $this->validate($client->fetchPrograms(), 'programs');
            $this->progress($progress, 45, 'Fetching year levels.');
            $catalogs['year_levels'] = $this->validate($client->fetchYearLevels(), 'year_levels');
            $this->progress($progress, 65, 'Validating catalog relationships.');
            $departments = array_column($catalogs['departments'], 'code');
            foreach ($catalogs['programs'] as $program) {
                if ($program['department_code'] !== null && !in_array($program['department_code'], $departments, true)) {
                    throw new RuntimeException('Incomplete reference contract.');
                }
            }
        } catch (Throwable) {
            // Neither raw responses nor transport exceptions may reach logs, audit, or the browser.
            throw new RuntimeException('MIS reference catalogs are unavailable or incomplete. No references were changed.', 503);
        }

        $audit = new AuditService(null, $db);
        $this->progress($progress, 85, 'Saving reference catalogs and auditing synchronization.');
        $db->resetTransStatus();
        if (!$db->transBegin()) {
            throw new RuntimeException('MIS reference synchronization is unavailable.', 503);
        }
        try {
            $audit->lockTenant($actor['tenant_id']);
            $fresh = $this->actor();
            if ($fresh['id'] !== $actor['id'] || $fresh['tenant_id'] !== $actor['tenant_id'] || $fresh['role'] !== $actor['role']) {
                throw new RuntimeException('Your access changed. Reload before trying again.', 403);
            }
            $syncedAt = date('Y-m-d H:i:s');
            $counts = [];
            foreach (self::TABLES as $catalog => $table) {
                $counts[$catalog] = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
                foreach ($catalogs[$catalog] as $row) {
                    $query = $db->table($table)->where('tenant_id', $fresh['tenant_id'])->where('code', $row['code']);
                    $existing = $query->get()->getRowArray();
                    $change = 'inserted';
                    if ($existing !== null) {
                        $change = 'unchanged';
                        foreach ($row as $key => $value) {
                            if ($existing[$key] !== $value) { $change = 'updated'; break; }
                        }
                    }
                    $values = $row + ['synced_at' => $syncedAt];
                    $saved = $existing === null
                        ? $db->table($table)->insert($values + ['tenant_id' => $fresh['tenant_id']])
                        : $db->table($table)->where('tenant_id', $fresh['tenant_id'])->where('id', $existing['id'])->update($values);
                    if (!$saved) { throw new RuntimeException('Reference write failed.'); }
                    $counts[$catalog][$change]++;
                }
            }
            $audit->log('mis.references.synced', 'mis_reference_catalog', (string) $fresh['tenant_id'], 'sync', [
                'scope' => 'references_only', 'source' => 'mis', 'counts' => $counts,
                'synced_at' => $syncedAt, 'deletion_policy' => 'retain_missing',
            ], $fresh['id'], $fresh['role']);
            if (!$db->transStatus() || !$db->transCommit()) {
                throw new RuntimeException('Reference commit failed.');
            }
        } catch (Throwable $error) {
            $db->transRollback();
            $db->resetTransStatus();
            if ($error instanceof RuntimeException && in_array($error->getCode(), [401, 403], true)) {
                throw new RuntimeException('Your access changed. Reload before trying again.', 403);
            }
            throw new RuntimeException('MIS reference synchronization could not be committed. No references were changed.', 503);
        }
        $this->progress($progress, 100, 'MIS reference catalogs synchronized.');
        return compact('counts', 'syncedAt');
    }

    private function progress(?callable $observer, int $percent, string $message): void
    {
        try {
            if ($observer !== null) { $observer(['percent' => $percent, 'message' => $message]); }
        } catch (Throwable) {
            // Best-effort observers must never affect synchronization or expose their errors.
        }
    }

    private function validate(array $rows, string $catalog): array
    {
        if ($rows === [] || !array_is_list($rows) || count($rows) > 10000) {
            throw new RuntimeException('Incomplete reference contract.');
        }
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new RuntimeException('Invalid reference row.'); }
            $code = $this->text($row['code'] ?? null, 50);
            // Reject case-folded collisions as production collations may be case insensitive.
            $key = mb_strtolower($code, 'UTF-8');
            if (isset($seen[$key])) { throw new RuntimeException('Duplicate reference code.'); }
            $seen[$key] = true;
            $value = ['code' => $code, 'name' => $this->text($row['name'] ?? null, $catalog === 'year_levels' ? 100 : 150)];
            if ($catalog === 'programs') {
                $value['department_code'] = array_key_exists('department_code', $row)
                    ? $this->text($row['department_code'], 50) : null;
            }
            $result[] = $value;
        }
        return $result;
    }

    private function text(mixed $value, int $max): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || trim($value) === ''
            || trim($value) !== $value || mb_strlen($value, 'UTF-8') > $max || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new RuntimeException('Invalid reference field.');
        }
        return $value;
    }
}
