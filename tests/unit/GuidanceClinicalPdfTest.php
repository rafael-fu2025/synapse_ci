<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\GuidanceClinicalController;
use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Reporting\GuidanceFormLatex;
use App\Services\Reporting\LatexReportCompiler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ReportExport;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;

final class GuidanceClinicalPdfTest extends CIUnitTestCase
{
    private const PRIVATE_MARKER = 'SYNTHETIC CLINICAL PDF PRIVATE MARKER';

    private array $sampleInterviewData;
    private array $samplePsychosocialContactData;
    private array $sampleCounselingContactData;
    private array $authorAttestation;
    private array $cosignerAttestation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sampleInterviewData = [
            'client_type'                  => 'walk_in',
            'interview_date'               => '2026-11-20',
            'interview_time'               => '09:30 AM',
            'student_name'                 => 'Juan Dela Cruz & Maria Clara',
            'student_id'                   => 'STU-2026-0042',
            'course_year'                  => 'BS Psychology - 3rd Year (Section A_1)',
            'presenting_concerns'          => 'Student reports recurring anxiety, difficulty sleeping, and academic stress regarding 50% midterm requirements.',
            'concern_severity'             => 'serious',
            'problem_duration'             => 'Approximately three weeks since midterm examinations began.',
            'perceived_cause'              => 'Heavy coursework overlap and irregular rest schedule.',
            'coping_barriers'              => 'Difficulty disconnecting from deadlines late at night.',
            'prior_coping_attempts'        => 'Tried organizing daily checklists and talking with classmates.',
            'desired_help'                 => 'Supportive counseling and practical stress-management strategies.',
            'hoped_changes'                => 'Consistent sleep pattern and improved focus on academic tasks.',
            'privacy_consent_acknowledged' => true,
        ];

        $this->samplePsychosocialContactData = [
            'department'                   => 'College of Arts and Sciences',
            'session_date'                 => '2026-11-25',
            'student_name'                 => 'Maria Clara Santos',
            'student_id'                   => 'STU-2026-0042',
            'course_year'                  => 'BS Psychology - 3rd Year',
            'time_started'                 => '10:00 AM',
            'time_ended'                   => '11:00 AM',
            'referral_source'              => 'counselor_associate_initiated',
            'session_type'                 => 'follow_up',
            'presenting_concerns'          => 'Student reports midterm academic workload pressure and disturbed sleep.',
            'predisposing_factors'         => 'History of high self-expectation during major exam weeks.',
            'precipitating_factors'        => 'Three overlapping course projects due in the same week.',
            'perpetuating_factors'         => 'Irregular late-night study habits and skipped rest breaks.',
            'protective_risk_factors'      => 'Strong family support and active peer study circle.',
            'intervention_plan'            => 'Active listening, review of sleep diary, and progressive muscle relaxation coaching.',
            'follow_up_plan'               => 'Continue sleep schedule tracking and follow up next week.',
            'next_session_schedule'        => '2026-12-02 10:00 AM',
            'privacy_consent_acknowledged' => true,
        ];

        $this->sampleCounselingContactData = [
            'department'                   => 'College of Engineering',
            'session_date'                 => '2026-11-22',
            'student_name'                 => 'Crisostomo Ibarra',
            'student_id'                   => 'STU-2026-0099',
            'course_year'                  => 'BS Civil Engineering - 4th Year',
            'time_started'                 => '02:00 PM',
            'time_ended'                   => '03:15 PM',
            'referral_source'              => 'counselor_initiated',
            'session_type'                 => 'intake',
            'presenting_concerns'          => 'Career uncertainty, impending graduation anxiety, and interpersonal conflicts with thesis partner.',
            'predisposing_factors'         => 'Perfectionism and pressure as first-generation engineering student.',
            'precipitating_factors'        => 'Upcoming thesis final defense and thesis partner communication breakdown.',
            'perpetuating_factors'         => 'Avoidance of assertive conversation and late-night rumination.',
            'protective_risk_factors'      => 'Strong academic standing and supportive sibling network.',
            'intervention_plan'            => 'Brief solution-focused counseling; role-playing assertiveness communication. Client actively engaged and receptive.',
            'follow_up_plan'               => 'Conduct assertiveness dialogue with thesis peer and review progress next session.',
            'next_session_schedule'        => '2026-11-29 02:00 PM',
            'privacy_consent_acknowledged' => true,
        ];

