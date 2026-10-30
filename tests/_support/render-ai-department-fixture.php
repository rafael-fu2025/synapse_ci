<?php
// Reuse the inert legacy view harness; no application bootstrap or dotenv is loaded.
ob_start();
require __DIR__ . '/render-ai-fixture.php';
ob_end_clean();

use App\Services\Ai\AnalysisCatalog;
use App\Services\Ai\BroadDisclosurePolicy;

$unit = $fixture['unit'] ?? 'clinic';
if (!in_array($unit, ['clinic', 'guidance', 'bmg'], true)) {
    throw new InvalidArgumentException('Unknown synthetic workstation.');
}
$analysis = $fixture['analysis'] ?? AnalysisCatalog::forUnit($unit)[0]['code'];
if (AnalysisCatalog::get($analysis)['unit'] !== $unit) {
    throw new InvalidArgumentException('Synthetic analysis must belong to its workstation.');
}
if ($analysis !== 'clinic.activity') {
    if ($unit === 'bmg') {
        $current = $analysis === 'bmg.batches'
            ? ['batches_started' => 40, 'batches_released' => 32, 'batches_voided' => 8]
            : ['rotation_reports' => 40, 'rotation_failed' => 8, 'rotation_interrupted' => 4];
        $previous = array_fill_keys(array_keys($current), 0);
        $evidence = BroadDisclosurePolicy::release($analysis, ['counts' => $current], ['counts' => $previous], '2026-09', '2026-08');
    } elseif ($analysis === 'guidance.activity') {
        $evidence = BroadDisclosurePolicy::release($analysis,
            ['count' => 40, 'contributors' => 20, 'unlinked' => 0],
            ['count' => 32, 'contributors' => 20, 'unlinked' => 0], '2026-09', '2026-08');
    } else {
        $aggregate = static function () use ($analysis): array {
            $buckets = [];
            $codes = [...array_column(array_slice(AnalysisCatalog::metrics($analysis), 1), 'metric_code'),
                '__other', '__missing', ...($analysis === 'guidance.themes' ? ['__personal', '__crisis'] : [])];
            foreach ($codes as $i => $code) {
                $count = $i === 0 ? 20 : (in_array($code, ['__other', '__missing'], true) ? 10 : 0);
                $buckets[$code] = ['count' => $count, 'contributors' => $count, 'complement_contributors' => 40 - $count];
            }
            return ['count' => 40, 'contributors' => 40, 'unlinked' => 0, 'buckets' => $buckets];
        };
        $evidence = BroadDisclosurePolicy::release($analysis, $aggregate(), $aggregate(), '2026-09', '2026-08');
    }
}
if (!empty($fixture['hostile'])) {
    $evidence['limitations'][] = $attack;
}
$data['unit'] = $unit;
$data['basePath'] = ($unit === 'bmg' ? 'facilities' : $unit) . '/insights';
$data['analyses'] = AnalysisCatalog::forUnit($unit);
foreach ($data['jobs'] as &$job) { $job['analysis_code'] = $analysis; }
unset($job);
foreach ($data['insights'] as &$insight) {
    $insight['analysis_code'] = $analysis;
    $insight['draft']['evidence'] = $evidence;
}
unset($insight);
echo json_encode([
    'index' => view('ai/index', $data), 'panel' => view('ai/panel', $data),
    'baseline' => view('ai/evidence', ['evidence' => $evidence]), 'evidence' => $evidence,
], JSON_THROW_ON_ERROR);
