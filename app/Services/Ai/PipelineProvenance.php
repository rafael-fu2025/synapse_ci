<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Public code identity only: never hash configuration, credentials or deployment settings here. */
final class PipelineProvenance
{
    private const FILES = [
        'Ai/AnalysisCatalog.php', 'Ai/ClinicSnapshotBuilder.php', 'Ai/DepartmentSnapshotBuilder.php',
        'Ai/DisclosurePolicy.php', 'Ai/BroadDisclosurePolicy.php', 'Ai/SignalDetector.php',
        'Ai/ProgramLibrary.php', 'Ai/InsightGenerator.php', 'Ai/InputBudget.php', 'Ai/ChatClient.php',
        'Reporting/ClinicReportInsights.php', 'Ai/PipelineProvenance.php',
    ];

    public static function digest(): string
    {
        $manifest = ['format' => 'humus.ai.pipeline.v1'];
        foreach (self::FILES as $name) {
            $hash = @hash_file('sha256', dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name));
            if (!is_string($hash)) { throw new AiFailure('pipeline_unavailable'); }
            $manifest[$name] = $hash;
        }
        return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    public static function matches(mixed $digest): bool
    {
        return is_string($digest) && preg_match('/\A[a-f0-9]{64}\z/D', $digest) === 1
            && hash_equals(self::digest(), $digest);
    }
}
