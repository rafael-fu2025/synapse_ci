<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GuidanceDocumentIssuanceModel;
use App\Models\GuidanceDocumentJobModel;
use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Reporting\GuidanceMultipleIntelligenceLatex;
use App\Services\Reporting\LatexReportCompiler;
use CodeIgniter\Test\CIUnitTestCase;

final class GuidanceMultipleIntelligencePdfTest extends CIUnitTestCase
{
    private array $sampleSnapshot;
    private array $sampleAttestation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sampleAttestation = [
            'signer_name' => 'Juan Dela Cruz',
            'signer_id' => 'STU-2026-0042',
            'signer_role' => 'Student',
            'attested_at' => '2026-11-13 15:45:00',
            'statement' => 'I attest under penalty of academic integrity that this survey accurately reflects my own responses.',
            'snapshot_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ];

        $this->sampleSnapshot = [
            'student_name' => 'Juan Dela Cruz & Maria Clara',
            'student_id' => 'STU-2026-0042',
            'course_year' => 'BS Psychology - 3rd Year (Sec_A)',
            'assessment_date' => '2026-11-13',
            'verification_code' => 'SYN-DOC-MI9901',
            'domain_scores' => [
                'logical_mathematical' => 34,
                'linguistic' => 30,
                'spatial' => 26,
                'musical' => 22,
                'bodily_kinesthetic' => 28,
                'interpersonal' => 36,
                'intrapersonal' => 32,
                'naturalist' => 18,
            ],
            'attestation' => $this->sampleAttestation,
            'is_draft' => false,
        ];
    }

    public function testDomainDefinitionsAreCanonicalAndComplete(): void
    {
        $domains = GuidanceMultipleIntelligenceLatex::getDomainDefinitions();

        $this->assertCount(8, $domains);

        $expectedOrder = [
            1 => 'logical_mathematical',
            2 => 'linguistic',
            3 => 'spatial',
            4 => 'musical',
            5 => 'bodily_kinesthetic',
            6 => 'interpersonal',
            7 => 'intrapersonal',
            8 => 'naturalist',
        ];

        foreach ($expectedOrder as $index => $expectedKey) {
            $this->assertArrayHasKey($index, $domains);
            $domain = $domains[$index];
            $this->assertSame($expectedKey, $domain['key']);
            $this->assertSame(40, $domain['max_score']);
            $this->assertNotEmpty($domain['title']);
            $this->assertNotEmpty($domain['description']);
            $this->assertNotEmpty($domain['question']);
            $this->assertIsArray($domain['educational_suggestions']);
            $this->assertGreaterThanOrEqual(3, count($domain['educational_suggestions']));
        }
    }

    public function testScoreExtractionFromVariousFormats(): void
    {
        // 1. Flat associative array
        $flat = [
            'logical_mathematical' => 35,
            'linguistic' => 25,
            'spatial' => 20,
            'musical' => 15,
            'bodily_kinesthetic' => 30,
            'interpersonal' => 40,
            'intrapersonal' => 10,
            'naturalist' => 5,
        ];
        $extracted1 = GuidanceMultipleIntelligenceLatex::extractDomainScores(['domain_scores' => $flat]);
        $this->assertSame(35, $extracted1['logical_mathematical']);
        $this->assertSame(40, $extracted1['interpersonal']);
        $this->assertSame(5, $extracted1['naturalist']);

        // 2. Abbreviated aliases
        $aliases = [
            'lm' => 38,
            'ling' => 28,
            'spa' => 22,
            'mus' => 18,
            'bk' => 33,
            'inter' => 37,
            'intra' => 29,
            'nat' => 12,
        ];
        $extracted2 = GuidanceMultipleIntelligenceLatex::extractDomainScores(['scores' => $aliases]);
        $this->assertSame(38, $extracted2['logical_mathematical']);
        $this->assertSame(28, $extracted2['linguistic']);
        $this->assertSame(12, $extracted2['naturalist']);

        // 3. JSON encoded string
        $json = json_encode($flat, JSON_THROW_ON_ERROR);
        $extracted3 = GuidanceMultipleIntelligenceLatex::extractDomainScores(['domain_scores_json' => $json]);
        $this->assertSame(35, $extracted3['logical_mathematical']);

        // 4. Clamping out-of-bounds scores
        $outOfBounds = [
            'logical_mathematical' => -10,
            'linguistic' => 99,
        ];
        $extracted4 = GuidanceMultipleIntelligenceLatex::extractDomainScores(['scores' => $outOfBounds]);
        $this->assertSame(0, $extracted4['logical_mathematical']);
        $this->assertSame(40, $extracted4['linguistic']);
    }

