<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reporting\ClinicSummaryReportLatex;
use App\Services\Reporting\LatexReportCompiler;
use CodeIgniter\Test\CIUnitTestCase;

/** DB-free layout, escaping, and compilation checks for the Clinic summary LaTeX report. */
final class ClinicSummaryReportLatexTest extends CIUnitTestCase
{
    private function sampleSummary(bool $withRows = true): array
    {
        return [
            'period' => [
                'preset' => 'monthly',
                'label'  => 'Current Month',
                'from'   => '2026-11-01',
                'to'     => '2026-11-17',
            ],
            'school_year' => '2026-2027',
            'totals' => [
                'encounters'  => $withRows ? 142 : 10,
                'completed'   => $withRows ? 136 : 9,
                'referred'    => $withRows ? 6 : 0,
                'in_progress' => $withRows ? 0 : 1,
                'student'     => $withRows ? 128 : 10,
                'employee'    => $withRows ? 14 : 0,
                'dispensed'   => $withRows ? 95 : 0,
                'isolation'   => $withRows ? 5 : 0,
            ],
            'monthly_series' => $withRows
                ? [
                    ['ym' => '2026-06', 'label' => 'Jun 2026', 'count' => 22],
                    ['ym' => '2026-11', 'label' => 'Nov 2026', 'count' => 10],
                ]
                : [],
            'complaints' => $withRows ? [
                'Other recorded' => 7,
                'Not recorded'   => 2,
                'Fever'          => 1,
            ] : [],
            'dispositions' => $withRows ? [
                'Discharged'   => 9,
                'Not recorded' => 1,
            ] : [],
            'top_medicines' => $withRows
                ? [[
                    'name'            => 'Paracetamol + Phenylpropanolamine 500mg',
                    'unit'            => 'tablets',
                    'total_qty'       => 64,
                    'encounter_count' => 32,
                ]]
                : [],
            'isolation_summary' => $withRows
                ? [['label' => 'Chickenpox / Varicella', 'count' => 2]]
                : [],
            'clearances' => [
                'cleared'       => $withRows ? 420 : 0,
                'pending'       => $withRows ? 18 : 0,
                'hold_followup' => $withRows ? 4 : 0,
            ],
        ];
    }

    public function testEverySectionHeadingBreaksParagraphBeforeTabularx(): void
    {
        $tex = ClinicSummaryReportLatex::source($this->sampleSummary());

        self::assertStringContainsString(
            '\newcommand{\SectionHeading}[1]{\par\addvspace{4mm}\noindent{\bfseries\color{Maroon}#1}\par\nobreak\vspace{1.5mm}}',
            $tex
        );

        foreach ([
            '\SectionHeading{Monthly Encounter Breakdown}',
            '\SectionHeading{Chief Complaints \& Clinical Dispositions}',
            '\SectionHeading{Top Dispensed Medicines (FEFO Ledger)}',
            '\SectionHeading{Communicable Disease \& Enrollment Health Clearance Summary (SY 2026-2027)}',
        ] as $heading) {
            self::assertStringContainsString($heading, $tex);
        }

        // Prevent inline horizontal placement of \textwidth tabularx next to text headings.
        self::assertDoesNotMatchRegularExpression('/\{\\\\bfseries[^\}]*\}(?:\r?\n|\s)+\\\\begin\{tabularx\}\{\\\\textwidth\}/', $tex);
        preg_match_all('/([^\r\n]+)\r?\n\\\\begin\{tabularx\}\{\\\\textwidth\}/', $tex, $matches);
        self::assertCount(5, $matches[1]);
        foreach ($matches[1] as $previousLine) {
            $trimmed = trim($previousLine);
            self::assertTrue(
                str_starts_with($trimmed, '\SectionHeading{') || str_contains($trimmed, '\par'),
                'Expected paragraph break before \textwidth tabularx, got: ' . $trimmed
            );
        }

        // Top KPI strip and section tables must use full-width boxed grid columns without booktabs-only rules.
        self::assertStringContainsString('\begin{tabularx}{\textwidth}{|X|X|X|}', $tex);
        self::assertStringContainsString('\begin{tabularx}{\textwidth}{|X|r|}', $tex);
        self::assertStringContainsString('\begin{tabularx}{\textwidth}{|X|r|r|}', $tex);
        self::assertStringContainsString('\begin{tabularx}{\textwidth}{|>{\centering\arraybackslash}X|>{\centering\arraybackslash}X|>{\centering\arraybackslash}X|}', $tex);
        self::assertStringContainsString('\begin{tabularx}{\linewidth}[t]{|X|r|}', $tex);
        self::assertStringContainsString('\hline\hline', $tex);
        self::assertStringContainsString('\textcolor{Maroon}{\textbf{Month}} & \textcolor{Maroon}{\textbf{Encounters}}', $tex);
    }

