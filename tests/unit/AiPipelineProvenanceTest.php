<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\PipelineProvenance;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiPipelineProvenanceTest extends TestCase
{
    public function testDigestIsDeterministicAndMatchesOnlyCurrentCode(): void
    {
        $digest = PipelineProvenance::digest();
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $digest);
        self::assertSame($digest, PipelineProvenance::digest());
        self::assertTrue(PipelineProvenance::matches($digest));
        foreach ([null, '', [], 1, str_repeat('0', 64), strtoupper($digest)] as $legacy) {
            self::assertFalse(PipelineProvenance::matches($legacy));
        }
    }

    public function testManifestContainsOnlyApplicationOwnedPipelineCode(): void
    {
        $files = (new ReflectionClass(PipelineProvenance::class))->getConstant('FILES');
        self::assertContains('Ai/BroadDisclosurePolicy.php', $files);
        self::assertContains('Ai/InsightGenerator.php', $files);
        self::assertContains('Ai/AnalysisCatalog.php', $files);
        self::assertContains('Reporting/ClinicReportInsights.php', $files);
        foreach ($files as $file) {
            self::assertMatchesRegularExpression('/\A(?:Ai|Reporting)\/[A-Za-z]+\.php\z/D', $file);
            self::assertNotContains($file, ['Ai/Profile.php', 'Ai/SettingsStore.php', 'Ai/DeploymentControl.php']);
        }
    }
}