        $this->authorAttestation = [
            'signer_name' => 'Prof. Alex Mercer, RGC',
            'signer_role' => 'Licensed Guidance Counselor',
            'attested_at' => '2026-11-13 14:30:00',
            'snapshot_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ];

        $this->cosignerAttestation = [
            'signer_name' => 'Dr. Maria Santos, RGC',
            'signer_role' => 'Guidance Supervisor',
            'attested_at' => '2026-11-13 16:15:00',
            'snapshot_hash' => '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918',
        ];
    }

    public function testRenderPsychosocialInterviewDirectFinalization(): void
    {
        $snapshot = [
            'data' => $this->sampleInterviewData,
            'instance_number' => 'PIF-2026-001',
            'verification_code' => 'SYN-DOC-PIF-1001',
            'attestation' => $this->authorAttestation,
            'is_draft' => false,
        ];

        $latex = GuidanceFormLatex::renderPsychosocialInterview($snapshot);

        // Canonical codes and metadata
        $this->assertStringContainsString('F-OGC-GPS-PIF-008-00', $latex);
        $this->assertStringContainsString('Psychosocial Support Interview Form', $latex);
        $this->assertStringContainsString('00', $latex);
        $this->assertStringContainsString('September 1, 2025', $latex);
        $this->assertStringContainsString('2025-09-01', $latex);

        // Header / OGC branding & framed dual-logo container without duplicated Canonical Code
        $this->assertStringContainsString('FOUNDATION UNIVERSITY', $latex);
        $this->assertStringContainsString('OFFICE OF THE GUIDANCE CENTER', $latex);
        $this->assertStringContainsString('\\pdfminorversion=4', $latex);
        $this->assertStringContainsString('\\setlength{\\parindent}{0pt}', $latex);
        $this->assertStringContainsString('\\fcolorbox{HeaderBorder}{HeaderBg}', $latex);
        $this->assertStringContainsString('university-seal.png', $latex);
        $this->assertStringContainsString('guidance-logo.png', $latex);
        $this->assertStringContainsString('\\fancyhead[R]{\\small \\textbf{Form Code:} F-OGC-GPS-PIF-008-00}', $latex);
        $this->assertStringNotContainsString('Canonical Code:', $latex);

        // Data Privacy Notice (RA 10173), Client Information & 8 Paper Questions
        $this->assertStringContainsString('Data Privacy Act of 2012 (RA 10173)', $latex);
        $this->assertStringContainsString('dpo@foundationu.com', $latex);
        $this->assertStringContainsString('Juan Dela Cruz \\& Maria Clara', $latex);
        $this->assertStringContainsString('STU-2026-0042', $latex);
        $this->assertStringContainsString('BS Psychology - 3rd Year (Section A\\_1)', $latex);
        $this->assertStringContainsString('2026-11-20 09:30 AM', $latex);
        $this->assertStringContainsString('$\\boxtimes$ Walk-in', $latex);

        // Questions 1-8 matching 8.1. F-OGC-GPS-PIF-008-00
        $this->assertStringContainsString('1. ISSUES/CONCERNS', $latex);
        $this->assertStringContainsString('50\\% midterm requirements', $latex);
        $this->assertStringContainsString('2. How serious are these matters for you at this time?', $latex);
        $this->assertStringContainsString('$\\boxtimes$ Serious', $latex);
        $this->assertStringContainsString('3. How long have these been your problem?', $latex);
        $this->assertStringContainsString('Approximately three weeks', $latex);
        $this->assertStringContainsString('4. What do you think originally caused these problem/s?', $latex);
        $this->assertStringContainsString('5. What other things currently making it hard to deal with the problem/s?', $latex);
        $this->assertStringContainsString('6. What have you already tried in order to deal with the problem/s?', $latex);
        $this->assertStringContainsString('7. What do you think would help solve the problem/s? What type of help do you want?', $latex);
        $this->assertStringContainsString('8. What changes are you hoping for?', $latex);

        // Fabricated sections with no basis on original PIF-008 paper must never appear
        $this->assertStringNotContainsString('Mental Health Screening', $latex);
        $this->assertStringNotContainsString('PHQ-9', $latex);
        $this->assertStringNotContainsString('GAD-7', $latex);
        $this->assertStringNotContainsString('Civil Status', $latex);
        $this->assertStringNotContainsString('Emergency Contact', $latex);
        $this->assertStringNotContainsString('Counselor Assessment', $latex);

        // Attestation stamp: Author line and verification code
        $this->assertStringContainsString('Prof. Alex Mercer, RGC', $latex);
        $this->assertStringContainsString('Licensed Guidance Counselor', $latex);
        $this->assertStringContainsString('2026-11-13 14:30:00', $latex);
        $this->assertStringContainsString('SYN-DOC-PIF-1001', $latex);

        // Confidentiality notice
        $this->assertStringContainsString('RA 10173', $latex);
        $this->assertStringNotContainsString('DRAFT - PREVIEW ONLY', $latex);
    }

