<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Services\Audit\AuditService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class OperationalExportService
{
    public function export(array $input): array
    {
        return $this->buildExport($input, 'csv');
    }

    public function exportPdf(array $input): array
    {
        return $this->buildExport($input, 'pdf');
    }

    private function buildExport(array $input, string $format): array
    {
        $actor = ExportAccess::actor();
        $units = ExportAccess::selectUnits($actor, $input['unit'] ?? null);
        $range = ExportRange::fromInput($input);
        $db = Database::connect();
        $rows = $this->aggregate($db, (int) $actor['tenant_id'], $range, config('App')->appTimezone, $units);
        $clinicInsights = $format === 'pdf' && in_array('clinic', $units, true)
            ? ClinicReportInsights::collect($db, (int) $actor['tenant_id'], $range, config('App')->appTimezone)
            : null;
        $content = $format === 'pdf'
            ? OperationalReportPdf::encode($rows, $range->start->format('Y-m-d'), $range->end->format('Y-m-d'), $clinicInsights)
            : ExportCsv::encode($rows);
        // Audit must succeed before any response body is released. Never include export content.
        (new AuditService())->log('reports.operational.exported', 'reports', 'operational', 'exported',
            $range->metadata() + ['units' => $units, 'row_count' => count($rows) - 1, 'format' => $format], $actor['id'], $actor['role']);
        $extension = $format === 'pdf' ? 'pdf' : 'csv';
        return [$format => $content, 'content' => $content, 'format' => $format,
            'filename' => 'operational_' . (count($units) === 3 ? 'all' : $units[0]) . '_'
                . $range->start->format('Y-m-d') . '_' . $range->end->format('Y-m-d') . '.' . $extension];
    }

    public function aggregate(BaseConnection $db, int $tenantId, ExportRange $range, string $storageTimezone, array $units): array
    {
        $rows = [['date_manila', 'unit', 'metric', 'count']];
        for ($day = $range->start; $day <= $range->end; $day = $day->modify('+1 day')) {
            $daily = ExportRange::fromInput(['from' => $day->format('Y-m-d'), 'to' => $day->format('Y-m-d')]);
            [$from, $until] = $daily->bounds($storageTimezone);
            // Only aggregate counts reach PHP; no notes, diagnoses, names or person-level rows.
            if (in_array('clinic', $units, true)) {
                $clinic = $db->table('clinic_encounters')->where('tenant_id', $tenantId)->where('status', 'completed')
                    ->where('started_at >=', $from)->where('started_at <', $until)->countAllResults();
                $rows[] = [$day->format('Y-m-d'), 'clinic', 'completed_clinic_encounters_started', $clinic];
            }
            if (in_array('guidance', $units, true)) {
                $guidance = $db->table('counselling_sessions s')
                    ->join('counselling_cases c', 'c.id = s.case_id AND c.tenant_id = s.tenant_id')
                    ->where('s.tenant_id', $tenantId)->where('s.status', 'completed')
                    ->where('s.started_at >=', $from)->where('s.started_at <', $until)->countAllResults();
                $rows[] = [$day->format('Y-m-d'), 'guidance', 'completed_guidance_sessions_started', $guidance];
            }
            if (in_array('bmg', $units, true)) {
                $started = $db->table('bmg_batches')->where('tenant_id', $tenantId)
                    ->where('started_at >=', $from)->where('started_at <', $until)->countAllResults();
                $rows[] = [$day->format('Y-m-d'), 'bmg', 'bmg_batches_started', $started];
                // Both terminal transitions use completed_at in BmgService; not sensor or quality evidence.
                foreach (['released', 'voided'] as $status) {
                    $count = $db->table('bmg_batches')->where('tenant_id', $tenantId)->where('status', $status)
                        ->where('completed_at >=', $from)->where('completed_at <', $until)->countAllResults();
                    $rows[] = [$day->format('Y-m-d'), 'bmg', 'bmg_batches_' . $status, $count];
                }
            }
        }
        return $rows;
    }
}
