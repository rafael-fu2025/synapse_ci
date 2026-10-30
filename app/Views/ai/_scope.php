<?php
/** Server-provided workstation data; defaults retain the original clinic-only fixtures. */
$legacyAnalysisFixture = $legacyAnalysisFixture ?? !isset($analyses);
$unit = $unit ?? 'clinic';
$unitLabel = match ($unit) { 'guidance' => 'Guidance', 'bmg' => 'Facilities / BMG', default => 'Clinic' };
$basePath = $basePath ?? match ($unit) {
    'guidance' => 'guidance/insights', 'bmg' => 'facilities/insights', default => 'clinic/insights',
};
$analyses = $analyses ?? [\App\Services\Ai\AnalysisCatalog::forUnit($unit)[0]];
// An optional server-supplied clock keeps presentation tests independent of today's date.
$presentationNow = ($presentationNow ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))
    ->setTimezone(new \DateTimeZone('Asia/Manila'));
$completedMonth = $presentationNow->modify('first day of this month')->setTime(0, 0)->modify('-1 month');
$comparisonMonthLabel = $completedMonth->modify('-1 month')->format('F Y');
$completedMonthLabel = $completedMonth->format('F Y');
$analysisDescriptions = [
    'clinic.activity' => 'Compare completed clinic visits in the two months.',
    'clinic.complaints' => 'Compare recorded complaint categories as a share of completed clinic visits.',
    'guidance.activity' => 'Compare completed counselling sessions in the two months.',
    'guidance.themes' => 'Compare recorded case categories as a share of completed counselling sessions.',
    'bmg.batches' => 'Compare batch starts, releases, and voids separately. These are not completion rates.',
    'bmg.rotations' => 'Compare accepted rotation reports and the share reporting a failure or interruption.',
];
