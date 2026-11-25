<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\AuthenticatedUser;
use App\Services\DashboardAccess;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\PortalContext;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DomainException;
use RuntimeException;

/** Defense at the HTTP boundary for legacy handlers; domain services keep their own guards. */
final class WebAccessFilter implements FilterInterface
{
    private array $actor;

    public function before(RequestInterface $request, $arguments = null)
    {
        $path = trim($request->getUri()->getRoutePath(), '/');
        if (is_file(WRITEPATH . 'bmg-maintenance.flag') && preg_match('~^facilities(?:/|$)~', $path)) {
            return service('response')->setStatusCode(503)->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('Retry-After', '60')->setJSON(['message' => 'Drum operations are temporarily unavailable for maintenance.']);
        }
        // No expiry: failed/partial DDL must remain closed until explicitly verified.
        if (is_file(WRITEPATH . 'scheduling-maintenance.flag')
            && (preg_match('~^(clinic|guidance)/(appointments|queue|referrals|announcements|surveys)(?:/|$)~', $path)
                || preg_match('~^portal/(appointments|referrals|announcements|surveys)(?:/|$)~', $path)
                || preg_match('~^(referrals|notifications)(?:/|$)~', $path)
                || $path === 'api/appointments/slots' || $path === 'portal/queue'
                                || preg_match('~^(clinic|guidance)/dashboard/queue-counts$~', $path))) {
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
            if (in_array($path, ['portal', 'portal/appointments', 'portal/records', 'portal/queue'], true)) {
                // Every alias renders the same combined page, so gate all datasets together.
                $this->permit('portal.appointments.read');
                $this->permit('portal.records.read');
            }
            if ($path === 'portal/visits') $this->permit('portal.records.read');
            if (preg_match('~^portal/appointments/\d+$~', $path)) {
                $this->permit('portal.appointments.read');
            }
            if (preg_match('~^portal/appointments/(?:clinic|guidance)/(?:create|store|cancel/\\d+)$~', $path)) {
                $this->permit('portal.appointments.create');
            }
            if (str_starts_with($path, 'admin/audit')) $this->permit('audit.read');
            if ($path === 'admin/interns' || str_starts_with($path, 'admin/interns/')) {
                if (!PortalContext::isRolePermitted($actor['role'], 'main')
                    || !in_array($actor['role'], ['superadmin', 'clinic_admin', 'guidance_admin', 'bmg_admin'], true)) {
                    throw new RuntimeException('Student intern management is not permitted.', 403);
                }
                $this->permit('interns.manage');
            }
            if ($path === 'admin/archive' || str_starts_with($path, 'admin/archive/')) {
                if (!PortalContext::isRolePermitted($actor['role'], 'main')) {
                    throw new RuntimeException('Archive access forbidden.', 403);
                }
                if (!in_array($actor['role'], \App\Services\Archive\ArchiveAccessPolicy::ROLES_MAIN, true)) {
                    throw new RuntimeException('Archive governance access is not permitted for your role.', 403);
                }
                if (str_starts_with($path, 'admin/archive/holds/')) {
                    $this->permit('archive.legal_hold.manage');
                } elseif (str_starts_with($path, 'admin/archive/policies/')) {
                    $this->permit('archive.policies.manage');
                } elseif (str_starts_with($path, 'admin/archive/records/')) {
                    $this->permit('archive.manage');
                } else {
                    $this->permit('archive.read');
                }
            }
            if (preg_match('~^(?:clinic/isolation|guidance/episodes)/\d+/(?:archive|restore)$~', $path)
                || preg_match('~^(?:clinic|guidance|facilities)/teams/(?:calendar/events/)?\d+/(?:archive|restore)$~', $path)) {
                $this->permit('archive.manage');
            }
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
                    foreach (DashboardAccess::permissions($unit) as $permission) $this->permit($permission);
                } elseif ($area !== null) {
                    $permission = match ($area) {
                        'queue' => $unit . '.queue.' . ($write ? 'manage' : 'read'),
                        'patients' => str_ends_with($path, '/clearances')
                            ? 'clinic.clearances.write'
                            : (str_ends_with($path, '/labs') ? 'clinic.encounters.addendum' : 'clinic.patients.' . ($write ? 'write' : 'read')),
                        'encounters' => preg_match('~/(?:archive|restore)$~', $path)
                            ? 'archive.manage'
                            : (str_ends_with($path, '/addendum')
                                ? 'clinic.encounters.addendum'
                                : 'clinic.encounters.' . (str_contains($path, '/start-') ? 'create' : (str_ends_with($path, '/complete') ? 'seal' : ($write ? 'write' : 'read')))),
                        'inventory' => preg_match('~/(?:archive|restore)$~', $path)
                            ? 'archive.manage'
                            : 'clinic.inventory.' . ($write ? 'write' : 'read'),
                        'appointments' => $unit === 'clinic' ? 'clinic.appointments.' . ($write || str_ends_with($path, '/create') ? 'write' : 'read') : 'guidance.schedule.' . ($write || str_ends_with($path, '/create') ? 'manage' : 'read'),
                        default => preg_match('~^guidance/cases/\d+/(?:archive|restore)$~', $path)
                            ? 'archive.manage'
                            : 'guidance.cases.' . (str_ends_with($path, '/create') || str_ends_with($path, '/store') || str_ends_with($path, '/session') || str_contains($path, '/start-for-ticket/') || str_contains($path, '/choose-for-ticket/') ? 'create' : ($write ? 'write' : 'read')),
                    };
                    $this->permit($permission);
                }
            }
            // Client registry and longitudinal records are read-only Guidance data,
            // including the legacy cumulative URL. Reload the grant even for superadmin.
            if (!$write && preg_match('~^guidance/(?:clients(?:/\d+)?|students/\d+/cumulative)$~D', $path)) {
                $this->actor = (new GuidanceAccessPolicy(db: Database::connect()))
                    ->authorize($this->actor, 'guidance.cases.read');
            }
            if (str_starts_with($path, 'facilities')) {
                if (!PortalContext::isRolePermitted($actor['role'], 'bmg')) throw new RuntimeException('Facilities access forbidden.', 403);
                // AI POST actions own their scoped capabilities; generation also checks source read.
                $aiAction = strtoupper($request->getMethod()) === 'POST'
                    && preg_match('~^facilities/insights/(?:prepare|generate|recover|[0-9]+/review|jobs/[0-9]+/(?:cancel|retry))$~D', $path);
                if (!preg_match('~^facilities/teams(?:/|$)~', $path) && !$aiAction) {
                    $this->permit($write ? 'facilities.manage' : 'facilities.read');
                }
            }
            $this->records($path, $request, $write);
        } catch (DomainException | RuntimeException $e) {
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

    private function assertNotInternSelfRecord(int $subjectPersonId): void
    {
        if (in_array($this->actor['role'] ?? '', ['clinic_intern', 'guidance_intern', 'bmg_intern'], true)
            && !empty($this->actor['person_id'])
            && $subjectPersonId === (int) $this->actor['person_id']) {
            throw new RuntimeException('Student interns cannot access or modify their own clinical or guidance records on staff workstations. Use My Health Portal instead.', 403);
        }
    }

    private function caseAccess(int $id, bool $write = false): array
    {
        $case = $this->record('counselling_cases', $id, true);
        $this->record('people', (int) $case['person_id'], true);
        $this->assertNotInternSelfRecord((int) $case['person_id']);
        if (!$write) {
            // Use the same authoritative rule as the cumulative record's workspace links.
            // Consultation/oversight reads never grant mutation authority or skip service audits.
            $this->actor = (new GuidanceAccessPolicy(
                identitySource: static fn (): ?array => (new AuthenticatedUser())->current(),
                db: Database::connect()
            ))->readCase($case, $this->actor);
        } elseif ((int) $case['counsellor_user_id'] !== $this->actor['id']) {
            throw new RuntimeException('This case is assigned to another counselor.', 403);
        }
        return $case;
    }

    private function ticket(int $id, string $unit): array
    {
        $row = $this->record('queue_entries', $id);
        if ($row['unit'] !== $unit) throw new RuntimeException('Queue ticket not found.', 404);
        $this->record('people', (int) $row['person_id'], true);
        $this->assertNotInternSelfRecord((int) $row['person_id']);
        return $row;
    }

    private function records(string $path, RequestInterface $request, bool $write): void
    {
        if (!$write && preg_match('~^guidance/(?:clients/(\d+)|students/(\d+)/cumulative)$~D', $path, $m)) {
            $personId = (int) ($m[1] !== '' ? $m[1] : $m[2]);
            $this->record('people', $personId, true);
            $this->assertNotInternSelfRecord($personId);
        }
        if (!$write && str_contains($path, '/start-for-ticket/')) return;
        if (preg_match('~^(clinic|guidance)/queue/(?:call|start|complete|skip|recall|no-show)/(\d+)$~', $path, $m)) $this->ticket((int) $m[2], $m[1]);
        if (preg_match('~^guidance/cases/choose-for-ticket/(\d+)$~', $path, $m)) {
            $ticket = $this->ticket((int) $m[1], 'guidance');
            if ($ticket['queue_date'] !== SchedulingClock::today() || !in_array($ticket['status'], ['called', 'in_consultation'], true)) {
                throw new RuntimeException('The queue ticket is not ready for a counseling session.', 409);
            }
            $case = Database::connect()->table('counselling_cases')->where('tenant_id', $this->actor['tenant_id'])
                ->where('person_id', $ticket['person_id'])->where('deleted_at', null)->whereIn('status', ['open','active','monitoring'])->get()->getRowArray();
            if ($case !== null) $this->caseAccess((int) $case['id'], $write);
        }
        if (preg_match('~^(clinic|guidance)/(?:encounters|cases)/start-for-ticket/(\d+)$~', $path, $m)) {
            $ticket = $this->ticket((int) $m[2], $m[1]);
            if ($ticket['queue_date'] !== SchedulingClock::today() || !in_array($ticket['status'], ['waiting', 'called', 'in_consultation'], true)) {
                throw new RuntimeException('The queue ticket is not active today.', 409);
            }
            if ($m[1] === 'guidance') {
                $case = Database::connect()->table('counselling_cases')->where('tenant_id', $this->actor['tenant_id'])
                    ->where('person_id', $ticket['person_id'])->where('deleted_at', null)->whereIn('status', ['open','active','monitoring'])->get()->getRowArray();
                if ($case !== null) $this->caseAccess((int) $case['id'], $write);
            }
        }
        if (preg_match('~^clinic/(?:patients|encounters/start-direct)/(\d+)(?:/(?:health|labs|clearances))?$~', $path, $m)) {
            $this->record('people', (int) $m[1], true);
            $this->assertNotInternSelfRecord((int) $m[1]);
        }
        if (preg_match('~^clinic/encounters/(\d+)/(archive|restore)$~', $path, $m)) {
            $encounter = $this->record('clinic_encounters', (int) $m[1], false);
            $this->record('people', (int) $encounter['person_id'], false);
            $this->assertNotInternSelfRecord((int) $encounter['person_id']);
        } elseif (preg_match('~^clinic/encounters/(\d+)(?:/|$)~', $path, $m)) {
            $encounter = $this->record('clinic_encounters', (int) $m[1], true);
            $this->record('people', (int) $encounter['person_id'], true);
            $this->assertNotInternSelfRecord((int) $encounter['person_id']);
            if ($write && !str_ends_with($path, '/addendum') && $encounter['status'] !== 'in_progress') {
                throw new RuntimeException('Encounter is no longer open.', 409);
            }
        }
        if (preg_match('~^guidance/cases/(\d+)/(archive|restore)$~', $path, $m)) {
            $case = $this->record('counselling_cases', (int) $m[1], false);
            $this->record('people', (int) $case['person_id'], false);
        } elseif (preg_match('~^guidance/cases/(\d+)(?:/|$)~', $path, $m)) {
            $this->caseAccess((int) $m[1], $write);
        }
        if (preg_match('~^guidance/sessions/(\d+)(?:/|$)~', $path, $m)) {
            $session = $this->record('counselling_sessions', (int) $m[1], true);
            $this->caseAccess((int) $session['case_id'], $write);
            if ($write && $session['status'] !== 'in_progress') throw new RuntimeException('Counseling session is no longer open.', 409);
        }
        if (preg_match('~^guidance/followups/(\d+)(?:/|$)~', $path, $m)) {
            $followup = $this->record('guidance_followups', (int) $m[1]);
            $this->caseAccess((int) $followup['case_id'], $write);
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
            $this->assertNotInternSelfRecord((int) $person);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'private, no-store');
    }
}
