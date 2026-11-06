<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Source contracts only: never construct controllers, services, or database connections. */
final class DashboardStatusContractTest extends TestCase
{
    public function testCompletedClinicQueueUsesDoneAndRetainsItsScope(): void
    {
        $query = $this->assignment('ClinicController', 'queueCompleted');

        $this->assertStringContainsString("->table('queue_entries')", $query);
        $this->assertStringContainsString("->where('tenant_id', \$tenantId)", $query);
        $this->assertStringContainsString("->where('unit', 'clinic')", $query);
        $this->assertStringContainsString("->where('queue_date', \$today)", $query);
        $this->assertStringContainsString("->where('status', 'done')->countAllResults()", $query);
        $this->assertStringNotContainsString("'completed'", $query);
    }

    public function testClinicTriageCountsOnlyActualPriorities(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Controllers/ClinicController.php');
        $this->assertNotFalse($source);
        $start = strpos($source, '$triageCounts = ');
        $end = strpos($source, '$recentEncounters = ', $start === false ? 0 : $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $counts = substr($source, $start, $end - $start);

        // Today's active queue only: the same cohort as the waiting/called KPIs beside it.
        $this->assertStringContainsString("->where('tenant_id', \$tenantId)", $counts);
        $this->assertStringContainsString("->where('unit', 'clinic')", $counts);
        $this->assertStringContainsString("->where('queue_date', \$today)", $counts);
        $this->assertStringContainsString("->whereIn('status', ['waiting', 'called', 'in_consultation'])", $counts);
        $this->assertStringContainsString("->groupBy('priority')", $counts);

        // One grouped read replaces one query per priority.
        $this->assertSame(1, substr_count($counts, '->table('));
        $this->assertSame(0, substr_count($counts, 'countAllResults()'));

        // A served, skipped or no-show ticket is never part of the live triage mix.
        foreach (['done', 'skipped', 'no_show'] as $terminal) {
            $this->assertStringNotContainsString("'{$terminal}'", $counts);
        }
        $this->assertStringNotContainsString("'priority' =>", $counts);
        $this->assertStringNotContainsString("->where('priority', 'priority')", $counts);
    }

    public function testGuidancePendingReferralsUseSubmittedAndAcknowledgedOnly(): void
    {
        $query = $this->assignment('GuidanceController', 'pendingReferrals');

        $this->assertStringContainsString("->table('referrals')", $query);
        $this->assertStringContainsString("->where('tenant_id', \$tenantId)", $query);
        $this->assertStringContainsString("->where('target_module', 'guidance')", $query);
        $this->assertStringContainsString("->whereIn('status', ['submitted', 'acknowledged'])->countAllResults()", $query);
        $this->assertStringNotContainsString("'pending'", $query);
    }

    public function testEncounterAndSessionCompletionStatusesRemainUnchanged(): void
    {
        $this->assertStringContainsString(
            "->where('status', 'completed')->countAllResults()",
            $this->assignment('ClinicController', 'encountersCount')
        );
        $this->assertStringContainsString(
            "->where('counselling_sessions.status', 'completed')->countAllResults()",
            $this->assignment('GuidanceController', 'completedSessions')
        );
    }

    public function testSchedulingCountsUseSchedulingClockAndAppointmentLocalDate(): void
    {
        foreach (['ClinicController', 'GuidanceController'] as $controller) {
            $source = file_get_contents(__DIR__ . '/../../app/Controllers/' . $controller . '.php');
            $this->assertNotFalse($source);
            $this->assertStringContainsString('use App\\Services\\Scheduling\\SchedulingClock;', $source);
            $this->assertSame('$today = SchedulingClock::today();', $this->assignment($controller, 'today'));
            foreach (['queueWaiting', 'queueCalled'] as $variable) {
                $query = $this->assignment($controller, $variable);
                $this->assertStringContainsString("->where('queue_date', \$today)", $query);
                $this->assertStringContainsString("->where('tenant_id', \$tenantId)", $query);
                $unit = $controller === 'ClinicController' ? 'clinic' : 'guidance';
                $this->assertStringContainsString("->where('unit', '{$unit}')", $query);
            }
        }

        $query = $this->assignment('ClinicController', 'appointmentsToday');
        $this->assertStringContainsString("->table('appointments')", $query);
        $this->assertStringContainsString("->where('tenant_id', \$tenantId)", $query);
        $this->assertStringContainsString("->where('unit', 'clinic')", $query);
        $this->assertStringContainsString("->where('local_date', \$today)->countAllResults()", $query);
        $this->assertStringNotContainsString('DATE(starts_at)', $query);
    }

    public function testClinicViewHasThreeMatchingTriageBuckets(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Views/clinic/dashboard.php');
        $this->assertNotFalse($source);
        $this->assertStringNotContainsString("\$triageCounts['priority']", $source);
        $this->assertStringNotContainsString('<span>Priority</span>', $source);
        $this->assertStringContainsString("labels: ['Emergency', 'Urgent', 'Routine']", $source);
        $this->assertStringContainsString("colors: ChartTheme.themedSlices(t, ChartTheme.statusPalette(t).slice(0, 3))", $source);
        $this->assertStringContainsString('if (totalTriage === 0) triageData = [0, 0, 1];', $source);
        $matched = preg_match('/var triageData = \\[(.*?)\\];/s', $source, $matches);
        $this->assertSame(1, $matched);
        preg_match_all('/\\$triageCounts\\[\'([^\']+)\'\\]/', $matches[1], $keys);
        $this->assertSame(['emergency', 'urgent', 'routine'], $keys[1]);
        foreach ($keys[1] as $key) {
            $this->assertSame(2, substr_count($source, "\$triageCounts['{$key}']"));
        }
    }

    public function testEncounterAndSessionSeriesUseScopedTenantAndBoundedDayRanges(): void
    {
        foreach (['ClinicController' => 'dailyEncounters', 'GuidanceController' => 'dailySessions'] as $controller => $variable) {
            $source = file_get_contents(__DIR__ . '/../../app/Controllers/' . $controller . '.php');
            $this->assertNotFalse($source);
            $start = strpos($source, '$ranges = ');
            $end = strpos($source, 'return view(', $start === false ? 0 : $start);
            $this->assertNotFalse($start);
            $this->assertNotFalse($end);
            $series = substr($source, $start, $end - $start);
            $this->assertStringContainsString('$' . $variable . ' = ', $series);

            // Manila business-day boundaries, resolved once through the clock.
            $this->assertStringContainsString('SchedulingClock::businessDayRanges(7)', $series);
            $this->assertStringContainsString('SchedulingClock::businessDayIndex(', $series);

            // A range predicate keeps the index usable; a DATE() comparison cannot.
            $this->assertStringNotContainsString('DATE(started_at)', $series);
            $this->assertStringNotContainsString('DATE(created_at)', $series);

            if ($controller === 'ClinicController') {
                $this->assertStringContainsString("->where('tenant_id', \$tenantId)", $series);
                $this->assertStringContainsString("->where('started_at >=', \$ranges[", $series);
                $this->assertStringContainsString("->where('started_at <', \$ranges[", $series);
            } else {
                // The session series inherits the tenant scope from the case builder it clones.
                $this->assertStringContainsString("->where('counselling_cases.tenant_id', \$tenantId)", $source);
                $this->assertStringContainsString("->where('counselling_sessions.started_at >=', \$ranges[", $series);
                $this->assertStringContainsString("->where('counselling_sessions.started_at <', \$ranges[", $series);
            }

            // One bounded read per series replaces one query per day.
            $this->assertSame(0, substr_count($series, 'countAllResults()'));
            if ($controller === 'ClinicController') {
                $this->assertSame(1, substr_count($series, '->table('));
            } else {
                // The session series reuses the prepared case builder it clones.
                $this->assertSame(0, substr_count($series, '->table('));
                $this->assertSame(1, substr_count($series, '(clone $sessions)'));
            }
        }
    }

    public function testAdminAndFacilitiesSeriesUseBoundedUtcRanges(): void
    {
        $admin = file_get_contents(__DIR__ . '/../../app/Controllers/DashboardController.php');
        $this->assertNotFalse($admin);
        $this->assertStringContainsString('SchedulingClock::businessDayRanges(7)', $admin);
        $this->assertStringContainsString('SchedulingClock::businessDayIndex(', $admin);
        $this->assertStringNotContainsString('DATE(started_at)', $admin);
        $this->assertStringNotContainsString("date('Y-m-d', strtotime(\"", $admin);

        $facilities = file_get_contents(__DIR__ . '/../../app/Controllers/FacilitiesController.php');
        $this->assertNotFalse($facilities);
        $this->assertStringContainsString('SchedulingClock::businessDayRanges(7)', $facilities);
        $this->assertStringContainsString('SchedulingClock::businessDayIndex(', $facilities);
        $this->assertStringNotContainsString('DATE(created_at)', $facilities);
    }

    private function assignment(string $controller, string $variable): string
    {
        $source = file_get_contents(__DIR__ . '/../../app/Controllers/' . $controller . '.php');
        $this->assertNotFalse($source);
        $matched = preg_match('/\$' . preg_quote($variable, '/') . '\s*=\s*[^;]+;/', $source, $matches);
        $this->assertSame(1, $matched, 'Dashboard assignment not found: ' . $variable);

        return (string) preg_replace('/\s+/', ' ', $matches[0]);
    }
}
