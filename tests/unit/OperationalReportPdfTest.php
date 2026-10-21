<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reporting\ExportPdf;
use App\Services\Reporting\OperationalReportPdf;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

final class OperationalReportPdfTest extends CIUnitTestCase
{
    private function rows(int $days = 1): array
    {
        $rows = [['date_manila', 'unit', 'metric', 'count']];
        for ($day = 1; $day <= $days; $day++) {
            foreach (['clinic' => ['completed_clinic_encounters_started'], 'guidance' => ['completed_guidance_sessions_started'],
                'bmg' => ['bmg_batches_started', 'bmg_batches_released', 'bmg_batches_voided']] as $unit => $metrics) {
                foreach ($metrics as $metric) $rows[] = [sprintf('2026-10-%02d', $day), $unit, $metric, $day];
            }
        }
        return $rows;
    }

    /** Logical pages in the source, not a substitute for checking compiled pagination. */
    private function pages(string $source): array
    {
        $body = explode('\\begin{document}', $source, 2)[1];
        $body = explode('\\end{document}', $body, 2)[0];
        return array_values(array_filter(array_map('trim', explode('\\newpage', $body)),
            static fn (string $page): bool => $page !== ''));
    }

    private function assertPageNumbers(string $source, int $total): void
    {
        self::assertCount($total, $this->pages($source));
        self::assertStringContainsString('Page \\thepage{} of ' . $total . '\\par', $source);
        self::assertStringNotContainsString('TOTALPAGES', $source);
    }

