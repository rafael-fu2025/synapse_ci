<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Audit\AuditService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class AnnouncementService
{
    private BaseConnection $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** Never accept actor, tenant, role or department authority from a form. */
    public function manager(string $unit): array
    {
        if (!in_array($unit, ['clinic', 'guidance'], true)) {
            throw new RuntimeException('Invalid announcement department.', 422);
        }
        $actor = EngagementAccess::actor($unit . '.announcements.manage', $unit);
        $roles = $unit === 'clinic' ? ['superadmin', 'clinic_admin'] : ['superadmin', 'guidance_admin', 'guidance_supervisor'];
        if (!in_array($actor['role'], $roles, true)) {
            throw new RuntimeException('Department administrator access is required.', 403);
        }
        return $actor;
    }

    public function listing(string $unit, mixed $page = 1): array
    {
        $actor = $this->manager($unit);
        return $this->paginate($this->db->table('announcements')->select('id,unit,title,body,audience,target_year_levels,target_student_categories,status,starts_at,ends_at,published_at,version')
            ->where('tenant_id', $actor['tenant_id'])->where('unit', $unit), $page);
    }

    public function reads(int $id, string $unit, mixed $page = 1): array
    {
        $actor = $this->manager($unit);
        $row = $this->record($id, $actor, $unit);
        $result = $this->paginate($this->db->table('announcements_reads r')
            ->join('people p', 'p.tenant_id = r.tenant_id AND p.id = r.person_id')
            ->where('r.tenant_id', $actor['tenant_id'])->where('r.announcement_id', $id)
            ->select('r.id,r.read_at,p.first_name,p.last_name'), $page, 'r');
        return ['announcement' => $row, 'reads' => $result['announcements'], 'pagination' => $result['pagination']];
    }

    public function draft(int $id, string $unit): array
    {
        $actor = $this->manager($unit);
        $row = $this->record($id, $actor, $unit);
        $this->requireDraft($row);
        return $row;
    }

    public function create(string $unit, array $input): array
    {
        return $this->transaction(function () use ($unit, $input): array {
            $actor = $this->manager($unit);
            $data = $this->validate($input);
            $now = gmdate('Y-m-d H:i:s');
            $this->must($this->db->table('announcements')->insert($data + [
                'tenant_id' => $actor['tenant_id'], 'unit' => $unit, 'status' => 'draft',
                'created_by' => $actor['id'], 'created_at' => $now, 'updated_at' => $now, 'version' => 1,
            ]));
            $row = $this->record((int) $this->db->insertID(), $actor, $unit);
            $this->audit('created', $row, $actor);
            return $row;
        });
    }

    public function update(int $id, string $unit, array $input): array
    {
        return $this->transaction(function () use ($id, $unit, $input): array {
            $actor = $this->manager($unit);
            $row = $this->record($id, $actor, $unit, true);
            $this->requireDraft($row);
            $this->version($row, $input['version'] ?? null);
            $data = $this->validate($input);
            $this->must($this->db->table('announcements')->where('tenant_id', $actor['tenant_id'])->where('id', $id)
                ->update($data + ['version' => (int) $row['version'] + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]));
            $row = $this->record($id, $actor, $unit);
            $this->audit('updated', $row, $actor);
            return $row;
        });
    }

    public function publish(int $id, string $unit, mixed $version): array
    {
        return $this->transition($id, $unit, $version, 'published');
    }

    public function archive(int $id, string $unit, mixed $version): array
    {
        return $this->transition($id, $unit, $version, 'archived');
    }

    private function transition(int $id, string $unit, mixed $version, string $status): array
    {
        return $this->transaction(function () use ($id, $unit, $version, $status): array {
            $actor = $this->manager($unit);
            $row = $this->record($id, $actor, $unit, true);
            $this->version($row, $version);
            if ($status === 'published') {
                $this->requireDraft($row);
                $this->validate($row, true);
                if ($row['ends_at'] !== null && $row['ends_at'] <= gmdate('Y-m-d H:i:s')) {
                    throw new RuntimeException('An expired announcement cannot be published.', 422);
                }
            } elseif (!in_array($row['status'], ['draft', 'published'], true)) {
                throw new RuntimeException('Announcement is already archived.', 409);
            }
            $now = gmdate('Y-m-d H:i:s');
            $changes = ['status' => $status, 'updated_at' => $now, 'version' => (int) $row['version'] + 1];
            if ($status === 'published') { $changes['published_at'] = $now; }
            $this->must($this->db->table('announcements')->where('tenant_id', $actor['tenant_id'])->where('id', $id)->update($changes));
            $row = $this->record($id, $actor, $unit);
            if ($status === 'published') {
                EngagementNotifications::publish('announcement', $id, $actor);
            }
            $this->audit($status, $row, $actor);
            return $row;
        });
    }

    public function portalListing(mixed $page = 1, array $query = []): array
    {
        $actor = EngagementAccess::actor();
        $filters = PortalContent::filters(['page' => $page] + $query, ['all', 'unread', 'read'], ['clinic', 'guidance']);
        $builder = $this->visible($actor)->select('a.id,a.unit,a.title,a.audience,a.published_at,a.ends_at,r.read_at')
            ->select('SUBSTR(a.body, 1, 400) AS excerpt', false);
        $this->withRead($builder, $actor);
        if ($filters['unit'] !== 'all') $builder->where('a.unit', $filters['unit']);
        if ($filters['q'] !== '') $builder->groupStart()->like('a.title', $filters['q'])->orLike('a.body', $filters['q'])->groupEnd();
        $counts = ['all' => (clone $builder)->countAllResults(),
            'unread' => (clone $builder)->where('r.read_at', null)->countAllResults(),
            'read' => (clone $builder)->where('r.read_at IS NOT NULL', null, false)->countAllResults()];
        if ($filters['status'] === 'unread') $builder->where('r.read_at', null);
        if ($filters['status'] === 'read') $builder->where('r.read_at IS NOT NULL', null, false);
        $result = PortalContent::page($builder, $filters, 'a.id');
        return ['announcements' => $result['items'], 'pagination' => $result['pagination'], 'filters' => $filters, 'counts' => $counts];
    }

    public function show(int $id): array
    {
        $actor = EngagementAccess::actor();
        return $this->portalRecord($id, $actor);
    }

    /** Read means read, not acknowledgement or proof of comprehension. */
    public function read(int $id): array
    {
        return $this->transaction(function () use ($id): array {
            $actor = EngagementAccess::actor();
            if (empty($actor['person_id'])) { throw new RuntimeException('A person profile is required to mark a notice read.', 403); }
            $this->record($id, $actor, null, true);
            $row = $this->portalRecord($id, $actor);
            if ($row['read_at'] === null) {
                $this->must($this->db->table('announcements_reads')->insert([
                    'tenant_id' => $actor['tenant_id'], 'announcement_id' => $id,
                    'person_id' => $actor['person_id'], 'read_at' => gmdate('Y-m-d H:i:s'),
                ]));
                $this->audit('read', $row, $actor);
            }
            return $this->portalRecord($id, $actor);
        });
    }

    private function visible(array $actor): BaseBuilder
    {
        $now = gmdate('Y-m-d H:i:s');
        $builder = $this->db->table('announcements a')->where('a.tenant_id', $actor['tenant_id'])
            ->whereIn('a.unit', ['clinic', 'guidance'])->where('a.status', 'published')->where('a.published_at IS NOT NULL', null, false)
            ->groupStart()->where('a.starts_at', null)->orWhere('a.starts_at <=', $now)->groupEnd()
            ->groupStart()->where('a.ends_at', null)->orWhere('a.ends_at >', $now)->groupEnd();
        return EngagementAudience::apply($builder, $actor, 'a');
    }

    private function withRead(BaseBuilder $builder, array $actor): void
    {
        $person = (int) ($actor['person_id'] ?? 0);
        $builder->join('announcements_reads r', 'r.tenant_id = a.tenant_id AND r.announcement_id = a.id AND r.person_id = ' . $person, 'left');
    }

    private function portalRecord(int $id, array $actor): array
    {
        $builder = $this->visible($actor)->select('a.*,r.read_at')->where('a.id', $id);
        $this->withRead($builder, $actor);
        $row = $builder->get()->getRowArray();
        if ($row === null) { throw new RuntimeException('Announcement not found.', 404); }
        return $row;
    }

    private function record(int $id, array $actor, ?string $unit = null, bool $lock = false): array
    {
        $builder = $this->db->table('announcements')->where('tenant_id', $actor['tenant_id'])->where('id', $id);
        if ($unit !== null) { $builder->where('unit', $unit); }
        $row = $lock ? $this->db->query($builder->getCompiledSelect() . ' FOR UPDATE')->getRowArray() : $builder->get()->getRowArray();
        if ($row === null) { throw new RuntimeException('Announcement not found.', 404); }
        return $row;
    }

    private function paginate(BaseBuilder $builder, mixed $page, string $alias = ''): array
    {
        if ((!is_string($page) && !is_int($page)) || filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) === false) {
            throw new RuntimeException('Invalid page.', 422);
        }
        $total = (clone $builder)->countAllResults();
        $pages = max(1, (int) ceil($total / 25));
        $page = min((int) $page, $pages);
        $prefix = $alias === '' ? '' : $alias . '.';
        return ['announcements' => $builder->orderBy($prefix . 'id', 'DESC')->limit(25, ($page - 1) * 25)->get()->getResultArray(),
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'per_page' => 25]];
    }

    /** Form values are Manila wall times; publication revalidates already-stored UTC. */
    private function validate(array $input, bool $stored = false): array
    {
        $data = [];
        foreach (['title' => 160, 'body' => 10000] as $field => $limit) {
            $value = $input[$field] ?? null;
            if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen(trim($value)) > $limit
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw new RuntimeException(ucfirst($field) . ' must be plain text, between 1 and ' . $limit . ' characters.', 422);
            }
            $data[$field] = trim($value);
        }
        $data['audience'] = EngagementAudience::validate($input['audience'] ?? null);
        if ($stored) {
            // Only stored rows contain JSON; form submissions must supply arrays.
            foreach (['target_year_levels', 'target_student_categories'] as $field) {
                $value = $input[$field] ?? null;
                if ($value === null) { unset($input[$field]); continue; }
                if (!is_string($value)) { throw new RuntimeException('Invalid stored student targeting.', 422); }
                try { $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR); }
                catch (\JsonException $e) { throw new RuntimeException('Invalid stored student targeting.', 422, $e); }
                if (!is_array($decoded)) { throw new RuntimeException('Invalid stored student targeting.', 422); }
                $input[$field] = $decoded;
            }
        }
        $data += EngagementAudience::targeting($input, $data['audience']);
        foreach (['starts_at', 'ends_at'] as $field) {
            $value = $input[$field] ?? null;
            if ($value === '' || $value === null) { $data[$field] = null; continue; }
            if (!is_string($value)) { throw new RuntimeException('Invalid announcement date.', 422); }
            $format = $stored ? 'Y-m-d H:i:s' : 'Y-m-d\TH:i';
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone($stored ? 'UTC' : 'Asia/Manila'));
            if ($date === false || $date->format($format) !== $value || (!$stored && (int) $date->format('Y') < 1970)) {
                throw new RuntimeException('Use a valid announcement date and time (Asia/Manila).', 422);
            }
            $data[$field] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if ($data['starts_at'] !== null && $data['ends_at'] !== null && $data['ends_at'] <= $data['starts_at']) {
            throw new RuntimeException('Expiry must be after the start time.', 422);
        }
        return $data;
    }

    private function version(array $row, mixed $version): void
    {
        if ((!is_string($version) && !is_int($version)) || !ctype_digit((string) $version) || (string) $row['version'] !== (string) $version) {
            throw new RuntimeException('Announcement changed. Reload before trying again.', 409);
        }
    }

    private function requireDraft(array $row): void
    {
        if ($row['status'] !== 'draft') { throw new RuntimeException('Only drafts can be edited or published. Published announcements are immutable.', 409); }
    }

    private function audit(string $action, array $row, array $actor): void
    {
        (new AuditService())->log('announcement.' . $action, 'announcement', (string) $row['id'], $action,
            ['unit' => $row['unit'], 'status' => $row['status'], 'version' => (int) $row['version']], (int) $actor['id'], $actor['role']);
    }

    private function must(bool $success): void
    {
        if (!$success) { throw new RuntimeException('Announcement could not be saved.', 503); }
    }

    private function transaction(callable $operation): array
    {
        // Refuse caller-owned transactions: never commit (or roll back) another workflow.
        if ($this->db->transDepth !== 0) { throw new RuntimeException('Announcement operations require an independent transaction.', 409); }
        $actor = EngagementAccess::actor();
        $this->db->resetTransStatus();
        if (!$this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') || !$this->db->transBegin()) {
            throw new RuntimeException('Announcement transaction could not begin.', 503);
        }
        try {
            // Lock before business inserts/FK checks, not by upgrading their shared tenant locks.
            (new AuditService(null, $this->db))->lockTenant((int) $actor['tenant_id']);
            $result = $operation();
            if (!$this->db->transStatus() || !$this->db->transCommit()) { throw new RuntimeException('Announcement transaction failed.', 503); }
            return $result;
        } catch (Throwable $e) {
            try { $this->db->transRollback(); }
            finally { $this->db->resetTransStatus(); }
            throw $e;
        }
    }
}
