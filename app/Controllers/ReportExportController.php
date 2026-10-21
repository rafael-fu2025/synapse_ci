<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Reporting\ExportAccess;
use App\Services\Reporting\ExportCsv;
use App\Services\Reporting\ExportPdf;
use App\Services\Reporting\OperationalExportService;
use App\Services\Scheduling\SchedulingClock;
use RuntimeException;
use Throwable;

final class ReportExportController extends BaseController
{
    public function index()
    {
        try {
            $actor = ExportAccess::actor();
            $today = SchedulingClock::now();
            $rangeStart = $today->modify('-6 days')->format('Y-m-d');
            $rangeEnd = $today->format('Y-m-d');
            $this->response->setHeader('Cache-Control', 'private, no-store');
            return view('reports/export', ['title' => 'Operational Report Exports', 'units' => ExportAccess::allowedUnits($actor),
                'from' => $rangeStart, 'to' => $rangeEnd,
                'fromDisplay' => \DateTimeImmutable::createFromFormat('!Y-m-d', $rangeStart, new \DateTimeZone('Asia/Manila'))->format('d/m/Y'),
                'toDisplay' => \DateTimeImmutable::createFromFormat('!Y-m-d', $rangeEnd, new \DateTimeZone('Asia/Manila'))->format('d/m/Y')]);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function operational()
    {
        try {
            $format = $this->request->getPost('format') ?? 'csv';
            if (!is_string($format) || !in_array($format, ['csv', 'pdf'], true)) {
                throw new RuntimeException('Choose CSV or PDF format.', 422);
            }
            $service = new OperationalExportService();
            $export = $format === 'pdf' ? $service->exportPdf($this->request->getPost()) : $service->export($this->request->getPost());
            $this->response->setHeader('X-Content-Type-Options', 'nosniff');
            return $format === 'pdf'
                ? ExportPdf::response($this->response, $export['content'], $export['filename'])
                : ExportCsv::response($this->response, $export['content'], $export['filename']);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    private function failure(Throwable $e)
    {
        $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 422], true) ? $e->getCode() : 503;
        // Do not log exception messages/traces: drivers may embed query data or credentials.
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')
            ->setJSON(['message' => $status === 503 ? 'Export unavailable. No download was produced.' : $e->getMessage()]);
    }
}
