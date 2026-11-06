<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\DashboardCache;
use App\Services\PortalContext;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Database\BaseConnection;

final class DashboardController extends BaseController
{
    public function index()
    {
        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            return AuthenticatedUser::denied($this->request, false, 'Please sign in to continue.', '/auth/login');
        }
        $role = (string) ($user['role'] ?? 'student');
        $host = PortalContext::hostPortal();
        if (!PortalContext::compatibleHost($role)) return PortalContext::accessNotice($role);
        if (($host !== null && $host !== PortalContext::PORTAL_MAIN)
            || PortalContext::preferredPortal($role) !== PortalContext::PORTAL_MAIN
            || in_array($role, ['student', 'employee'], true)) {
            return redirect()->to(PortalContext::landing($role))->setHeader('Cache-Control', 'private, no-store');
        }

        if (!$this->canRead($user, 'reports.read') && !$this->canRead($user, 'audit.read')) {
            return AuthenticatedUser::denied($this->request, true, 'You do not have permission to access that resource.', '/');
        }

        return $this->adminDashboard($user);
    }

    public function admin()
    {
        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            return AuthenticatedUser::denied($this->request, false, 'Please sign in to continue.', '/auth/login');
        }
        if (!$this->canRead($user, 'audit.read')) {
            return AuthenticatedUser::denied($this->request, true, 'You do not have permission to access that resource.', '/');
        }

        return $this->adminDashboard($user);
    }

    private function adminDashboard(array $user)
    {
        $tenantId = \App\Services\CurrentTenant::id();
        $db = \Config\Database::connect();
        $canReadAudit = $this->canRead($user, 'audit.read');

        // The audit sections are permission-gated, so the discriminator keeps a
        // reports-only viewer from ever being served the cached audit dataset.
        $metrics = DashboardCache::remember('admin.dashboard.' . ($canReadAudit ? '1' : '0'), DashboardCache::TTL,
            function () use ($db, $user, $tenantId): array {
                // 1. Campus Directory Population
                $studentsCount = $db->table('people')
                    ->where('tenant_id', $tenantId)->where('kind', 'student')->where('deleted_at', null)->countAllResults();
                $employeesCount = $db->table('people')
                    ->where('tenant_id', $tenantId)->where('kind', 'employee')->where('deleted_at', null)->countAllResults();
                $totalPopulation = $studentsCount + $employeesCount;

                // 2. Cross-Department Care Volume
                $totalClinicEncounters = $db->table('clinic_encounters')
                    ->where('tenant_id', $tenantId)->where('status', 'completed')->countAllResults();
                $totalGuidanceSessions = $db->table('counselling_sessions s')
                    ->join('counselling_cases c', 'c.id = s.case_id AND c.tenant_id = s.tenant_id')
                    ->where('s.tenant_id', $tenantId)->where('s.status', 'completed')->countAllResults();
                $totalOperationsVolume = $totalClinicEncounters + $totalGuidanceSessions;

                // Audit access gates the queries as well as the rendered sections.
                $audit = $this->auditSummary($db, $user, $tenantId);

                // 4. Active Accounts & Roles
                $activeAccounts = $db->table('users')
                    ->where('tenant_id', $tenantId)->where('status', 'active')->where('deleted_at', null)->countAllResults();

                // 6. Cross-department volume velocity (last 7 days)
                // One bounded range read per series, bucketed on the Manila business day in PHP:
                // started_at is stored as a UTC instant, so the Manila day is a UTC range and a
                // range predicate keeps the index usable.
                $ranges = SchedulingClock::businessDayRanges(7);
                $days = array_map(static fn (string $businessDay): string => date('M j', strtotime($businessDay)), array_keys($ranges));
                $windowStart = $ranges[array_key_first($ranges)][0];
                $windowEnd = $ranges[array_key_last($ranges)][1];
                $clinicSeries = array_fill(0, count($ranges), 0);
                foreach ($db->table('clinic_encounters')
                    ->where('tenant_id', $tenantId)
                    ->where('started_at >=', $windowStart)->where('started_at <', $windowEnd)
                    ->select('started_at')->get()->getResultArray() as $encounterRow) {
                    $bucket = SchedulingClock::businessDayIndex((string) $encounterRow['started_at'], $ranges);
                    if ($bucket !== null) {
                        $clinicSeries[$bucket]++;
                    }
                }
                $guidanceSeries = array_fill(0, count($ranges), 0);
                foreach ($db->table('counselling_sessions')
                    ->where('tenant_id', $tenantId)
                    ->where('started_at >=', $windowStart)->where('started_at <', $windowEnd)
                    ->select('started_at')->get()->getResultArray() as $sessionRow) {
                    $bucket = SchedulingClock::businessDayIndex((string) $sessionRow['started_at'], $ranges);
                    if ($bucket !== null) {
                        $guidanceSeries[$bucket]++;
                    }
                }

                return [
                    'totalPopulation'       => $totalPopulation,
                    'studentsCount'         => $studentsCount,
                    'employeesCount'        => $employeesCount,
                    'totalOperationsVolume' => $totalOperationsVolume,
                    'totalClinicEncounters' => $totalClinicEncounters,
                    'totalGuidanceSessions' => $totalGuidanceSessions,
                    'totalAuditEvents'      => $audit['totalAuditEvents'],
                    'activeAccounts'        => $activeAccounts,
                    'recentAudit'           => $audit['recentAudit'],
                    'chartDays'             => $days,
                    'clinicSeries'          => $clinicSeries,
                    'guidanceSeries'        => $guidanceSeries,
                ];
            });

        return view('dashboard/index', $metrics + [
            'title'                  => $canReadAudit ? 'Executive Admin Dashboard' : 'Reports Dashboard',
            'pageTitle'              => $canReadAudit ? 'Executive Operations Dashboard' : 'Operations Reports Dashboard',
            'canReadAudit'           => $canReadAudit,
            'user'                   => $user,
            'breadcrumbs'            => [
                $canReadAudit
                                    ? ['label' => 'Administration', 'url' => site_url('/admin')]
                                    : ['label' => 'Reports', 'url' => site_url('/dashboard')],
                ['label' => 'Dashboard', 'url' => ''],
            ],
        ]);
    }

    private function canRead(array $user, string $permission): bool
    {
        return $user['role'] === 'superadmin' || in_array($permission, $user['permissions'], true);
    }

    private function auditSummary(BaseConnection $db, array $user, int $tenantId): array
    {
        if (!$this->canRead($user, 'audit.read')) {
            return ['canReadAudit' => false, 'totalAuditEvents' => null, 'recentAudit' => []];
        }

        $totalAuditEvents = $db->table('audit_events')
            ->where('tenant_id', $tenantId)->countAllResults();
        $recentAudit = $db->table('audit_events a')
            ->join('users u', 'u.id = a.actor_user_id AND u.tenant_id = a.tenant_id', 'left')
            ->where('a.tenant_id', $tenantId)
            ->select('a.id, a.event_type, a.entity_type, a.entity_id, a.action, a.occurred_at, u.username as actor_name')
            ->orderBy('a.id', 'DESC')->limit(6)->get()->getResultArray();

        return ['canReadAudit' => true, 'totalAuditEvents' => $totalAuditEvents, 'recentAudit' => $recentAudit];
    }
}