    public function testRenderPsychosocialInterviewCoSigned(): void
    {
        $associateAttestation = [
            'signer_name' => 'Jane Associate',
            'signer_role' => 'Associate Counselor',
            'attested_at' => '2026-11-13 13:00:00',
            'snapshot_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ];

        $snapshot = [
            'data' => $this->sampleInterviewData,
            'instance_number' => 'PIF-2026-002',
            'verification_code' => 'SYN-DOC-PIF-COSIGN',
            'attestation' => $associateAttestation,
            'cosigner_attestation' => $this->cosignerAttestation,
            'is_draft' => false,
        ];

        $latex = GuidanceFormLatex::renderPsychosocialInterview($snapshot);

        // Author line
        $this->assertStringContainsString('Jane Associate', $latex);
        $this->assertStringContainsString('Associate Counselor', $latex);

        // Co-signer line: "Digitally co-signed by [Supervisor] on [DateTime]"
        $this->assertStringContainsString('Digitally co-signed by Dr. Maria Santos, RGC on 2026-11-13 16:15:00', $latex);
        $this->assertStringContainsString('SYN-DOC-PIF-COSIGN', $latex);
    }

    public function testRenderPsychosocialContact(): void
    {
        $snapshot = [
            'data' => $this->samplePsychosocialContactData,
            'instance_number' => 'PCF-2026-0045',
            'verification_code' => 'SYN-DOC-PCF-777',
            'attestation' => $this->authorAttestation,
            'cosigner_attestation' => $this->cosignerAttestation,
            'is_draft' => false,
        ];

        $latex = GuidanceFormLatex::renderPsychosocialContact($snapshot);

        // Canonical code & title
        $this->assertStringContainsString('F-OGC-GPS-PCF-008-00', $latex);
        $this->assertStringContainsString('Psychosocial Support Contact Form', $latex);
        $this->assertStringContainsString('September 1, 2025', $latex);
        $this->assertStringContainsString('2025-09-01', $latex);

        // Privacy Notice (RA 10173) & Session / Student details
        $this->assertStringContainsString('Data Privacy Act of 2012 (RA 10173)', $latex);
        $this->assertStringContainsString('dpo@foundationu.com', $latex);
        $this->assertStringContainsString('Maria Clara Santos', $latex);
        $this->assertStringContainsString('STU-2026-0042', $latex);
        $this->assertStringContainsString('BS Psychology - 3rd Year', $latex);
        $this->assertStringContainsString('College of Arts and Sciences', $latex);
        $this->assertStringContainsString('2026-11-25', $latex);
        $this->assertStringContainsString('10:00 AM -- 11:00 AM', $latex);
        $this->assertStringContainsString('$\\boxtimes$ Counselor Associate-initiated', $latex);
        $this->assertStringContainsString('$\\boxtimes$ Follow-up', $latex);

        // Summary of Psychosocial Support Session (5-P formulation) & Plans
        $this->assertStringContainsString('Summary of Psychosocial Support Session', $latex);
        $this->assertStringContainsString('Presenting Concern/s:', $latex);
        $this->assertStringContainsString('Student reports midterm academic workload pressure', $latex);
        $this->assertStringContainsString('Predisposing Factors:', $latex);
        $this->assertStringContainsString('History of high self-expectation', $latex);
        $this->assertStringContainsString('Precipitating Factors:', $latex);
        $this->assertStringContainsString('Perpetuating Factors:', $latex);
        $this->assertStringContainsString('Protective / Risk Factors:', $latex);
        $this->assertStringContainsString('Plans:', $latex);
        $this->assertStringContainsString('Intervention:', $latex);
        $this->assertStringContainsString('Active listening, review of sleep diary', $latex);
        $this->assertStringContainsString('Follow up:', $latex);
        $this->assertStringContainsString('Continue sleep schedule tracking', $latex);
        $this->assertStringContainsString('2026-12-02 10:00 AM', $latex);

        // Fabricated legacy PCF fields must not appear
        $this->assertStringNotContainsString('Session Modality:', $latex);
        $this->assertStringNotContainsString('Client State \\& Presentation', $latex);

        // Attestations
        $this->assertStringContainsString('Prof. Alex Mercer, RGC', $latex);
        $this->assertStringContainsString('Digitally co-signed by Dr. Maria Santos, RGC on 2026-11-13 16:15:00', $latex);
        $this->assertStringContainsString('SYN-DOC-PCF-777', $latex);
    }

    public function testRenderCounselingContact(): void
    {
        $snapshot = [
            'data' => $this->sampleCounselingContactData,
            'instance_number' => 'CCF-2026-0012',
            'verification_code' => 'SYN-DOC-CCF-888',
            'attestation' => $this->authorAttestation,
            'cosigner_attestation' => $this->cosignerAttestation,
            'is_draft' => false,
        ];

        $latex = GuidanceFormLatex::renderCounselingContact($snapshot);

        // Canonical code & title (and alternate title)
        $this->assertStringContainsString('F-OGC-GCS-CCF-009-00', $latex);
        $this->assertStringContainsString('Counseling Contact Sheet', $latex);
        $this->assertStringContainsString('Counseling Contact Form', $latex);
        $this->assertStringContainsString('September 1, 2025', $latex);
        $this->assertStringContainsString('2025-09-01', $latex);

        // Privacy Notice (RA 10173) & Session / Student details
        $this->assertStringContainsString('Data Privacy Act of 2012 (RA 10173)', $latex);
        $this->assertStringContainsString('dpo@foundationu.com', $latex);
        $this->assertStringContainsString('Crisostomo Ibarra', $latex);
        $this->assertStringContainsString('STU-2026-0099', $latex);
        $this->assertStringContainsString('BS Civil Engineering - 4th Year', $latex);
        $this->assertStringContainsString('College of Engineering', $latex);
        $this->assertStringContainsString('2026-11-22', $latex);
        $this->assertStringContainsString('02:00 PM -- 03:15 PM', $latex);
        $this->assertStringContainsString('$\\boxtimes$ Counselor-initiated', $latex);
        $this->assertStringContainsString('$\\boxtimes$ Intake', $latex);

        // Summary of Counseling Session (5-P formulation) & Plans
        $this->assertStringContainsString('Summary of Counseling Session', $latex);
        $this->assertStringContainsString('Presenting Concern/s:', $latex);
        $this->assertStringContainsString('Career uncertainty, impending graduation anxiety', $latex);
        $this->assertStringContainsString('Predisposing Factors:', $latex);
        $this->assertStringContainsString('Perfectionism and pressure', $latex);
        $this->assertStringContainsString('Precipitating Factors:', $latex);
        $this->assertStringContainsString('Perpetuating Factors:', $latex);
        $this->assertStringContainsString('Protective / Risk Factors:', $latex);
        $this->assertStringContainsString('Plans:', $latex);
        $this->assertStringContainsString('Intervention:', $latex);
        $this->assertStringContainsString('Brief solution-focused counseling', $latex);
        $this->assertStringContainsString('Follow up:', $latex);
        $this->assertStringContainsString('Conduct assertiveness dialogue with thesis peer', $latex);
        $this->assertStringContainsString('Next Session / Follow-Up', $latex);
        $this->assertStringContainsString('2026-11-29 02:00 PM', $latex);

        // Fabricated legacy CCF fields must not appear
        $this->assertStringNotContainsString('Counseling Goals', $latex);
        $this->assertStringNotContainsString('Session Number:', $latex);

        // Attestations
        $this->assertStringContainsString('Prof. Alex Mercer, RGC', $latex);
        $this->assertStringContainsString('Digitally co-signed by Dr. Maria Santos, RGC on 2026-11-13 16:15:00', $latex);
        $this->assertStringContainsString('SYN-DOC-CCF-888', $latex);
    }

    public function testDraftWatermarkAndPreviewAcrossClinicalForms(): void
    {
        $draftInterview = GuidanceFormLatex::renderPsychosocialInterview([
            'data' => $this->sampleInterviewData,
            'is_draft' => true,
        ]);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $draftInterview);
        $this->assertStringContainsString('Unfinalized draft record', $draftInterview);
        $this->assertStringContainsString('Provisional Draft', $draftInterview);

        $draftPsychosocial = GuidanceFormLatex::renderPsychosocialContact([
            'data' => $this->samplePsychosocialContactData,
            'is_draft' => true,
        ]);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $draftPsychosocial);
        $this->assertStringContainsString('Unfinalized draft record', $draftPsychosocial);