    public function testCreatesSevenPageSourceWithExecutiveSummaryAndSeparateDepartmentSections(): void
    {
        $source = OperationalReportPdf::source($this->rows(), '2026-10-01', '2026-10-01');
        self::assertStringStartsWith('\\documentclass[10pt,a4paper]{article}', $source);
        self::assertStringEndsWith("\\end{document}\n", $source);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $source);
        foreach (['FOUNDATION UNIVERSITY', 'SYNAPSE', 'EXECUTIVE SUMMARY', 'Department results',
            'Performance summary', 'Daily activity', 'Daily counts and period totals',
            'Interpretation and counting basis', 'not unique patients', 'not unique people',
            'separate lifecycle events', 'No names, diagnoses, or person-level records.'] as $label) {
            self::assertStringContainsString($label, $source);
        }
        $this->assertPageNumbers($source, 7);
        $pages = $this->pages($source);
        self::assertStringContainsString('\\ReportHeader{EXECUTIVE SUMMARY}{Operational report}', $pages[0]);
        self::assertStringContainsString('There is no combined activity total.', $pages[0]);
        self::assertStringContainsString('not be added together or interpreted as a cohort success rate', $pages[0]);
        foreach (['Clinic' => 2, 'Guidance' => 4, 'BMG' => 6] as $unit => $firstPage) {
            self::assertStringContainsString('\\Section{' . $unit . '\\hfill\\normalfont\\scriptsize Pages ' . $firstPage . '--' . ($firstPage + 1) . '}', $pages[0]);
            self::assertStringContainsString('\\ReportHeader{DEPARTMENT ANALYSIS}{' . $unit . ' operational results}', $pages[$firstPage - 1]);
            self::assertStringContainsString('\\ReportHeader{DEPARTMENT DETAIL}{' . $unit . ' daily register}', $pages[$firstPage]);
        }
        self::assertSame(7, substr_count($source, '{01 Oct 2026 - 01 Oct 2026}'));
        self::assertLessThan(262_144, strlen($source));
    }

    public function testEveryPageUsesHeaderWithEmbeddedUniversitySeal(): void
    {
        $source = OperationalReportPdf::source($this->rows(), '2026-10-01', '2026-10-01');
        self::assertStringContainsString('\\includegraphics[width=20mm,height=20mm,keepaspectratio]{university-seal.png}', $source);
        self::assertSame(1, substr_count($source, '\\includegraphics'));
        foreach ($this->pages($source) as $page) {
            self::assertStringStartsWith('\\ReportHeader{', $page);
            self::assertSame(1, substr_count($page, '\\ReportHeader{'));
        }
    }

    public function testDepartmentPagesContainOnlyTheirOwnMetricsAndCharts(): void
    {
        $source = OperationalReportPdf::source($this->rows(), '2026-10-01', '2026-10-01');
        $pages = $this->pages($source);
        $labels = ['Clinic' => ['Completed encounters'], 'Guidance' => ['Completed sessions'],
            'BMG' => ['Batches started', 'Batches released', 'Batches voided']];
        foreach (['Clinic', 'Guidance', 'BMG'] as $index => $unit) {
            foreach ([1 + $index * 2, 2 + $index * 2] as $pageIndex) {
                $page = $pages[$pageIndex];
                foreach ($labels as $otherUnit => $metrics) {
                    if ($otherUnit !== $unit) self::assertStringNotContainsString($otherUnit, $page);
                    foreach ($metrics as $metric) {
                        if ($otherUnit === $unit) self::assertStringContainsString($metric, $page);
                        else self::assertStringNotContainsString($metric, $page);
                    }
                }
                self::assertSame($pageIndex % 2 === 1 ? count($labels[$unit]) : 0,
                    substr_count($page, '\\begin{axis}'));
            }
        }
        self::assertStringNotContainsString('\\begin{axis}', $pages[0]);
    }

    public function testPageNumbersAndContentAdaptToEveryDepartmentSelection(): void
    {
        foreach ([['clinic'], ['guidance'], ['bmg'], ['clinic', 'guidance'], ['clinic', 'bmg'],
            ['guidance', 'bmg'], ['clinic', 'guidance', 'bmg']] as $units) {
            $rows = array_merge([['date_manila', 'unit', 'metric', 'count']],
                array_values(array_filter(array_slice($this->rows(), 1), static fn (array $row): bool => in_array($row[1], $units, true))));
            $source = OperationalReportPdf::source($rows, '2026-10-01', '2026-10-01');
            $total = count($units) === 1 ? 2 : 1 + 2 * count($units);
            $this->assertPageNumbers($source, $total);
            $pages = $this->pages($source);
            $offset = count($units) === 1 ? 0 : 1;
            if ($offset === 0) self::assertStringNotContainsString('EXECUTIVE SUMMARY', $source);
            foreach (['clinic' => 'Clinic', 'guidance' => 'Guidance', 'bmg' => 'BMG'] as $key => $unit) {
                if (!in_array($key, $units, true)) {
                    // The preamble declares shared colors; only document content is department-specific.
                    foreach (array_slice($pages, $offset) as $page) self::assertStringNotContainsString($unit, $page);
                                        self::assertStringNotContainsString('\\Section{' . $unit . '\\hfill', $pages[0]);
                }
            }
            foreach ($units as $index => $unit) {
                $title = $unit === 'bmg' ? 'BMG' : ucfirst($unit);
                $analysis = $pages[$offset + $index * 2];
                $register = $pages[$offset + $index * 2 + 1];
                self::assertStringContainsString('{' . $title . ' operational results}', $analysis);
                self::assertStringContainsString('{' . $title . ' daily register}', $register);
                self::assertSame($unit === 'bmg' ? 3 : 1, substr_count($analysis, '\\addplot['));
                self::assertStringNotContainsString('\\addplot[', $register);
                if ($offset === 1) {
                    $firstPage = 2 + $index * 2;
                    self::assertStringContainsString('Pages ' . $firstPage . '--' . ($firstPage + 1), $pages[0]);
                }
            }
        }
    }

    public function testMaximumRangePreservesAllDailyRowsAndChartCoordinatesInEachDepartment(): void
    {
        $source = OperationalReportPdf::source($this->rows(31), '2026-10-01', '2026-10-31');
        $this->assertPageNumbers($source, 7);
        $pages = $this->pages($source);
        $coordinates = [];
        for ($day = 1; $day <= 31; $day++) $coordinates[] = '(' . $day . ',' . $day . ')';
        foreach ([2, 4, 6] as $pageIndex) {
            $columns = $pageIndex === 6 ? 3 : 1;
            for ($day = 1; $day <= 31; $day++) {
                $row = sprintf('%02d Oct 2026', $day) . str_repeat(' & ' . $day, $columns) . " \\\\\n";
                self::assertSame(1, substr_count($pages[$pageIndex], $row));
            }
            self::assertStringContainsString('\\textbf{Period total}' . str_repeat(' & \\textbf{496}', $columns), $pages[$pageIndex]);
            self::assertSame($columns, substr_count($pages[$pageIndex - 1], 'coordinates {' . implode(' ', $coordinates) . '};'));
            self::assertStringContainsString('xtick={1,11,21,31},xticklabels={{01 Oct},{11 Oct},{21 Oct},{31 Oct}}', $pages[$pageIndex - 1]);
            self::assertStringContainsString('ymin=0,ymax=32,ytick={0,16,32}', $pages[$pageIndex - 1]);
        }
        self::assertLessThan(262_144, strlen($source));
    }

    public function testChartsPreserveDistinctMetricValuesAndCountingStatistics(): void
    {
        $rows = $this->rows(3);
        foreach ($rows as $index => &$row) {
            if ($index > 0) $row[3] = ((int) substr($row[0], -2) - 1) * ($index % 5 + 1);
        }
        unset($row);
        $source = OperationalReportPdf::source($rows, '2026-10-01', '2026-10-03');
        $pages = $this->pages($source);
        foreach (['Clinic', 'Guidance', 'Started', 'Released', 'Voided'] as $index => $color) {
            $multiplier = ($index + 1) % 5 + 1;
            self::assertStringContainsString('\\addplot[fill=' . $color . ',draw=' . $color . '] coordinates {(1,0) (2,' . $multiplier . ') (3,' . (2 * $multiplier) . ')};', $source);
        }
        self::assertStringContainsString('\\MetricCard{Completed activity}{6}{Completed encounters}', $pages[1]);
        self::assertStringContainsString('\\MetricCard{Daily average}{2.0}{Across all reporting days}', $pages[1]);
        self::assertStringContainsString('\\MetricCard{Days with activity}{2 / 3}', $pages[1]);
        self::assertStringContainsString('Peak daily activity: 4 on 03 Oct 2026', $pages[1]);
        self::assertStringContainsString('xtick={1,2,3},xticklabels={{01 Oct},{02 Oct},{03 Oct}}', $pages[1]);
    }

    public function testZeroSingleUnitAndLargeCountsRemainBounded(): void
    {
        foreach ([0, 999999999] as $count) {
            $source = OperationalReportPdf::source([['date_manila', 'unit', 'metric', 'count'],
                ['2026-10-01', 'clinic', 'completed_clinic_encounters_started', $count]], '2026-10-01', '2026-10-01');
            self::assertStringContainsString('\\MetricCard{Completed activity}{' . number_format($count) . '}', $source);
            self::assertStringContainsString('\\textbf{Period total} & \\textbf{' . number_format($count) . '}', $source);
            self::assertStringContainsString('coordinates {(1,' . $count . ')};', $source);
            self::assertStringContainsString($count === 0 ? 'ymin=0,ymax=2,ytick={0,1,2}' : 'ymin=0,ymax=1000000000,ytick={0,500000000,1000000000}', $source);
            self::assertStringContainsString($count === 0 ? 'No completed activity matched this reporting period.' : 'Peak daily activity: 999,999,999 on 01 Oct 2026', $source);
            $this->assertPageNumbers($source, 2);
            self::assertStringNotContainsString('EXECUTIVE SUMMARY', $source);
            self::assertStringNotContainsString('NAN', $source);
            self::assertStringNotContainsString('INF', $source);
            self::assertLessThan(262_144, strlen($source));
        }
    }

    public function testNumericStringsProduceTheSameServerOwnedSourceAsIntegers(): void
    {
        $rows = $this->rows();
        $strings = $rows;
        foreach ($strings as $index => &$row) {
            if ($index > 0) $row[3] = '0001';
        }
        unset($row);
        self::assertSame(OperationalReportPdf::source($rows, '2026-10-01', '2026-10-01'),
            OperationalReportPdf::source($strings, '2026-10-01', '2026-10-01'));
    }

    public function testRejectsUserPayloadInsteadOfInterpolatingItIntoSource(): void
    {
        foreach (['\\input{private}', '\\immediate\\write18{whoami}', "}\\end{document}\n%", ') Tj ET /OpenAction'] as $payload) {
            foreach (range(0, 3) as $column) {
                $row = $this->rows()[1];
                $row[$column] = $payload;
                try {
                    OperationalReportPdf::source([['date_manila', 'unit', 'metric', 'count'], $row], '2026-10-01', '2026-10-01');
                    self::fail('User payload must never reach the template.');
                } catch (RuntimeException $error) {
                    self::assertSame(503, $error->getCode());
                    self::assertStringNotContainsString($payload, $error->getMessage());
                }
            }
        }
    }

    public function testRejectsMissingDatesDuplicateMetricsNegativeCountsAndInvalidDates(): void
    {
        $rows = $this->rows();
        $duplicate = $rows;
        $duplicate[] = $rows[1];
        $negative = $rows;
        $negative[1][3] = -1;
        foreach ([[$rows, '2026-10-01', '2026-10-02'], [$duplicate, '2026-10-01', '2026-10-01'],
            [$negative, '2026-10-01', '2026-10-01'], [$rows, '2026-02-30', '2026-02-30'],
            [$rows, '2026-10-02', '2026-10-01'], [$rows, '2026-10-01', '2026-11-01'],
            [$rows, '2026-10-1', '2026-10-01'], [$rows, '2026-10-01', '\\input{private}'],
            [[], '2026-10-01', '2026-10-01'], [[$rows[0]], '2026-10-01', '2026-10-01'],
            [[['date', 'unit', 'metric', 'count'], $rows[1]], '2026-10-01', '2026-10-01'],
            [array_merge($this->rows(31), [$rows[1]]), '2026-10-01', '2026-10-31']] as $input) {
            try {
                OperationalReportPdf::source(...$input);
                self::fail('Invalid input must be refused.');
            } catch (RuntimeException $error) {
                self::assertSame(503, $error->getCode());
            }
        }
    }

    public function testBmgRequiresAllThreeLifecycleMetrics(): void
    {
        $bmg = array_values(array_filter(array_slice($this->rows(), 1), static fn (array $row): bool => $row[1] === 'bmg'));
        foreach ([1, 2, 3, 4, 5, 6] as $mask) {
            foreach ([false, true] as $withClinic) {
                $rows = [['date_manila', 'unit', 'metric', 'count']];
                if ($withClinic) $rows[] = $this->rows()[1];
                foreach ($bmg as $index => $row) {
                    if (($mask & (1 << $index)) !== 0) $rows[] = $row;
                }
                try {
                    OperationalReportPdf::source($rows, '2026-10-01', '2026-10-01');
                    self::fail('Partial BMG lifecycle metrics must be refused.');
                } catch (RuntimeException $error) {
                    self::assertSame(503, $error->getCode());
                    self::assertSame('Incomplete BMG report data.', $error->getMessage());
                }
            }
        }
    }

    public function testRejectsMalformedRowsAndPersonLevelFields(): void
    {
        $valid = $this->rows()[1];
        $malformed = [null, [], array_slice($valid, 0, 3), [...$valid, 'Private patient name']];
        foreach ([0 => ['2026-09-30', 'Private patient name'], 1 => ['guidance', 'Private patient name'],
            2 => [null, 'Private diagnosis'], 3 => [1.5, true, '1e3', '', '-1', 'Private patient name', 1000000000]] as $column => $values) {
            foreach ($values as $value) {
                $row = $valid;
                $row[$column] = $value;
                $malformed[] = $row;
            }
        }
        foreach ($malformed as $row) {
            try {
                OperationalReportPdf::source([['date_manila', 'unit', 'metric', 'count'], $row], '2026-10-01', '2026-10-01');
                self::fail('Malformed or person-level input must be refused.');
            } catch (RuntimeException $error) {
                self::assertSame(503, $error->getCode());
                self::assertStringNotContainsString('Private', $error->getMessage());
            }
        }
    }

    public function testResponseUsesPrivateAttachmentHeaders(): void
    {
        $pdf = "%PDF-1.4\n\x00\xFF\n%%EOF";
        $response = ExportPdf::response(Services::response(null, false), $pdf, 'report.pdf');
        self::assertStringStartsWith('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame((string) strlen($pdf), $response->getHeaderLine('Content-Length'));
        $cacheControl = $response->getHeaderLine('Cache-Control');
                foreach (['private', 'no-store', 'max-age=0'] as $directive) {
                    self::assertContains($directive, array_map('trim', explode(',', $cacheControl)));
                }
                self::assertNotContains('public', array_map('trim', explode(',', $cacheControl)));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        self::assertSame('0', $response->getHeaderLine('Expires'));
        self::assertSame('attachment; filename="report.pdf"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame($pdf, $response->getBody());
    }

    public function testRealLatexCompilationWhenExplicitlyEnabled(): void
    {
        if (getenv('SYNAPSE_TEST_LATEX') !== '1') {
            self::markTestSkipped('Set SYNAPSE_TEST_LATEX=1 with a configured LaTeX compiler to run this integration test.');
        }
        $pdf = OperationalReportPdf::encode($this->rows(31), '2026-10-01', '2026-10-31');
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringEndsWith('%%EOF', trim($pdf));
        self::assertLessThanOrEqual(2_097_152, strlen($pdf));
        $response = ExportPdf::response(Services::response(null, false), $pdf, 'report.pdf');
        self::assertSame((string) strlen($pdf), $response->getHeaderLine('Content-Length'));
        self::assertSame($pdf, $response->getBody());
    }
}
