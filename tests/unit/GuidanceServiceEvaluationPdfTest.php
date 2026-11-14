<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Reporting\GuidanceFormLatex;
use App\Services\Reporting\LatexReportCompiler;
use CodeIgniter\Test\CIUnitTestCase;

final class GuidanceServiceEvaluationPdfTest extends CIUnitTestCase
{
    private array $psychosocialAggregateSnapshot;
    private array $counselingAggregateSnapshot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->psychosocialAggregateSnapshot = [
            'cohort_met'              => true,
            'total_responses'         => 6,
            'minimum_required'        => 5,
            'evaluation_type'         => 'psychosocial_support_evaluation',
            'form_code'               => 'F-OGC-GPS-PEF-008-00',
            'provider_name'           => 'Prof. Alex Mercer, RGC',
            'academic_term'           => '1st Trimester',
            'academic_year'           => '2026-2027',
            'report_date'             => '2026-11-13',
            'verification_code'       => 'SYN-DOC-PEF-2026-01',
            'visit_type_distribution' => [
                'walk_in'   => 3,
                'called'    => 1,
                'referred'  => 1,
                'follow_up' => 1,
            ],
            'averages' => [
                'rating_welcoming'   => 4.83,
                'rating_empathy'     => 4.67,
                'rating_clarity'     => 4.50,
                'rating_helpfulness' => 4.83,
                'rating_environment' => 5.00,
                'overall_mean'       => 4.77,
            ],
            'boolean_rate' => 0.8333,
            'comments'     => [
                'The guidance office felt safe and welcoming from the moment I walked in.',
                'Clear and practical coping strategies were shared during the psychosocial support session.',
            ],
        ];