    public function testEscapesDynamicFieldsClosesRowsAndReplacesEveryPlaceholder(): void
    {
        $summary = $this->sampleSummary();
        $summary['period']['label'] = 'Custom & 100% Check #1_2';
        $summary['monthly_series'][0]['label'] = 'Jun & Jul_#%';
        $summary['complaints'] = ['Headache & nausea_#%' => 3];
        $summary['dispositions'] = ['Sent_home' => 4];
        $summary['top_medicines'] = [[
            'name'            => 'Med A&B 50% #1',
            'unit'            => 'tabs_#',
            'total_qty'       => 10,
            'encounter_count' => 5,
        ]];
        $summary['isolation_summary'] = [['label' => 'Flu_#%', 'count' => 1]];

        $tex = str_replace("\r\n", "\n", ClinicSummaryReportLatex::source($summary));
        $rowEnding = " \\\\ \\hline\n";

        self::assertDoesNotMatchRegularExpression('/__[A-Z_]+__/', $tex);
        self::assertStringContainsString('Custom \\& 100\\% Check \\#1\\_2', $tex);
        foreach ([
            'Jun \\& Jul\\_\\#\\% & 22',
            'Headache \\& nausea\\_\\#\\% & 3',
            'Sent\\_home & 4',
            'Med A\\&B 50\\% \\#1 & 5 & 10 tabs\\_\\#',
            'Flu\\_\\#\\% & 1',
        ] as $row) {
            self::assertStringContainsString($row . $rowEnding, $tex);
        }
        foreach (['\\toprule', '\\midrule', '\\bottomrule'] as $removedCommand) {
            self::assertStringNotContainsString($removedCommand, $tex);
        }
    }

    public function testEmptySummaryUsesExplicitClosedRows(): void
    {
        $tex = str_replace("\r\n", "\n", ClinicSummaryReportLatex::source($this->sampleSummary(false)));
        $rowEnding = " \\\\ \\hline\n";

        self::assertDoesNotMatchRegularExpression('/__[A-Z_]+__/', $tex);
        self::assertSame(5, substr_count($tex, $rowEnding));
        foreach ([
            'No encounters in selected period & 0',
            'No chief complaints recorded & 0',
            'No dispositions recorded & 0',
            'No FEFO dispensations in selected period & 0 & 0',
            'No communicable isolation cases logged & 0',
        ] as $row) {
            self::assertStringContainsString($row . $rowEnding, $tex);
        }
    }

    public function testCompilesMinimalAndPopulatedSummaryToPdfWhenExplicitlyEnabled(): void
    {
        foreach ([false, true] as $withRows) {
            $tex = ClinicSummaryReportLatex::source($this->sampleSummary($withRows));
            self::assertLessThan(262_144, strlen($tex));
            if (getenv('SYNAPSE_TEST_LATEX') === '1') {
                self::assertStringStartsWith('%PDF-', LatexReportCompiler::compile($tex));
            }
        }
    }
}
