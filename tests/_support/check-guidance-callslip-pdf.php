<?php

declare(strict_types=1);

namespace Config {
    final class ReportExport
    {
        public string $pdflatex = 'pdflatex';
        public bool $miktex = PHP_OS_FAMILY === 'Windows';
        public int $timeoutSeconds = 30;
    }
}

namespace App\Models {
    // In-memory issuance seam; never instantiate the real database-backed model.
    final class GuidanceDocumentIssuanceModel
    {
        public array $records = [];
        public function insert(array $record): int
        {
            $id = count($this->records) + 1;
            $this->records[$id] = ['id' => $id] + $record;
            return $id;
        }
        public function find(int $id): ?array { return $this->records[$id] ?? null; }
    }
}

namespace {
    // Actual template/compiler/service, no framework, dotenv, sessions or database.
    if (PHP_SAPI !== 'cli' || $argc !== 2) {
        throw new RuntimeException('Use the CLI with a new output directory.');
    }
    $root = dirname(__DIR__, 2);
    $output = $argv[1];
    if (file_exists($output) || !mkdir($output, 0700, true)) {
        throw new RuntimeException('The PDF check needs a new empty output directory.');
    }
    define('WRITEPATH', $output . DIRECTORY_SEPARATOR);
    $compilerConfig = new \Config\ReportExport();
    function config(string $name): \Config\ReportExport
    {
        if ($name !== \Config\ReportExport::class) {
            throw new RuntimeException('Unexpected configuration dependency.');
        }
        return $GLOBALS['compilerConfig'];
    }
    require $root . '/app/Services/Reporting/LatexReportCompiler.php';
    require $root . '/app/Services/Reporting/GuidanceFormLatex.php';
    require $root . '/app/Services/Guidance/GuidanceDocumentService.php';

    $service = (new ReflectionClass(\App\Services\Guidance\GuidanceDocumentService::class))->newInstanceWithoutConstructor();
    $data = [
        'call_slip_number' => 'SYNTHETIC-CS-0041',
        'student_name' => 'Synthetic Client',
        'student_id' => 'SYN-0041',
        'course_year' => 'BS Computer Science - 2',
        'call_date' => '2030-01-31',
        'call_time' => '09:30 AM',
        'issuing_counselor_name' => 'Synthetic Counselor',
        'purpose' => 'personal_counseling',
        'purpose_other' => '',
    ];
    foreach (['single', 'quad'] as $layout) {
        foreach (['draft', 'finalized'] as $status) {
            $snapshot = ['data' => $data, 'is_draft' => $status === 'draft'];
            if ($status === 'finalized') {
                $snapshot['verification_code'] = 'SYN-DOC-TEST-ONLY';
                $snapshot['attestation'] = [
                    'signer_role' => 'Synthetic Counselor',
                    'attested_at' => '2030-01-30 10:15:00',
                    'snapshot_hash' => str_repeat('a', 64),
                ];
            }
            $source = \App\Services\Reporting\GuidanceFormLatex::renderCallSlip($snapshot, ['layout' => $layout]);
            if (str_contains($source, 'mdframed')) {
                throw new RuntimeException('Call Slips must not depend on mdframed.');
            }
            $stem = $output . '/' . $layout . '-' . $status;
            file_put_contents($stem . '.tex', $source);
            $result = $service->renderCallSlipPdf($snapshot, ['layout' => $layout]);
            $pdf = $result['pdf'];
            if (strlen($pdf) < 1024 || !str_starts_with($pdf, '%PDF-')
                || !preg_match('/startxref\s+\d+\s+%%EOF\s*$/s', $pdf)) {
                throw new RuntimeException('Incomplete Call Slip PDF: ' . $layout . '-' . $status);
            }
            file_put_contents($stem . '.pdf', $pdf);
            fwrite(STDOUT, 'PASS ' . $layout . '-' . $status . ': ' . strlen($pdf) . " bytes\n");
        }
    }
    $issuances = new \App\Models\GuidanceDocumentIssuanceModel();
    (new ReflectionProperty($service, 'issuanceModel'))->setValue($service, $issuances);
    foreach (['single', 'quad'] as $layout) {
        $result = $service->issueDocument(['id' => 1], ['internal_key' => 'guidance_call_slip', 'form_code' => 'F-OGC-GSP-CSP-007-00'],
            ['id' => 1], $data, ['signer_role' => 'Synthetic Counselor', 'attested_at' => '2030-01-30', 'snapshot_hash' => str_repeat('a', 64)],
            ['id' => 1, 'tenant_id' => 1], ['layout' => $layout]);
        if (strlen($result['pdf']) < 1024 || !preg_match('/startxref\s+\d+\s+%%EOF\s*$/s', $result['pdf'])
            || $result['issuance']['pdf_sha256'] !== hash('sha256', $result['pdf'])) {
            throw new RuntimeException('Official print did not return a complete verified PDF.');
        }
        fwrite(STDOUT, 'PASS official-' . $layout . ": real PDF, matching hash, RAM-only issuance seam\n");
    }
    $issuedCount = count($issuances->records);
    $compilerConfig->pdflatex = 'synapse-intentionally-missing-pdflatex';
    foreach (['preview', 'official'] as $mode) {
        try {
            if ($mode === 'official') {
                $service->issueDocument(['id' => 1], ['internal_key' => 'guidance_call_slip', 'form_code' => 'F-OGC-GSP-CSP-007-00'],
                    ['id' => 1], $data, [], ['id' => 1, 'tenant_id' => 1], ['layout' => 'single']);
            } else {
                $service->renderCallSlipPdf(['data' => $data, 'is_draft' => true], ['layout' => 'single']);
            }
            throw new RuntimeException('Compiler failure must not return a fake PDF.');
        } catch (RuntimeException $error) {
            if ($error->getCode() !== 503 || $error->getMessage() !== 'PDF report compilation is unavailable.') {
                throw $error;
            }
            if (count($issuances->records) !== $issuedCount) {
                throw new RuntimeException('Failed compilation must not register an issuance.');
            }
            fwrite(STDOUT, 'PASS ' . $mode . " compilation failure returns safe 503 before any issuance\n");
        }
    }
}