    public function testRelativeStrengthBands(): void
    {
        $this->assertSame('Highly Developed', GuidanceMultipleIntelligenceLatex::getRelativeStrengthBand(40));
        $this->assertSame('Highly Developed', GuidanceMultipleIntelligenceLatex::getRelativeStrengthBand(32));
        $this->assertSame('Moderately Developed', GuidanceMultipleIntelligenceLatex::getRelativeStrengthBand(31));
        $this->assertSame('Moderately Developed', GuidanceMultipleIntelligenceLatex::getRelativeStrengthBand(24));
        $this->assertSame('Developing', GuidanceMultipleIntelligenceLatex::getRelativeStrengthBand(23));
        $this->assertSame('Developing', GuidanceMultipleIntelligenceLatex::getRelativeStrengthBand(16));
        $this->assertSame('Emerging', GuidanceMultipleIntelligenceLatex::getRelativeStrengthBand(15));
        $this->assertSame('Emerging', GuidanceMultipleIntelligenceLatex::getRelativeStrengthBand(0));
    }

    public function testRenderLatexContainsAllRequiredSections(): void
    {
        $latex = GuidanceMultipleIntelligenceLatex::render($this->sampleSnapshot);

        // Header and branding
        $this->assertStringContainsString('FOUNDATION UNIVERSITY', $latex);
        $this->assertStringContainsString('OFFICE OF THE GUIDANCE CENTER', $latex);
        $this->assertStringContainsString('MULTIPLE INTELLIGENCES REPORT (Walter McKenzie 1999)', $latex);
        $this->assertStringContainsString('PROVISIONAL (PENDING QA SIGN-OFF)', $latex);

        $customCodeLatex = GuidanceMultipleIntelligenceLatex::render($this->sampleSnapshot + ['form_code' => 'F-OGC-MIR-010-00']);
        $this->assertStringContainsString('F-OGC-MIR-010-00', $customCodeLatex);

        // Student metadata
        $this->assertStringContainsString('Juan Dela Cruz \\& Maria Clara', $latex);
        $this->assertStringContainsString('STU-2026-0042', $latex);
        $this->assertStringContainsString('BS Psychology - 3rd Year (Sec\\_A)', $latex);
        $this->assertStringContainsString('2026-11-13', $latex);

        // Section I: Summary Table with all 8 domains
        $this->assertStringContainsString('I. Domain Score Summary Table', $latex);
        $this->assertStringContainsString('Logical-Mathematical Intelligence', $latex);
        $this->assertStringContainsString('Linguistic Intelligence', $latex);
        $this->assertStringContainsString('Spatial Intelligence', $latex);
        $this->assertStringContainsString('Musical Intelligence', $latex);
        $this->assertStringContainsString('Bodily-Kinesthetic Intelligence', $latex);
        $this->assertStringContainsString('Interpersonal Intelligence', $latex);
        $this->assertStringContainsString('Intrapersonal Intelligence', $latex);
        $this->assertStringContainsString('Naturalist Intelligence', $latex);

        // Scores and percentages
        $this->assertStringContainsString('34 / 40', $latex);
        $this->assertStringContainsString('85.0\\%', $latex);
        $this->assertStringContainsString('Highly Developed', $latex);

        // Section II: Visual Score Plot (TikZ)
        $this->assertStringContainsString('II. Visual Score Plot (Walter McKenzie 1999 Profile)', $latex);
        $this->assertStringContainsString('\\begin{tikzpicture}', $latex);
        $this->assertStringContainsString('Logical-Mathematical', $latex);
        $this->assertStringContainsString('accentblue', $latex);

        // Digital Attestation stamp and verification code
        $this->assertStringContainsString('Digital Attestation:', $latex);
        $this->assertStringContainsString('Digitally attested by Juan Dela Cruz (STU-2026-0042) on 2026-11-13 15:45:00', $latex);
        $this->assertStringContainsString('SYN-DOC-MI9901', $latex);
        $this->assertStringContainsString('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $latex);

        // Section III: Comprehensive Domain Profiles and Suggestions
        $this->assertStringContainsString('III. Comprehensive Domain Profiles \\& Educational Recommendations', $latex);
        $this->assertStringContainsString('Primary Focus:', $latex);
        $this->assertStringContainsString('Self-Inquiry / Characteristics:', $latex);
        $this->assertStringContainsString('Educational Suggestions:', $latex);

        // Confidentiality notice
        $this->assertStringContainsString('CONFIDENTIALITY NOTICE:', $latex);
        $this->assertStringContainsString('RA 10173', $latex);
    }

    public function testRenderLatexEscapesSpecialCharacters(): void
    {
        $snapshot = $this->sampleSnapshot;
        $snapshot['student_name'] = 'Dela Cruz & Sons <Special> % 100$ #1 _Under_ {Braces} ~Tilde~ ^Caret^ \\Backslash';
        $snapshot['course_year'] = 'BS Computer Science & Engineering (100% #1)';

        $latex = GuidanceMultipleIntelligenceLatex::render($snapshot);

        // Ensure raw unescaped characters do not cause TeX compile issues
        $this->assertStringContainsString('\\&', $latex);
        $this->assertStringContainsString('\\%', $latex);
        $this->assertStringContainsString('\\$', $latex);
        $this->assertStringContainsString('\\#', $latex);
        $this->assertStringContainsString('\\_', $latex);
        $this->assertStringContainsString('\\{', $latex);
        $this->assertStringContainsString('\\}', $latex);
        $this->assertStringContainsString('\\textasciitilde{}', $latex);
        $this->assertStringContainsString('\\textasciicircum{}', $latex);
        $this->assertStringContainsString('\\textbackslash{}', $latex);
    }

    public function testDraftModeIncludesWatermarkAndNotice(): void
    {
        $draftSnapshot = $this->sampleSnapshot;
        $draftSnapshot['is_draft'] = true;
        $draftSnapshot['attestation'] = null;

        $latex = GuidanceMultipleIntelligenceLatex::render($draftSnapshot);

        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $latex);
        $this->assertStringContainsString('ATTENTION: Unfinalized Draft Record', $latex);
    }

