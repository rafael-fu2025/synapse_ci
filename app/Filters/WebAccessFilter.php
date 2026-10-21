<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\AuthenticatedUser;
use App\Services\PortalContext;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use RuntimeException;

/** Defense at the HTTP boundary for legacy handlers; domain services keep their own guards. */
final class WebAccessFilter implements FilterInterface
{
    private array $actor;

    public function before(RequestInterface $request, $arguments = null)
    {
        $path = preg_replace('~^index\.php/?~', '', trim($request->getUri()->getPath(), '/'));
        if (is_file(WRITEPATH . 'bmg-maintenance.flag') && preg_match('~^facilities(?:/|$)~', $path)) {
            return service('response')->setStatusCode(503)->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('Retry-After', '60')->setJSON(['message' => 'Drum operations are temporarily unavailable for maintenance.']);
        }
        // No expiry: failed/partial DDL must remain closed until explicitly verified.
        if (is_file(WRITEPATH . 'scheduling-maintenance.flag')
            && (preg_match('~^(clinic|guidance)/(appointments|queue|referrals|announcements|surveys)(?:/|$)~', $path)
                || preg_match('~^portal/(appointments|referrals|announcements|surveys)(?:/|$)~', $path)
                || preg_match('~^(referrals|notifications)(?:/|$)~', $path)
                || $path === 'api/appointments/slots')) {
            return service('response')->setStatusCode(503)->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('Retry-After', '60')->setJSON([
                    'message' => 'Scheduling, care coordination, and campus content are temporarily unavailable for maintenance. Please try again later.',
                ]);
        }

        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) return AuthenticatedUser::denied($request, false, 'Please sign in to continue.', '/auth/login');
        $this->actor = $actor;
        $write = strtoupper($request->getMethod()) !== 'GET';
        try {
            if (in_array($path, ['portal', 'portal/appointments', 'portal/records'], true)) {
                // Every alias renders the same combined page, so gate all datasets together.
                $this->permit('portal.appointments.read');
                $this->permit('portal.records.read');
            }
            if (preg_match('~^portal/appointments/(?:clinic|guidance)/(?:create|store|cancel/\\d+)$~', $path)) {
                $this->permit('portal.appointments.create');
            }
            if (str_starts_with($path, 'admin/audit')) $this->permit('audit.read');
            if (str_starts_with($path, 'reports/export')) {
                if (!PortalContext::isRolePermitted($actor['role'], 'main')) throw new RuntimeException('Reports access forbidden.', 403);
                $this->permit('reports.read');
                $this->permit('reports.export');
            }
            if (preg_match('~^(clinic|guidance)(?:/(dashboard|queue|appointments|patients|encounters|inventory|cases|sessions|followups))?(?:/|$)~', $path, $match)) {
                $unit = $match[1];
                $area = $match[2] ?? null;
                if (!PortalContext::isRolePermitted($actor['role'], $unit)) throw new RuntimeException('Department access forbidden.', 403);
                if ($area === 'dashboard' || $path === $unit) {
                    // These legacy dashboards query each dataset before rendering it.
                    $permissions = $unit === 'clinic'
                        ? ['clinic.queue.read', 'clinic.encounters.read', 'clinic.appointments.read', 'clinic.inventory.read']
                        : ['guidance.cases.read', 'guidance.queue.read', 'referrals.read'];
                    foreach ($permissions as $permission) $this->permit($permission);
                } elseif ($area !== null) {
                    $permission = match ($area) {
                        'queue' => $unit . '.queue.' . ($write ? 'manage' : 'read'),
                        'patients' => 'clinic.patients.' . ($write ? 'write' : 'read'),
                        'encounters' => 'clinic.encounters.' . (str_contains($path, '/start-') ? 'create' : ($write ? 'write' : 'read')),
                        'inventory' => 'clinic.inventory.' . ($write ? 'write' : 'read'),
                        'appointments' => $unit === 'clinic' ? 'clinic.appointments.' . ($write || str_ends_with($path, '/create') ? 'write' : 'read') : 'guidance.schedule.' . ($write || str_ends_with($path, '/create') ? 'manage' : 'read'),
                        default => 'guidance.cases.' . (str_ends_with($path, '/create') || str_ends_with($path, '/store') || str_ends_with($path, '/session') || str_contains($path, '/start-for-ticket/') || str_contains($path, '/choose-for-ticket/') ? 'create' : ($write ? 'write' : 'read')),
                    };
                    $this->permit($permission);
                }
            }
            if (str_starts_with($path, 'facilities')) {
                if (!PortalContext::isRolePermitted($actor['role'], 'bmg')) throw new RuntimeException('Facilities access forbidden.', 403);
                if (!preg_match('~^facilities/teams(?:/|$)~', $path)) {
                    $this->permit($write ? 'facilities.manage' : 'facilities.read');
                }
            }
            $this->records($path, $request, $write);
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'Permission required: ')) {
                return AuthenticatedUser::denied($request, true, $e->getMessage(), '/portal');
            }
            if ($e->getMessage() === 'Department access forbidden.' || $e->getMessage() === 'Facilities access forbidden.' || $e->getMessage() === 'Reports access forbidden.') {
                return AuthenticatedUser::denied($request, true, $e->getMessage(), '/portal');
            }
            return service('response')->setStatusCode($e->getCode() ?: 403)->setHeader('Cache-Control', 'private, no-store')
                ->setJSON(['message' => $e->getMessage()]);
        }
    }

    private function permit(string $permission): void
    {
        if ($this->actor['role'] !== 'superadmin' && !in_array($permission, $this->actor['permissions'], true)) {
            throw new RuntimeException('Permission required: ' . $permission, 403);
        }
    }

    private function record(string $table, int $id, bool $softDelete = false): array
    {
        $builder = Database::connect()->table($table)->where('tenant_id', $this->actor['tenant_id'])->where('id', $id);
        if ($softDelete) $builder->where('deleted_at', null);
        $row = $builder->get()->getRowArray();
        if ($row === null) throw new RuntimeException('Record not found.', 404);
        return $row;
    }

    private function caseAccess(int $id): array
    {
        $case = $this->record('counselling_cases', $id, true);
        $this->record('people', (int) $case['person_id'], true);
        if ($this->actor['role'] !== 'superadmin' && (int) $case['counsellor_user_id'] !== $this->actor['id']
            && !in_array('guidance.cases.read_any', $this->actor['permissions'], true)) {
            throw new RuntimeException('This case is assigned to another counselor.', 403);
        }
        return $case;
    }

    private function ticket(int $id, string $unit): array
    {
        $row = $this->record('queue_entries', $id);
        if ($row['unit'] !== $unit) throw new RuntimeException('Queue ticket not found.', 404);
        $this->record('people', (int) $row['person_id'], true);
        return $row;
    }

    private function records(string $path, RequestInterface $request, bool $write): void
    {
        if (!$write && str_contains($path, '/start-for-ticket/')) return;
        if (preg_match('~^(clinic|guidance)/queue/(?:call|start|complete|skip|recall|no-show)/(\d+)$~', $path, $m)) $this->ticket((int) $m[2], $m[1]);
        if (preg_match('~^guidance/cases/choose-for-ticket/(\d+)$~', $path, $m)) {
            $ticket = $this->ticket((int) $m[1], 'guidance');
            if ($ticket['queue_date'] !== SchedulingClock::today() || !in_array($ticket['status'], ['called', 'in_consultation'], true)) {
                throw new RuntimeException('The queue ticket is not ready for a counseling session.', 409);
            }
            $case = Database::connect()->table('counselling_cases')->where('tenant_id', $this->actor['tenant_id'])
                ->where('person_id', $ticket['person_id'])->where('deleted_at', null)->whereIn('status', ['open','active','monitoring'])->get()->getRowArray();
            if ($case !== null) $this->caseAccess((int) $case['id']);
        }
        if (preg_match('~^(clinic|guidance)/(?:encounters|cases)/start-for-ticket/(\d+)$~', $path, $m)) {
            $ticket = $this->ticket((int) $m[2], $m[1]);
            if ($ticket['queue_date'] !== SchedulingClock::today() || !in_array($ticket['status'], ['waiting', 'called', 'in_consultation'], true)) {
                throw new RuntimeException('The queue ticket is not active today.', 409);
            }
            if ($m[1] === 'guidance') {
                $case = Database::connect()->table('counselling_cases')->where('tenant_id', $this->actor['tenant_id'])
                    ->where('person_id', $ticket['person_id'])->where('deleted_at', null)->whereIn('status', ['open','active','monitoring'])->get()->getRowArray();
                if ($case !== null) $this->caseAccess((int) $case['id']);
            }
        }
        if (preg_match('~^clinic/(?:patients|encounters/start-direct)/(\d+)(?:/health)?$~', $path, $m)) $this->record('people', (int) $m[1], true);
        if (preg_match('~^clinic/encounters/(\d+)(?:/|$)~', $path, $m)) {
            $encounter = $this->record('clinic_encounters', (int) $m[1], true);
            $this->record('people', (int) $encounter['person_id'], true);
            if ($write && $encounter['status'] !== 'in_progress') throw new RuntimeException('Encounter is no longer open.', 409);
        }
        if (preg_match('~^guidance/cases/(\d+)(?:/|$)~', $path, $m)) $this->caseAccess((int) $m[1]);
        if (preg_match('~^guidance/sessions/(\d+)(?:/|$)~', $path, $m)) {
            $session = $this->record('counselling_sessions', (int) $m[1], true);
            $this->caseAccess((int) $session['case_id']);
            if ($write && $session['status'] !== 'in_progress') throw new RuntimeException('Counseling session is no longer open.', 409);
        }
        if (preg_match('~^guidance/followups/(\d+)(?:/|$)~', $path, $m)) {
            $followup = $this->record('guidance_followups', (int) $m[1]);
            $this->caseAccess((int) $followup['case_id']);
        }
        if (preg_match('~^facilities/(unit|batch)/(\d+)(?:/|$)~', $path, $m)) {
            if ($m[1] === 'unit') $this->record('bmg_units', (int) $m[2]);
            else {
                $batch = $this->record('bmg_batches', (int) $m[2]);
                $this->record('bmg_units', (int) $batch['unit_id']);
            }
        }
        if ($write && (str_ends_with($path, '/walkin') || $path === 'guidance/cases/store')) {
            $person = $request->getPost('person_id');
            if ((!is_string($person) && !is_int($person)) || !preg_match('/\A[1-9][0-9]*\z/D', (string) $person)) {
                throw new RuntimeException('A valid person ID is required.', 422);
            }
            $this->record('people', (int) $person, true);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'private, no-store');
    }
}