        $draftCounseling = GuidanceFormLatex::renderCounselingContact([
            'data' => $this->sampleCounselingContactData,
            'is_draft' => true,
        ]);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $draftCounseling);
        $this->assertStringContainsString('Unfinalized draft record', $draftCounseling);
    }

    public function testStrictLatexEscapingAcrossClinicalForms(): void
    {
        $rawDangerous = 'Risk #1: Client reports 100% emotional pain & distress_test with {braces} and $100 fee ~ ^ \\';
        $escaped = GuidanceFormLatex::escapeLatex($rawDangerous);

        $this->assertStringContainsString('\\#1', $escaped);
        $this->assertStringContainsString('100\\%', $escaped);
        $this->assertStringContainsString('\\&', $escaped);
        $this->assertStringContainsString('\\_test', $escaped);
        $this->assertStringContainsString('\\{braces\\}', $escaped);
        $this->assertStringContainsString('\\$100', $escaped);
        $this->assertStringContainsString('\\textasciitilde{}', $escaped);
        $this->assertStringContainsString('\\textasciicircum{}', $escaped);
        $this->assertStringContainsString('\\textbackslash{}', $escaped);

        $dangerousSnapshot = [
            'data' => array_merge($this->sampleInterviewData, [
                'presenting_concerns' => $rawDangerous,
                'student_name' => 'María José & Smith_Jones #4',
            ]),
            'is_draft' => true,
        ];

        $latex = GuidanceFormLatex::renderPsychosocialInterview($dangerousSnapshot);
        $this->assertMatchesRegularExpression('/Mar.?ia Jos.?e \\\\& Smith\\\\_Jones \\\\#4/', $latex);
        $this->assertStringContainsString('100\\%', $latex);
    }

    public static function clinicalPdfForms(): iterable
    {
        yield 'psychosocial interview' => [
            'renderPsychosocialInterviewPdf',
            ['internal_key' => 'psychosocial_support_interview', 'form_code' => 'F-OGC-GPS-PIF-008-00'],
            'sampleInterviewData',
        ];
        yield 'psychosocial contact' => [
            'renderPsychosocialContactPdf',
            ['internal_key' => 'psychosocial_support_contact', 'form_code' => 'F-OGC-GPS-PCF-008-00'],
            'samplePsychosocialContactData',
        ];
        yield 'counseling contact' => [
            'renderCounselingContactPdf',
            ['internal_key' => 'counseling_contact_sheet', 'form_code' => 'F-OGC-GCS-CCF-009-00'],
            'sampleCounselingContactData',
        ];
    }

    #[DataProvider('clinicalPdfForms')]
    public function testClinicalPdfRenderersPropagateCompilerFailure(
        string $renderer,
        array $definition,
        string $dataProperty
    ): void {
        $service = (new ReflectionClass(GuidanceDocumentService::class))->newInstanceWithoutConstructor();
        $snapshot = [
            'definition' => $definition,
            'data' => array_merge($this->{$dataProperty}, ['student_name' => self::PRIVATE_MARKER]),
            'is_draft' => true,
        ];

        $this->assertCompilationUnavailable(static fn (): array => $service->{$renderer}($snapshot));
    }

    #[DataProvider('clinicalPdfForms')]
    public function testClinicalDraftPreviewPropagatesCompilerFailure(
        string $_renderer,
        array $definition,
        string $dataProperty
    ): void {
        $service = (new ReflectionClass(GuidanceDocumentService::class))->newInstanceWithoutConstructor();
        $instance = ['id' => 10, 'instance_number' => 'SYNTHETIC-CLINICAL-DRAFT', 'status' => 'draft'];
        $revision = ['revision_number' => '00', 'effectivity_date' => '2025-09-01'];
        $data = array_merge($this->{$dataProperty}, ['student_name' => self::PRIVATE_MARKER]);

        $this->assertCompilationUnavailable(
            static fn (): array => $service->generateDraftPreview($instance, $definition, $revision, $data)
        );
    }

    public function testClinicalPdfFailurePreserves503WithoutLeakingCompilerDetails(): void
    {
        $controller = (new ReflectionClass(GuidanceClinicalController::class))->newInstanceWithoutConstructor();
        $privatePath = '/synthetic/private/clinical/report.tex';
        $error = new \RuntimeException(self::PRIVATE_MARKER . ' at ' . $privatePath, 503,
            new \RuntimeException('Synthetic private compiler diagnostic'));

        [$status, $message] = (new ReflectionMethod($controller, 'safeFailure'))->invoke($controller, $error, 'clinical.pdf', 10);

        $this->assertSame(503, $status);
        $this->assertSame('PDF preview is unavailable because the document could not be generated. Please contact your administrator to check the PDF compiler and try again.', $message);
        $this->assertStringNotContainsString(self::PRIVATE_MARKER, $message);
        $this->assertStringNotContainsString($privatePath, $message);
        $this->assertStringNotContainsString('Synthetic private compiler diagnostic', $message);
    }

    public function testClinicalNonPdfFailureKeepsGeneric503Message(): void
    {
        $controller = (new ReflectionClass(GuidanceClinicalController::class))->newInstanceWithoutConstructor();
        $error = new \RuntimeException(self::PRIVATE_MARKER, 503);

        [$status, $message] = (new ReflectionMethod($controller, 'safeFailure'))->invoke($controller, $error, 'clinical.save', 10);

        $this->assertSame(503, $status);
        $this->assertSame('Unable to complete the clinical form operation. Please try again later.', $message);
        $this->assertStringNotContainsString(self::PRIVATE_MARKER, $message);
    }

    private function assertCompilationUnavailable(callable $render): void
    {
        $config = config(ReportExport::class);
        $originalPdflatex = $config->pdflatex;
        $temporaryDirectory = realpath(sys_get_temp_dir());
        $this->assertIsString($temporaryDirectory);
        $missingDirectory = $temporaryDirectory . DIRECTORY_SEPARATOR . 'guidance-clinical-missing-' . bin2hex(random_bytes(16));
        $missingCompiler = $missingDirectory . DIRECTORY_SEPARATOR . 'pdflatex.exe';
        $this->assertDirectoryDoesNotExist($missingDirectory);
        $this->assertFileDoesNotExist($missingCompiler);

        try {
            $config->pdflatex = $missingCompiler;
            try {
                $render();
            } catch (\RuntimeException $error) {
                $this->assertSame(503, $error->getCode());
                $this->assertSame('PDF report compilation is unavailable.', $error->getMessage());
                $this->assertNull($error->getPrevious());
                $this->assertStringNotContainsString(self::PRIVATE_MARKER, $error->getMessage());
                $this->assertStringNotContainsString($missingCompiler, $error->getMessage());
                return;
            }

            $this->fail('Compiler failure must propagate instead of returning placeholder PDF bytes.');
        } finally {
            $config->pdflatex = $originalPdflatex;
        }
    }

    private function assertCompiledPdf(string $pdf, ?string $sha256 = null): void
    {
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('/startxref\s+[0-9]+\s+%%EOF\s*\z/', $pdf);
        if ($sha256 !== null) {
            $this->assertSame(hash('sha256', $pdf), $sha256);
        }
    }

    public function testGuidanceDocumentServiceIntegrationForClinicalForms(): void
    {
        $service = (new ReflectionClass(GuidanceDocumentService::class))->newInstanceWithoutConstructor();

        // 1. Interview PDF helper
        $interviewRes = $service->renderPsychosocialInterviewPdf([
            'data' => $this->sampleInterviewData,
            'is_draft' => true,
        ]);
        $this->assertNotEmpty($interviewRes['latex']);
        $this->assertCompiledPdf($interviewRes['pdf'], $interviewRes['pdf_sha256']);
        $this->assertStringContainsString('F-OGC-GPS-PIF-008-00', $interviewRes['latex']);

        // 2. Psychosocial Contact PDF helper
        $pcfRes = $service->renderPsychosocialContactPdf([
            'data' => $this->samplePsychosocialContactData,
            'is_draft' => true,
        ]);
        $this->assertNotEmpty($pcfRes['latex']);
        $this->assertCompiledPdf($pcfRes['pdf'], $pcfRes['pdf_sha256']);
        $this->assertStringContainsString('F-OGC-GPS-PCF-008-00', $pcfRes['latex']);

        // 3. Counseling Contact PDF helper
        $ccfRes = $service->renderCounselingContactPdf([
            'data' => $this->sampleCounselingContactData,
            'is_draft' => true,
        ]);
        $this->assertNotEmpty($ccfRes['latex']);
        $this->assertCompiledPdf($ccfRes['pdf'], $ccfRes['pdf_sha256']);
        $this->assertStringContainsString('F-OGC-GCS-CCF-009-00', $ccfRes['latex']);

        // 4. Draft preview routing
        $instance = ['id' => 10, 'instance_number' => 'INST-2026-001', 'status' => 'draft'];
        $rev = ['revision_number' => '00', 'effectivity_date' => '2025-09-01'];

        $pifPreview = $service->generateDraftPreview(
            $instance,
            ['internal_key' => 'psychosocial_support_interview', 'form_code' => 'F-OGC-GPS-PIF-008-00'],
            $rev,
            $this->sampleInterviewData
        );
        $this->assertStringContainsString('F-OGC-GPS-PIF-008-00', $pifPreview['latex']);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $pifPreview['latex']);
        $this->assertCompiledPdf($pifPreview['pdf'], $pifPreview['pdf_sha256']);

        $pcfPreview = $service->generateDraftPreview(
            $instance,
            ['internal_key' => 'psychosocial_support_contact', 'form_code' => 'F-OGC-GPS-PCF-008-00'],
            $rev,
            $this->samplePsychosocialContactData
        );
        $this->assertStringContainsString('F-OGC-GPS-PCF-008-00', $pcfPreview['latex']);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $pcfPreview['latex']);
        $this->assertCompiledPdf($pcfPreview['pdf'], $pcfPreview['pdf_sha256']);

        $ccfPreview = $service->generateDraftPreview(
            $instance,
            ['internal_key' => 'counseling_contact_sheet', 'form_code' => 'F-OGC-GCS-CCF-009-00'],
            $rev,
            $this->sampleCounselingContactData
        );
        $this->assertStringContainsString('F-OGC-GCS-CCF-009-00', $ccfPreview['latex']);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $ccfPreview['latex']);
        $this->assertCompiledPdf($ccfPreview['pdf'], $ccfPreview['pdf_sha256']);
    }

    public function testGuidanceFormLatexRenderDelegation(): void
    {
        $instance = ['id' => 1, 'instance_number' => 'OGC-2026-CLIN-01', 'status' => 'finalized'];
        $rev = ['revision_number' => '00', 'effectivity_date' => 'September 1, 2025'];

        $renderedPif = GuidanceFormLatex::render(
            $instance,
            ['internal_key' => 'psychosocial_support_interview', 'form_code' => 'F-OGC-GPS-PIF-008-00'],
            $rev,
            $this->sampleInterviewData,
            $this->authorAttestation,
            'SYN-DOC-TEST-PIF',
            false
        );
        $this->assertStringContainsString('F-OGC-GPS-PIF-008-00', $renderedPif);
        $this->assertStringContainsString('SYN-DOC-TEST-PIF', $renderedPif);

        $renderedPcf = GuidanceFormLatex::render(
            $instance,
            ['internal_key' => 'psychosocial_support_contact', 'form_code' => 'F-OGC-GPS-PCF-008-00'],
            $rev,
            $this->samplePsychosocialContactData,
            $this->authorAttestation,
            'SYN-DOC-TEST-PCF',
            false
        );
        $this->assertStringContainsString('F-OGC-GPS-PCF-008-00', $renderedPcf);
        $this->assertStringContainsString('SYN-DOC-TEST-PCF', $renderedPcf);

        $renderedCcf = GuidanceFormLatex::render(
            $instance,
            ['internal_key' => 'counseling_contact_sheet', 'form_code' => 'F-OGC-GCS-CCF-009-00'],
            $rev,
            $this->sampleCounselingContactData,
            $this->authorAttestation,
            'SYN-DOC-TEST-CCF',
            false
        );
        $this->assertStringContainsString('F-OGC-GCS-CCF-009-00', $renderedCcf);
        $this->assertStringContainsString('SYN-DOC-TEST-CCF', $renderedCcf);
    }

    public function testLatexCompilation(): void
    {
        $pifTex = GuidanceFormLatex::renderPsychosocialInterview([
            'data' => $this->sampleInterviewData,
            'verification_code' => 'SYN-DOC-COMPILE-1',
            'attestation' => $this->authorAttestation,
        ]);
        $pcfTex = GuidanceFormLatex::renderPsychosocialContact([
            'data' => $this->samplePsychosocialContactData,
            'verification_code' => 'SYN-DOC-COMPILE-2',
            'attestation' => $this->authorAttestation,
        ]);
        $ccfTex = GuidanceFormLatex::renderCounselingContact([
            'data' => $this->sampleCounselingContactData,
            'verification_code' => 'SYN-DOC-COMPILE-3',
            'attestation' => $this->authorAttestation,
        ]);

        $this->assertCompiledPdf(LatexReportCompiler::compile($pifTex));
        $this->assertCompiledPdf(LatexReportCompiler::compile($pcfTex));
        $this->assertCompiledPdf(LatexReportCompiler::compile($ccfTex));
    }

    public function testPsychosocialInterviewExcludesFabricatedScreeningAndDemographics(): void
    {
        $snapshot = [
            'data' => array_merge($this->sampleInterviewData, [
                'mental_health_screening' => [
                    'phq9_score' => 4,
                    'gad7_score' => 3,
                    'risk_level' => 'low',
                ],
                'demographics' => [
                    'age' => 20,
                    'civil_status' => 'Single',
                ],
            ]),
            'is_draft' => false,
        ];

        $latex = GuidanceFormLatex::renderPsychosocialInterview($snapshot);

        $this->assertStringNotContainsString('PHQ-9', $latex);
        $this->assertStringNotContainsString('GAD-7', $latex);
        $this->assertStringNotContainsString('Mental Health Screening', $latex);
        $this->assertStringNotContainsString('Civil Status', $latex);
    }

    public function testMdframedUsesTikzBackendAndConsistentBordersAcrossClinicalForms(): void
    {
        $pifDraft = GuidanceFormLatex::renderPsychosocialInterview([
            'data' => $this->sampleInterviewData,
            'is_draft' => true,
        ]);
        $pcfDraft = GuidanceFormLatex::renderPsychosocialContact([
            'data' => $this->samplePsychosocialContactData,
            'is_draft' => true,
        ]);
        $ccfDraft = GuidanceFormLatex::renderCounselingContact([
            'data' => $this->sampleCounselingContactData,
            'is_draft' => true,
        ]);

        foreach ([$pifDraft, $pcfDraft, $ccfDraft] as $latex) {
            $this->assertStringContainsString('\\usepackage[framemethod=tikz]{mdframed}', $latex);
            $this->assertStringNotContainsString('roundcorner=', $latex);
            $this->assertStringNotContainsString('linecolor=orange', $latex);
        }
    }

    public function testGeneralReferralLatexMatchesOfficialOgcForm(): void
    {
        $tex = GuidanceFormLatex::render(
            ['id' => 1, 'instance_number' => 'OGC-2026-GGR-01', 'status' => 'finalized'],
            ['internal_key' => 'guidance_general_referral', 'form_code' => 'F-OGC-GRE-GGR-005-00', 'canonical_title' => 'Guidance General Referral Form'],
            ['revision_number' => '01', 'effectivity_date' => '2025-09-01'],
            [
                'student_name'          => 'Juana Dela Cruz',
                'student_id'            => 'STU-10023',
                'course_year'           => 'BS Psychology - 3rd Year',
                'referring_name'        => 'Prof. Santos',
                'referring_designation' => 'Teacher',
                'referring_department'  => 'College of Arts and Sciences',
                'contact_number'        => '09171234567',
                'referral_date'         => '2026-11-26',
                'referral_time'         => '10:30 AM',
                'reasons'               => ['excessive_anxiety', 'absenteeism'],
                'reasons_narrative'     => 'Observed frequent absences and test anxiety.',
                'remarks'               => 'Initial check-in completed by adviser.',
                'date_received'         => '2026-11-26',
                'responded_date'        => '2026-11-26',
                'action_taken'          => 'Scheduled psychosocial support intake.',
            ],
            $this->authorAttestation,
            'SYN-DOC-GGR-01',
            false
        );

        $this->assertStringContainsString('F-OGC-GRE-GGR-005-00', $tex);
        $this->assertStringContainsString('Family concerns', $tex);
        $this->assertStringContainsString('Self-harming tendencies/behaviors', $tex);
        $this->assertStringContainsString('$\\boxtimes$ Excessive anxiety', $tex);
        $this->assertStringContainsString('$\\boxtimes$ Absenteeism', $tex);
        $this->assertStringContainsString('Description of Behavior / Incident Observed:', $tex);
        $this->assertStringContainsString('REMARKS:', $tex);
        $this->assertStringContainsString('$\\boxtimes$ Teacher', $tex);
        $this->assertStringContainsString('For School Counselors/Personnel use only', $tex);
        $this->assertStringContainsString('Scheduled psychosocial support intake.', $tex);
        $this->assertStringNotContainsString('Academic Deficiency', $tex);
        $this->assertCompiledPdf(LatexReportCompiler::compile($tex));
    }
}