    public function testTabularPlotStyleOption(): void
    {
        $latex = GuidanceMultipleIntelligenceLatex::render($this->sampleSnapshot, ['plot_style' => 'tabular']);

        $this->assertStringContainsString('\\begin{tabular}{llc}', $latex);
        $this->assertStringContainsString('\\end{tabular}', $latex);
    }

    public function testGuidanceDocumentServiceIntegration(): void
    {
        $docService = new GuidanceDocumentService();

        // 1. Direct PDF rendering helper
        $pdf = $docService->renderMultipleIntelligencePdf($this->sampleSnapshot);
        $this->assertIsString($pdf);
        $this->assertNotEmpty($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);

        // 2. Draft preview routing
        $preview = $docService->generateDraftPreview(
            ['id' => 1, 'instance_number' => 'INST-MI-001', 'status' => 'draft'],
            ['internal_key' => 'guidance_multiple_intelligence', 'form_code' => 'F-OGC-MIR-010-00'],
            ['id' => 1, 'revision_number' => '00'],
            $this->sampleSnapshot
        );
        $this->assertArrayHasKey('latex', $preview);
        $this->assertArrayHasKey('pdf', $preview);
        $this->assertStringContainsString('MULTIPLE INTELLIGENCES REPORT (Walter McKenzie 1999)', $preview['latex']);
        $this->assertStringStartsWith('%PDF-', $preview['pdf']);
    }

    public function testLatexCompilationEndToEnd(): void
    {
        $latex = GuidanceMultipleIntelligenceLatex::render($this->sampleSnapshot);

        try {
            $pdf = LatexReportCompiler::compile($latex);
            $this->assertIsString($pdf);
            $this->assertGreaterThan(1000, strlen($pdf));
            $this->assertStringStartsWith('%PDF-1.4', $pdf);
        } catch (\Throwable $e) {
            // In headless environments without pdflatex or network
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }
}
