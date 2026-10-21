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
        $counts = $this->assignment('ClinicController', 'triageCounts');

        foreach (['emergency', 'urgent', 'routine'] as $priority) {
            $this->assertStringContainsString(
                "'{$priority}' => \$db->table('queue_entries')->where('tenant_id', \$tenantId)->where('unit', 'clinic')->where('priority', '{$priority}')->countAllResults()",
                $counts
            );
        }
        $this->assertSame(1, substr_count($counts, "->where('priority', 'urgent')"));
        $this->assertStringNotContainsString("'priority' =>", $counts);
        $this->assertSame(3, substr_count($counts, '->countAllResults()'));
        $this->assertStringNotContainsString("->where('priority', 'priority')", $counts);
        $this->assertStringNotContainsString("->where('queue_date'", $counts);
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
        $this->assertStringContainsString("colors: ['#dc3545', '#ffc107', '#198754']", $source);
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
            $this->assertStringContainsString('$d = date(\'Y-m-d\', strtotime("-{$i} days"));', $source);
            $start = strpos($source, '$' . $variable . ' = [];');
            $end = strpos($source, 'return view(', $start === false ? 0 : $start);
            $this->assertNotFalse($start);
            $this->assertNotFalse($end);
            $series = substr($source, $start, $end - $start);
            if ($controller === 'ClinicController') {
                $this->assertStringContainsString("->where('tenant_id', \$tenantId)", $series);
                $this->assertStringContainsString("->where('started_at >=', \$d . ' 00:00:00')", $series);
                $this->assertStringContainsString("->where('started_at <', date('Y-m-d', strtotime(\$d . ' +1 day')) . ' 00:00:00')", $series);
                $this->assertStringNotContainsString("->where('DATE(started_at)', \$d)", $series);
            } else {
                $this->assertStringContainsString("->where('counselling_cases.tenant_id', \$tenantId)", $source);
                $this->assertStringContainsString("->where('counselling_sessions.started_at >=', \$d . ' 00:00:00')", $series);
                $this->assertStringContainsString("->where('counselling_sessions.started_at <', date('Y-m-d', strtotime(\$d . ' +1 day')) . ' 00:00:00')", $series);
                $this->assertStringNotContainsString("->where('DATE(started_at)', \$d)", $series);
            }
        }
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
