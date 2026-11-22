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

        $rangeParam = strtolower((string) ($this->request->getGet('range') ?? '7d'));
        $rangeKey = $rangeParam === '30d' ? '30d' : '7d';

        // The audit sections are permission-gated, so the discriminator keeps a
        // reports-only viewer from ever being served the cached audit dataset.
        $metrics = DashboardCache::remember('admin.dashboard.' . ($canReadAudit ? '1' : '0'), DashboardCache::TTL,
            function () use ($db, $user, $tenantId, $canReadAudit): array {
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

                // 4. Active Accounts & Privileged Role Spread
                $activeAccounts = $db->table('users')
                    ->where('tenant_id', $tenantId)->where('status', 'active')->where('deleted_at', null)->countAllResults();
                $inactiveAccounts = $db->table('users')
                    ->where('tenant_id', $tenantId)->where('status !=', 'active')->where('deleted_at', null)->countAllResults();

                $privilegedRoles = [];
                $privilegedAccounts = 0;
                $roleRows = $db->table('users')
                    ->select('role, COUNT(*) AS total')
                    ->where('tenant_id', $tenantId)->where('status', 'active')->where('deleted_at', null)
                    ->groupBy('role')
                    ->orderBy('total', 'DESC')
                    ->get()->getResultArray();
                foreach ($roleRows as $row) {
                    $rName = (string) ($row['role'] ?? 'unknown');
                    $rCount = (int) ($row['total'] ?? 0);
                    if (!in_array($rName, ['student', 'employee'], true)) {
                        $privilegedRoles[$rName] = $rCount;
                        $privilegedAccounts += $rCount;
                    }
                }

                // 5. Real Operational & Security Diagnostics (audit/admin readers only)
                $nowUtc = gmdate('Y-m-d H:i:s');
                $nowTs = time();
                $fifteenMinAgo = gmdate('Y-m-d H:i:s', $nowTs - 900);
                $sevenDaysAgo = gmdate('Y-m-d H:i:s', $nowTs - 604800);
                $today = SchedulingClock::today();

                $diagnostics = [];
                if ($canReadAudit) {
                    $activeApiTokens = $db->tableExists('api_tokens')
                        ? (int) $db->table('api_tokens')
                            ->where('tenant_id', $tenantId)
                            ->where('revoked_at', null)
                            ->where('refresh_expires_at >', $nowUtc)
                            ->countAllResults()
                        : 0;

                    $throttledBuckets = $db->tableExists('login_attempt_buckets')
                        ? (int) $db->table('login_attempt_buckets')
                            ->where('expires_at >', $nowTs)
                            ->where('attempts >=', 5)
                            ->countAllResults()
                        : 0;

                    $misCatalogTotal = 0;
                    $misLastSyncedAt = null;
                    foreach (['mis_departments', 'mis_programs', 'mis_year_levels'] as $misTable) {
                        if (!$db->tableExists($misTable)) {
                            continue;
                        }
                        $row = $db->table($misTable)
                            ->select('COUNT(*) AS total, MAX(synced_at) AS last_synced_at', false)
                            ->where('tenant_id', $tenantId)
                            ->get()->getRowArray();
                        $misCatalogTotal += (int) ($row['total'] ?? 0);
                        $syncedAt = $row['last_synced_at'] ?? null;
                        if (is_string($syncedAt) && $syncedAt !== '' && ($misLastSyncedAt === null || $syncedAt > $misLastSyncedAt)) {
                            $misLastSyncedAt = $syncedAt;
                        }
                    }

                    $bmgUnitsTotal = $db->tableExists('bmg_units')
                        ? (int) $db->table('bmg_units')
                            ->where('tenant_id', $tenantId)->where('deleted_at', null)->countAllResults()
                        : 0;
                    $bmgDevicesBound = 0;
                    $bmgDevicesOnline = 0;
                    if ($db->tableExists('bmg_devices')) {
                        $bmgDevicesBound = (int) $db->table('bmg_devices')
                            ->where('tenant_id', $tenantId)->where('status', 'active')->countAllResults();
                        $bmgDevicesOnline = (int) $db->table('bmg_devices')
                            ->where('tenant_id', $tenantId)->where('status', 'active')
                            ->where('last_seen_at >=', $fifteenMinAgo)->countAllResults();
                    }
                    $bmgFailedCommands7d = $db->tableExists('bmg_rotation_commands')
                        ? (int) $db->table('bmg_rotation_commands')
                            ->where('tenant_id', $tenantId)
                            ->whereIn('status', ['failed', 'rejected', 'expired'])
                            ->where('created_at >=', $sevenDaysAgo)
                            ->countAllResults()
                        : 0;

                    $docJobsFailed = 0;
                    $docJobsPending = 0;
                    if ($db->tableExists('guidance_document_jobs')) {
                        $docJobsFailed = (int) $db->table('guidance_document_jobs')
                            ->where('tenant_id', $tenantId)->where('status', 'failed')->countAllResults();
                        $docJobsPending = (int) $db->table('guidance_document_jobs')
                            ->where('tenant_id', $tenantId)->whereIn('status', ['pending', 'queued', 'processing'])->countAllResults();
                    }

                    $gatesTotal = 0;
                    $gatesEnabled = 0;
                    if ($db->tableExists('guidance_activation_gates')) {
                        $gatesTotal = (int) $db->table('guidance_activation_gates')
                            ->where('tenant_id', $tenantId)->countAllResults();
                        $gatesEnabled = (int) $db->table('guidance_activation_gates')
                            ->where('tenant_id', $tenantId)->where('is_enabled', 1)->countAllResults();
                    }

                    $openReferrals = $db->tableExists('referrals')
                        ? (int) $db->table('referrals')
                            ->where('tenant_id', $tenantId)
                            ->whereIn('status', ['submitted', 'acknowledged'])->countAllResults()
                        : 0;

                    $todayActiveQueue = $db->tableExists('queue_entries')
                        ? (int) $db->table('queue_entries')
                            ->where('tenant_id', $tenantId)->where('queue_date', $today)
                            ->whereIn('status', ['waiting', 'called', 'in_consultation'])->countAllResults()
                        : 0;

                    $diagnostics = [
                        'activeApiTokens'     => $activeApiTokens,
                        'throttledBuckets'    => $throttledBuckets,
                        'misCatalogTotal'     => $misCatalogTotal,
                        'misLastSyncedAt'     => $misLastSyncedAt,
                        'bmgUnitsTotal'       => $bmgUnitsTotal,
                        'bmgDevicesBound'     => $bmgDevicesBound,
                        'bmgDevicesOnline'    => $bmgDevicesOnline,
                        'bmgFailedCommands7d' => $bmgFailedCommands7d,
                        'docJobsFailed'       => $docJobsFailed,
                        'docJobsPending'      => $docJobsPending,
                        'gatesTotal'          => $gatesTotal,
                        'gatesEnabled'        => $gatesEnabled,
                        'openReferrals'       => $openReferrals,
                        'todayActiveQueue'    => $todayActiveQueue,
                    ];
                }

                // 6. Cross-department volume velocity (30d bounded UTC read, sliced for 7d client-side switching)
                // One bounded range read per series, bucketed on the Manila business day in PHP:
                // started_at / occurred_at is stored as a UTC instant, so the Manila day is a UTC range
                // and a range predicate keeps the index usable.
                $window7Count = count(SchedulingClock::businessDayRanges(7));
                $ranges30 = SchedulingClock::businessDayRanges(30);
                $days30 = array_map(static fn (string $businessDay): string => date('M j', strtotime($businessDay)), array_keys($ranges30));
                $windowStart30 = $ranges30[array_key_first($ranges30)][0];
                $windowEnd30 = $ranges30[array_key_last($ranges30)][1];

                $clinic30 = array_fill(0, count($ranges30), 0);
                foreach ($db->table('clinic_encounters')
                    ->where('tenant_id', $tenantId)
                    ->where('started_at >=', $windowStart30)->where('started_at <', $windowEnd30)
                    ->select('started_at')->get()->getResultArray() as $encounterRow) {
                    $bucket = SchedulingClock::businessDayIndex((string) $encounterRow['started_at'], $ranges30);
                    if ($bucket !== null) {
                        $clinic30[$bucket]++;
                    }
                }
                $guidance30 = array_fill(0, count($ranges30), 0);
                foreach ($db->table('counselling_sessions')
                    ->where('tenant_id', $tenantId)
                    ->where('started_at >=', $windowStart30)->where('started_at <', $windowEnd30)
                    ->select('started_at')->get()->getResultArray() as $sessionRow) {
                    $bucket = SchedulingClock::businessDayIndex((string) $sessionRow['started_at'], $ranges30);
                    if ($bucket !== null) {
                        $guidance30[$bucket]++;
                    }
                }
                $bmg30 = array_fill(0, count($ranges30), 0);
                if ($db->tableExists('bmg_rotation_runs')) {
                    foreach ($db->table('bmg_rotation_runs')
                        ->where('tenant_id', $tenantId)
                        ->where('occurred_at >=', $windowStart30)->where('occurred_at <', $windowEnd30)
                        ->select('occurred_at')->get()->getResultArray() as $runRow) {
                        $bucket = SchedulingClock::businessDayIndex((string) $runRow['occurred_at'], $ranges30);
                        if ($bucket !== null) {
                            $bmg30[$bucket]++;
                        }
                    }
                }

                // 7d slice + prior 7d comparison directly from the 30d buckets (zero extra queries)
                $days7 = array_slice($days30, -$window7Count);
                $clinic7 = array_slice($clinic30, -$window7Count);
                $guidance7 = array_slice($guidance30, -$window7Count);
                $bmg7 = array_slice($bmg30, -$window7Count);
                $prior7Offset = max(0, count($ranges30) - ($window7Count * 2));
                $prior7Total = array_sum(array_slice($clinic30, $prior7Offset, $window7Count))
                    + array_sum(array_slice($guidance30, $prior7Offset, $window7Count))
                    + array_sum(array_slice($bmg30, $prior7Offset, $window7Count));

                // Prior 30d comparison for 30-day period-over-period delta
                $priorStart30 = gmdate('Y-m-d H:i:s', strtotime($windowStart30 . ' UTC') - (30 * 86400));
                $prior30Total = (int) $db->table('clinic_encounters')
                        ->where('tenant_id', $tenantId)
                        ->where('started_at >=', $priorStart30)->where('started_at <', $windowStart30)
                        ->countAllResults()
                    + (int) $db->table('counselling_sessions')
                        ->where('tenant_id', $tenantId)
                        ->where('started_at >=', $priorStart30)->where('started_at <', $windowStart30)
                        ->countAllResults();
                if ($db->tableExists('bmg_rotation_runs')) {
                    $prior30Total += (int) $db->table('bmg_rotation_runs')
                        ->where('tenant_id', $tenantId)
                        ->where('occurred_at >=', $priorStart30)->where('occurred_at <', $windowStart30)
                        ->countAllResults();
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
                    'inactiveAccounts'      => $inactiveAccounts,
                    'privilegedAccounts'    => $privilegedAccounts,
                    'privilegedRoles'       => $privilegedRoles,
                    'diagnostics'           => $diagnostics,
                    'recentAudit'           => $audit['recentAudit'],
                    'chartDays'             => $days7,
                    'clinicSeries'          => $clinic7,
                    'guidanceSeries'        => $guidance7,
                    'bmgSeries'             => $bmg7,
                    'priorPeriodTotal'      => $prior7Total,
                    'opsWindows'            => [
                        '7d' => [
                            'days'       => $days7,
                            'clinic'     => $clinic7,
                            'guidance'   => $guidance7,
                            'bmg'        => $bmg7,
                            'priorTotal' => $prior7Total,
                        ],
                        '30d' => [
                            'days'       => $days30,
                            'clinic'     => $clinic30,
                            'guidance'   => $guidance30,
                            'bmg'        => $bmg30,
                            'priorTotal' => $prior30Total,
                        ],
                    ],
                ];
            });

        return view('dashboard/index', $metrics + [
            'rangeKey'               => $rangeKey,
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
