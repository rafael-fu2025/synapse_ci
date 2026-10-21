<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\PortalContext;
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
        $today = date('Y-m-d');

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
        $days = [];
        $clinicSeries = [];
        $guidanceSeries = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $days[] = date('M j', strtotime($d));
            $clinicSeries[] = $db->table('clinic_encounters')
                ->where('tenant_id', $tenantId)->where('DATE(started_at)', $d)->countAllResults();
            $guidanceSeries[] = $db->table('counselling_sessions')
                ->where('tenant_id', $tenantId)->where('DATE(started_at)', $d)->countAllResults();
        }

        return view('dashboard/index', [
            'title'                  => $audit['canReadAudit'] ? 'Executive Admin Dashboard' : 'Reports Dashboard',
            'pageTitle'              => $audit['canReadAudit'] ? 'Executive Operations Dashboard' : 'Operations Reports Dashboard',
            'canReadAudit'           => $audit['canReadAudit'],
            'user'                   => $user,
            'totalPopulation'        => $totalPopulation,
            'studentsCount'          => $studentsCount,
            'employeesCount'         => $employeesCount,
            'totalOperationsVolume'  => $totalOperationsVolume,
            'totalClinicEncounters'  => $totalClinicEncounters,
            'totalGuidanceSessions'  => $totalGuidanceSessions,
            'totalAuditEvents'       => $audit['totalAuditEvents'],
            'activeAccounts'         => $activeAccounts,
            'recentAudit'            => $audit['recentAudit'],
            'chartDays'              => $days,
            'clinicSeries'           => $clinicSeries,
            'guidanceSeries'         => $guidanceSeries,
            'breadcrumbs'            => [
                $audit['canReadAudit']
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
