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
        return (new HybridOperationalAggregateReader())->aggregate($db, $tenantId, $range, $storageTimezone, $units);
    }
}