        $this->counselingAggregateSnapshot = [
            'cohort_met'        => true,
            'total_responses'   => 8,
            'minimum_required'  => 5,
            'evaluation_type'   => 'counseling_evaluation',
            'form_code'         => 'F-OGC-GCS-CEF-009-00',
            'provider_name'     => 'Dr. Maria Santos, RGC',
            'academic_term'     => '1st Trimester',
            'academic_year'     => '2026-2027',
            'report_date'       => '2026-11-13',
            'verification_code' => 'SYN-DOC-CEF-2026-02',
            'averages'          => [
                'rating_rapport'                 => 4.88,
                'rating_understanding'           => 4.75,
                'rating_goal_progress'           => 4.50,
                'rating_confidentiality_comfort' => 5.00,
                'rating_overall_service'         => 4.88,
                'overall_mean'                   => 4.80,
            ],
            'boolean_rate' => 0.875,
            'comments'     => [
                'My counselor helped me work through academic stress with empathy and structure.',
                'I felt my confidentiality was completely respected throughout our counseling sessions.',
            ],
        ];
    }

    public function testRenderPsychosocialEvaluationAggregateThresholdMet(): void
    {
        $latex = GuidanceFormLatex::renderPsychosocialEvaluation($this->psychosocialAggregateSnapshot);

        // Canonical metadata & OGC branding
        $this->assertStringContainsString('FOUNDATION UNIVERSITY', $latex);
        $this->assertStringContainsString('OFFICE OF THE GUIDANCE CENTER', $latex);
        $this->assertStringContainsString('F-OGC-GPS-PEF-008-00', $latex);
        $this->assertStringContainsString('Psychosocial Support Evaluation Form', $latex);
        $this->assertStringContainsString('00', $latex);
        $this->assertStringContainsString('September 1, 2025', $latex);
        $this->assertStringContainsString('2025-09-01', $latex);

        // Aggregate summary mode banner
        $this->assertStringContainsString('AGGREGATE SUMMARY REPORT MODE', $latex);
        $this->assertStringContainsString('Prof. Alex Mercer, RGC', $latex);
        $this->assertStringContainsString('Total Responses:} 6', $latex);

        // Visit type distribution
        $this->assertStringContainsString('walk\\_in', $latex);
        $this->assertStringContainsString('called', $latex);
        $this->assertStringContainsString('referred', $latex);
        $this->assertStringContainsString('follow\\_up', $latex);

        // 5 rating dimensions & overall mean
        $this->assertStringContainsString('rating\\_welcoming', $latex);
        $this->assertStringContainsString('rating\\_empathy', $latex);
        $this->assertStringContainsString('rating\\_clarity', $latex);
        $this->assertStringContainsString('rating\\_helpfulness', $latex);
        $this->assertStringContainsString('rating\\_environment', $latex);
        $this->assertStringContainsString('overall\\_mean', $latex);
        $this->assertStringContainsString('4.83 / 5.00', $latex);
        $this->assertStringContainsString('4.77 / 5.00', $latex);

        // Willingness to visit again (boolean_rate)
        $this->assertStringContainsString('future\\_visit\\_willingness', $latex);
        $this->assertStringContainsString('83.3\\%', $latex);

        // Approved qualitative comments
        $this->assertStringContainsString('The guidance office felt safe and welcoming', $latex);
        $this->assertStringContainsString('Clear and practical coping strategies', $latex);

        // Privacy / unlinkability notice and verification code
        $this->assertStringContainsString('RA 10173', $latex);
        $this->assertStringContainsString('pseudonymous and cryptographically unlinked', $latex);
        $this->assertStringContainsString('SYN-DOC-PEF-2026-01', $latex);
    }

    public function testRenderPsychosocialEvaluationSuppressedAndTemplateStates(): void
    {
        // 1. Threshold-suppressed aggregate state (cohort_met === false)
        $suppressedSnapshot = array_merge($this->psychosocialAggregateSnapshot, [
            'cohort_met'      => false,
            'total_responses' => 3,
            'averages'        => null,
            'boolean_rate'    => null,
            'comments'        => ['Should never appear when cohort_met is false'],
        ]);

        $suppressedLatex = GuidanceFormLatex::renderPsychosocialEvaluation($suppressedSnapshot);
        $this->assertStringContainsString('COHORT THRESHOLD SUPPRESSION NOTICE', $suppressedLatex);
        $this->assertStringContainsString('SUPPRESSED', $suppressedLatex);
        $this->assertStringContainsString('COHORT SUPPRESSION ACTIVE', $suppressedLatex);
        $this->assertStringNotContainsString('Should never appear when cohort_met is false', $suppressedLatex);
        $this->assertStringNotContainsString('4.83 / 5.00', $suppressedLatex);

        // 2. Form template / preview state (no cohort_met)
        $templateSnapshot = [
            'provider_name'            => 'Prof. Alex Mercer, RGC',
            'academic_term'            => '1st Trimester',
            'academic_year'            => '2026-2027',
            'visit_type'               => 'walk_in',
            'rating_welcoming'         => 5,
            'rating_empathy'           => 4,
            'rating_clarity'           => 5,
            'rating_helpfulness'       => 5,
            'rating_environment'       => 5,
            'future_visit_willingness' => true,
            'qualitative_comments'     => 'Sample preview feedback on psychosocial support.',
            'is_draft'                 => true,
        ];

        $templateLatex = GuidanceFormLatex::renderPsychosocialEvaluation($templateSnapshot);
        $this->assertStringContainsString('F-OGC-GPS-PEF-008-00', $templateLatex);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $templateLatex);
        $this->assertStringContainsString('$\\boxtimes$ Voluntarily came / Walk-in', $templateLatex);
        $this->assertStringContainsString('$\\boxtimes$ \\textbf{Yes}', $templateLatex);
        $this->assertStringContainsString('Sample preview feedback on psychosocial support.', $templateLatex);
    }

    public function testRenderCounselingEvaluationAggregateThresholdMet(): void
    {
        $latex = GuidanceFormLatex::renderCounselingEvaluation($this->counselingAggregateSnapshot);

        // Canonical metadata & OGC branding
        $this->assertStringContainsString('FOUNDATION UNIVERSITY', $latex);
        $this->assertStringContainsString('OFFICE OF THE GUIDANCE CENTER', $latex);
        $this->assertStringContainsString('F-OGC-GCS-CEF-009-00', $latex);
        $this->assertStringContainsString('Counseling Evaluation Form', $latex);
        $this->assertStringContainsString('00', $latex);
        $this->assertStringContainsString('September 1, 2025', $latex);
        $this->assertStringContainsString('2025-09-01', $latex);

        // Aggregate summary mode banner
        $this->assertStringContainsString('AGGREGATE SUMMARY REPORT MODE', $latex);
        $this->assertStringContainsString('Dr. Maria Santos, RGC', $latex);
        $this->assertStringContainsString('Total Responses:} 8', $latex);

        // 5 counseling rating dimensions & overall mean
        $this->assertStringContainsString('rating\\_rapport', $latex);
        $this->assertStringContainsString('rating\\_understanding', $latex);
        $this->assertStringContainsString('rating\\_goal\\_progress', $latex);
        $this->assertStringContainsString('rating\\_confidentiality\\_comfort', $latex);
        $this->assertStringContainsString('rating\\_overall\\_service', $latex);
        $this->assertStringContainsString('overall\\_mean', $latex);
        $this->assertStringContainsString('4.88 / 5.00', $latex);
        $this->assertStringContainsString('4.80 / 5.00', $latex);

        // Service recommendation rate
        $this->assertStringContainsString('recommend\\_service', $latex);
        $this->assertStringContainsString('87.5\\%', $latex);

        // Approved qualitative feedback
        $this->assertStringContainsString('My counselor helped me work through academic stress', $latex);
        $this->assertStringContainsString('confidentiality was completely respected', $latex);

        // Privacy / pseudonymity notice and verification code
        $this->assertStringContainsString('RA 10173', $latex);
        $this->assertStringContainsString('SYN-DOC-CEF-2026-02', $latex);
    }

    public function testRenderCounselingEvaluationSuppressedAndTemplateStates(): void
    {
        // 1. Threshold-suppressed aggregate state
        $suppressedSnapshot = array_merge($this->counselingAggregateSnapshot, [
            'cohort_met'      => false,
            'total_responses' => 2,
            'averages'        => null,
            'boolean_rate'    => null,
            'comments'        => ['Unapproved leak test'],
        ]);

        $suppressedLatex = GuidanceFormLatex::renderCounselingEvaluation($suppressedSnapshot);
        $this->assertStringContainsString('COHORT THRESHOLD SUPPRESSION NOTICE', $suppressedLatex);
        $this->assertStringContainsString('SUPPRESSED', $suppressedLatex);
        $this->assertStringContainsString('COHORT SUPPRESSION ACTIVE', $suppressedLatex);
        $this->assertStringNotContainsString('Unapproved leak test', $suppressedLatex);

        // 2. Form template / preview state
        $templateSnapshot = [
            'provider_name'                  => 'Dr. Maria Santos, RGC',
            'rating_rapport'                 => 5,
            'rating_understanding'           => 5,
            'rating_goal_progress'           => 4,
            'rating_confidentiality_comfort' => 5,
            'rating_overall_service'         => 5,
            'recommend_service'              => true,
            'qualitative_feedback'           => 'Template preview counseling feedback.',
            'is_draft'                       => true,
        ];

        $templateLatex = GuidanceFormLatex::renderCounselingEvaluation($templateSnapshot);
        $this->assertStringContainsString('F-OGC-GCS-CEF-009-00', $templateLatex);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $templateLatex);
        $this->assertStringContainsString('$\\boxtimes$ \\textbf{Yes}', $templateLatex);
        $this->assertStringContainsString('Template preview counseling feedback.', $templateLatex);
    }

    public function testEscapingAndModerationFilteringInEvaluationRenderer(): void
    {
        $dangerousComment = 'Feedback #1: 100% helpful & supportive_session with $0 fee {great} ~tilde~ ^caret^ \\slash';
        $snapshot = array_merge($this->psychosocialAggregateSnapshot, [
            'provider_name' => 'Dr. Smith & Associates #1 (100% Care_Unit)',
            'comments'      => [
                ['text' => $dangerousComment, 'comment_moderation_status' => 'approved'],
                ['text' => 'Pending unmoderated comment', 'comment_moderation_status' => 'pending_review'],
                ['text' => 'Redacted sensitive comment', 'comment_moderation_status' => 'redacted'],
            ],
        ]);

        $latex = GuidanceFormLatex::renderPsychosocialEvaluation($snapshot);

        // Verify special characters are escaped
        $this->assertStringContainsString('Dr. Smith \\& Associates \\#1 (100\\% Care\\_Unit)', $latex);
        $this->assertStringContainsString('Feedback \\#1: 100\\% helpful \\& supportive\\_session', $latex);
        $this->assertStringContainsString('\\$0 fee \\{great\\}', $latex);
        $this->assertStringContainsString('\\textasciitilde{}tilde\\textasciitilde{}', $latex);
        $this->assertStringContainsString('\\textasciicircum{}caret\\textasciicircum{}', $latex);
        $this->assertStringContainsString('\\textbackslash{}slash', $latex);

        // Verify unmoderated/redacted structured comments are excluded
        $this->assertStringNotContainsString('Pending unmoderated comment', $latex);
        $this->assertStringNotContainsString('Redacted sensitive comment', $latex);
    }

    public function testGuidanceFormLatexDelegationAndDocumentServiceHelpers(): void
    {
        $instance = ['id' => 70, 'instance_number' => 'EVAL-2026-001', 'status' => 'finalized'];
        $rev      = ['revision_number' => '00', 'effectivity_date' => '2025-09-01'];

        // 1. GuidanceFormLatex::render delegation
        $pefLatex = GuidanceFormLatex::render(
            $instance,
            ['internal_key' => 'psychosocial_support_evaluation', 'form_code' => 'F-OGC-GPS-PEF-008-00'],
            $rev,
            $this->psychosocialAggregateSnapshot,
            null,
            'SYN-DOC-PEF-DELEGATED',
            false
        );
        $this->assertStringContainsString('F-OGC-GPS-PEF-008-00', $pefLatex);
        $this->assertStringContainsString('SYN-DOC-PEF-DELEGATED', $pefLatex);

        $cefLatex = GuidanceFormLatex::render(
            $instance,
            ['internal_key' => 'counseling_evaluation', 'form_code' => 'F-OGC-GCS-CEF-009-00'],
            $rev,
            $this->counselingAggregateSnapshot,
            null,
            'SYN-DOC-CEF-DELEGATED',
            false
        );
        $this->assertStringContainsString('F-OGC-GCS-CEF-009-00', $cefLatex);
        $this->assertStringContainsString('SYN-DOC-CEF-DELEGATED', $cefLatex);

        // 2. GuidanceDocumentService direct PDF helpers
        $docService = new GuidanceDocumentService();

        $pefPdf = $docService->renderPsychosocialEvaluationPdf($this->psychosocialAggregateSnapshot);
        $this->assertIsString($pefPdf);
        $this->assertNotEmpty($pefPdf);
        $this->assertStringStartsWith('%PDF-', $pefPdf);

        $cefPdf = $docService->renderCounselingEvaluationPdf($this->counselingAggregateSnapshot);
        $this->assertIsString($cefPdf);
        $this->assertNotEmpty($cefPdf);
        $this->assertStringStartsWith('%PDF-', $cefPdf);

        // 3. GuidanceDocumentService::generateDraftPreview routing
        $pefPreview = $docService->generateDraftPreview(
            ['id' => 71, 'instance_number' => 'EVAL-DRAFT-01', 'status' => 'draft'],
            ['internal_key' => 'psychosocial_support_evaluation', 'form_code' => 'F-OGC-GPS-PEF-008-00'],
            $rev,
            $this->psychosocialAggregateSnapshot
        );
        $this->assertStringContainsString('F-OGC-GPS-PEF-008-00', $pefPreview['latex']);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $pefPreview['latex']);
        $this->assertStringStartsWith('%PDF-', $pefPreview['pdf']);

        $cefPreview = $docService->generateDraftPreview(
            ['id' => 72, 'instance_number' => 'EVAL-DRAFT-02', 'status' => 'draft'],
            ['internal_key' => 'counseling_evaluation', 'form_code' => 'F-OGC-GCS-CEF-009-00'],
            $rev,
            $this->counselingAggregateSnapshot
        );
        $this->assertStringContainsString('F-OGC-GCS-CEF-009-00', $cefPreview['latex']);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $cefPreview['latex']);
        $this->assertStringStartsWith('%PDF-', $cefPreview['pdf']);
    }

    public function testLatexCompilationEndToEnd(): void
    {
        $pefAggregateTex = GuidanceFormLatex::renderPsychosocialEvaluation($this->psychosocialAggregateSnapshot);
        $pefSuppressedTex = GuidanceFormLatex::renderPsychosocialEvaluation([
            'cohort_met'      => false,
            'total_responses' => 2,
        ]);
        $cefAggregateTex = GuidanceFormLatex::renderCounselingEvaluation($this->counselingAggregateSnapshot);
        $cefSuppressedTex = GuidanceFormLatex::renderCounselingEvaluation([
            'cohort_met'      => false,
            'total_responses' => 1,
        ]);

        foreach ([$pefAggregateTex, $pefSuppressedTex, $cefAggregateTex, $cefSuppressedTex] as $tex) {
            try {
                $pdf = LatexReportCompiler::compile($tex);
                $this->assertIsString($pdf);
                $this->assertGreaterThan(1000, strlen($pdf));
                $this->assertStringStartsWith('%PDF-', $pdf);
            } catch (\Throwable $e) {
                $this->assertInstanceOf(\RuntimeException::class, $e);
            }
        }
    }
}
