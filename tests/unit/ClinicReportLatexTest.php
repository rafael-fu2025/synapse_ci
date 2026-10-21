<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reporting\OperationalReportPdf;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

final class ClinicReportLatexTest extends CIUnitTestCase
{
    private function rows(bool $all = false, int $total = 100): array
    {
        $rows = [['date_manila', 'unit', 'metric', 'count'], ['2026-10-01', 'clinic', 'completed_clinic_encounters_started', $total]];
        if ($all) {
            $rows[] = ['2026-10-01', 'guidance', 'completed_guidance_sessions_started', 8];
            foreach (['started', 'released', 'voided'] as $metric) $rows[] = ['2026-10-01', 'bmg', 'bmg_batches_' . $metric, 2];
        }
        return $rows;
    }

    private function insights(): array
    {
        return ['encounter_total' => 100,
            'complaints' => [['label' => 'Headache', 'count' => 50], ['label' => 'Fever', 'count' => 30], ['label' => 'Other recorded', 'count' => 15], ['label' => 'Not recorded', 'count' => 5]],
            'dispositions' => [['label' => 'Discharged', 'count' => 80], ['label' => 'Sent Home', 'count' => 20], ['label' => 'Not recorded', 'count' => 0]],
            'medicine_status' => 'ready', 'medicines' => [['label' => 'Paracetamol / 500 mg / Tablet', 'count' => 40], ['label' => 'Antacid', 'count' => 30]],
            'medicine_encounters' => 50, 'legacy_medicine_encounters' => 4];
    }

    private function source(array $insights, bool $all = false, int $total = 100): string
    {
        return OperationalReportPdf::source($this->rows($all, $total), '2026-10-01', '2026-10-01', $insights);
    }

    public function testAddsTwoClinicPagesAndUpdatesDepartmentOffsets(): void
    {
        $single = $this->source($this->insights());
        self::assertStringContainsString('Page \\thepage{} of 4', $single);
        foreach (['Clinic visit profile', 'Clinic medicine insights', 'Clinic daily register'] as $title) self::assertStringContainsString($title, $single);
        self::assertLessThan(strpos($single, 'Clinic daily register'), strpos($single, 'Clinic visit profile'));
        $all = $this->source($this->insights(), true);
        self::assertStringContainsString('Page \\thepage{} of 9', $all);
        foreach (['Pages 2--5', 'Pages 6--7', 'Pages 8--9'] as $range) self::assertStringContainsString($range, $all);
        self::assertSame(6, substr_count($all, 'arc[start angle='));
    }

    public function testHasDonutsExactCountsSharesAndReversalCountingNotes(): void
    {
        $source = $this->source($this->insights());
        self::assertStringContainsString('arc[start angle=', $source);
        self::assertStringContainsString('Chief complaint', $source);
        self::assertStringContainsString('Disposition', $source);
        self::assertStringContainsString('Headache & 50 & 50\\%', $source);
        self::assertStringContainsString('Antacid & 30 & 60\\%', $source);
        self::assertStringContainsString('current completion status', $source);
        self::assertStringContainsString('not units or quantities', $source);
        self::assertStringContainsString('non-reversed ledger dispensations', $source);
        self::assertStringContainsString('more than 100\\%', $source);
        self::assertStringContainsString('Free-text narratives are not exported', $source);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $source);
    }

    public function testCatalogLabelsCannotInjectLatexOrBreakPlotExpressions(): void
    {
        $insights = $this->insights();
        $insights['medicines'][0]['label'] = 'Caf' . "\u{00E9}" . ' & 5% \\input{private} #1_$';
        $source = $this->source($insights);
        self::assertStringContainsString('\\& 5\\%', $source);
        self::assertStringContainsString('\\textbackslash{}input\\{private\\}', $source);
        self::assertStringNotContainsString('\\input{private}', $source);
        self::assertStringNotContainsString("\u{00E9}", $source);
    }

    public function testZeroAndUnavailableStatesDoNotDrawMisleadingSlices(): void
    {
        $zero = ['encounter_total' => 0, 'complaints' => [['label' => 'Not recorded', 'count' => 0]],
            'dispositions' => [['label' => 'Not recorded', 'count' => 0]], 'medicine_status' => 'ready',
            'medicines' => [], 'medicine_encounters' => 0, 'legacy_medicine_encounters' => 0];
        $source = $this->source($zero, false, 0);
        self::assertStringNotContainsString('arc[start angle=', $source);
        self::assertStringContainsString('No matching medicine encounters.', $source);
        $zero['medicine_status'] = 'unavailable';
        $source = $this->source($zero, false, 0);
        self::assertStringContainsString('Medicine reporting setup not ready', $source);
        self::assertStringContainsString('This is not a zero count', $source);
    }

    public function testRejectsInvalidOrInconsistentAggregateContracts(): void
    {
        $bad = [];
        $x = $this->insights(); $x['encounter_total'] = 99; $bad[] = $x;
        $x = $this->insights(); $x['complaints'][0]['count'] = -1; $bad[] = $x;
        $x = $this->insights(); $x['dispositions'] = []; $bad[] = $x;
                $x = $this->insights(); $x['complaints'][0]['label'] = 'Private patient narrative'; $bad[] = $x;
        $x = $this->insights(); $x['medicines'][0]['count'] = 51; $bad[] = $x;
        $x = $this->insights(); $x['medicine_status'] = 'unavailable'; $bad[] = $x;
        $x = $this->insights(); $x['medicines'][0]['label'] = "bad\nlabel"; $bad[] = $x;
        foreach ($bad as $insights) {
            try { $this->source($insights); self::fail('Invalid Clinic aggregates accepted.'); }
            catch (RuntimeException $error) { self::assertSame(503, $error->getCode()); }
        }
        $this->expectException(RuntimeException::class);
        OperationalReportPdf::source([['date_manila', 'unit', 'metric', 'count'], ['2026-10-01', 'guidance', 'completed_guidance_sessions_started', 100]], '2026-10-01', '2026-10-01', $this->insights());
    }
}
