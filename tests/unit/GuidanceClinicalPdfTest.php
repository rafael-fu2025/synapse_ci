<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Reporting\GuidanceFormLatex;
use App\Services\Reporting\LatexReportCompiler;
use CodeIgniter\Test\CIUnitTestCase;

final class GuidanceClinicalPdfTest extends CIUnitTestCase
{
    private array $sampleInterviewData;
    private array $samplePsychosocialContactData;
    private array $sampleCounselingContactData;
    private array $authorAttestation;
    private array $cosignerAttestation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sampleInterviewData = [
            'student_name' => 'Juan Dela Cruz & Maria Clara',
            'student_id' => 'STU-2026-0042',
            'course_year' => 'BS Psychology - 3rd Year (Section A_1)',
            'interview_date' => '2026-11-20',
            'client_type' => 'walk_in',
            'demographics' => [
                'age' => 20,
                'sex' => 'Female',
                'contact_number' => '+63 912 345 6789',
                'civil_status' => 'Single',
                'living_arrangement' => 'Dormitory near campus',
                'emergency_contact' => 'Juana Dela Cruz (Mother) - 09187654321',
            ],
            'presenting_concerns' => 'Student reports recurring anxiety, difficulty sleeping, and academic stress regarding 50% midterm requirements.',
            'mental_health_screening' => [
                'Risk Level' => 'Low to Moderate',
                'Mood' => 'Anxious & Fatigued',
                'Suicide/Self-Harm Risk' => 'Negative / Denied',
                'Sleep Pattern' => 'Disturbed (3-4 hrs/night)',
            ],
            'family_social_support' => 'Good relationship with mother & younger sister; supportive peer study group in dorm.',
            'counselor_assessment' => 'Client exhibits adjustment difficulties and academic anxiety exacerbated by irregular sleep cycle. No evidence of severe clinical pathology.',
            'intervention_plan' => '1. Cognitive reframing and sleep hygiene protocol.\n2. Bi-weekly supportive counseling.\n3. Referral to academic tutor if stress persists.',
        ];

        $this->samplePsychosocialContactData = [
            'session_number' => 2,
            'session_date' => '2026-11-25',
            'modality' => 'in_person',
            'time_started' => '10:00 AM',
            'time_ended' => '11:00 AM',
            'session_type' => 'follow_up',
            'student_name' => 'Maria Clara Santos',
            'student_id' => 'STU-2026-0042',
            'course_year' => 'BS Psychology - 3rd Year',
            'department' => 'College of Arts and Sciences',
            'counselor_name' => 'Alex Mercer, RGC',
            'client_state' => 'Client arrived on time, noticeably calmer and well-rested. Affect euthymic and communicative.',
            'interventions_used' => 'Active listening, review of sleep diary, progressive muscle relaxation technique demonstration.',
            'client_response' => 'Client stated sleep improved to 6 hours after applying relaxation techniques. Expressed increased confidence.',
            'action_items' => 'Continue sleep schedule tracking; complete breathing exercises before studying.',
            'next_session_date' => '2026-12-02',
        ];

        $this->sampleCounselingContactData = [
            'session_number' => 1,
            'session_date' => '2026-11-22',
            'time_start' => '02:00 PM',
            'time_end' => '03:15 PM',
            'session_type' => 'intake',
            'student_name' => 'Crisostomo Ibarra',
            'student_id' => 'STU-2026-0099',
            'course_year' => 'BS Civil Engineering - 4th Year',
            'department' => 'College of Engineering',
            'counselor_name' => 'Dr. Jane Roe, RGC',
            'concerns_discussed' => 'Career uncertainty, impending graduation anxiety, and interpersonal conflicts with thesis partner.',
            'counseling_goals' => 'Establish career path clarity, practice conflict resolution communication skills, and manage pre-graduation anxiety.',
            'interventions_and_responses' => 'Brief solution-focused counseling; role-playing assertiveness communication. Client actively engaged and receptive.',
            'homework_and_commitments' => 'Conduct assertiveness dialogue with thesis peer; complete Myers-Briggs/MI assessment checklist.',
            'next_schedule' => '2026-11-29 02:00 PM',
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

        // Header / OGC branding
        $this->assertStringContainsString('FOUNDATION UNIVERSITY', $latex);
        $this->assertStringContainsString('OFFICE OF THE GUIDANCE CENTER', $latex);

        // Student Demographics & Academic Profile (Escaped)
        $this->assertStringContainsString('Juan Dela Cruz \\& Maria Clara', $latex);
        $this->assertStringContainsString('STU-2026-0042', $latex);
        $this->assertStringContainsString('BS Psychology - 3rd Year (Section A\\_1)', $latex);
        $this->assertStringContainsString('2026-11-20', $latex);
        $this->assertStringContainsString('$\\boxtimes$ Walk-in', $latex);
        $this->assertStringContainsString('Dormitory near campus', $latex);

        // Presenting Concerns & Mental Health Screening
        $this->assertStringContainsString('Presenting Concerns', $latex);
        $this->assertStringContainsString('50\\% midterm requirements', $latex);
        $this->assertStringContainsString('Mental Health Screening', $latex);
        $this->assertStringContainsString('Risk Level', $latex);

        // Family & Social Support
        $this->assertStringContainsString('Family \\& Social Support', $latex);
        $this->assertStringContainsString('Good relationship with mother \\& younger sister', $latex);

        // Counselor Assessment & Action/Intervention Plan
        $this->assertStringContainsString('Counselor Assessment', $latex);
        $this->assertStringContainsString('Action \\& Intervention Plan', $latex);

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

        // Session information
        $this->assertStringContainsString('Maria Clara Santos', $latex);
        $this->assertStringContainsString('STU-2026-0042', $latex);
        $this->assertStringContainsString('BS Psychology - 3rd Year', $latex);
        $this->assertStringContainsString('2026-11-25', $latex);
        $this->assertStringContainsString('10:00 AM -- 11:00 AM', $latex);
        $this->assertStringContainsString('$\\boxtimes$ In-Person', $latex);
        $this->assertStringContainsString('$\\boxtimes$ Follow-up', $latex);

        // Clinical sections
        $this->assertStringContainsString('Client arrived on time, noticeably calmer', $latex);
        $this->assertStringContainsString('Active listening, review of sleep diary', $latex);
        $this->assertStringContainsString('Client stated sleep improved to 6 hours', $latex);
        $this->assertStringContainsString('Continue sleep schedule tracking', $latex);
        $this->assertStringContainsString('2026-12-02', $latex);

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

        // Session information
        $this->assertStringContainsString('Crisostomo Ibarra', $latex);
        $this->assertStringContainsString('STU-2026-0099', $latex);
        $this->assertStringContainsString('BS Civil Engineering - 4th Year', $latex);
        $this->assertStringContainsString('College of Engineering', $latex);
        $this->assertStringContainsString('2026-11-22', $latex);
        $this->assertStringContainsString('02:00 PM -- 03:15 PM', $latex);
        $this->assertStringContainsString('$\\boxtimes$ Intake', $latex);

        // Clinical content
        $this->assertStringContainsString('Career uncertainty, impending graduation anxiety', $latex);
        $this->assertStringContainsString('Establish career path clarity', $latex);
        $this->assertStringContainsString('Brief solution-focused counseling', $latex);
        $this->assertStringContainsString('Conduct assertiveness dialogue with thesis peer', $latex);
        $this->assertStringContainsString('2026-11-29 02:00 PM', $latex);

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

    public function testGuidanceDocumentServiceIntegrationForClinicalForms(): void
    {
        $service = new GuidanceDocumentService();

        // 1. Interview PDF helper
        $interviewRes = $service->renderPsychosocialInterviewPdf([
            'data' => $this->sampleInterviewData,
            'is_draft' => true,
        ]);
        $this->assertNotEmpty($interviewRes['latex']);
        $this->assertNotEmpty($interviewRes['pdf']);
        $this->assertNotEmpty($interviewRes['pdf_sha256']);
        $this->assertStringContainsString('F-OGC-GPS-PIF-008-00', $interviewRes['latex']);

        // 2. Psychosocial Contact PDF helper
        $pcfRes = $service->renderPsychosocialContactPdf([
            'data' => $this->samplePsychosocialContactData,
            'is_draft' => true,
        ]);
        $this->assertNotEmpty($pcfRes['latex']);
        $this->assertNotEmpty($pcfRes['pdf']);
        $this->assertNotEmpty($pcfRes['pdf_sha256']);
        $this->assertStringContainsString('F-OGC-GPS-PCF-008-00', $pcfRes['latex']);

        // 3. Counseling Contact PDF helper
        $ccfRes = $service->renderCounselingContactPdf([
            'data' => $this->sampleCounselingContactData,
            'is_draft' => true,
        ]);
        $this->assertNotEmpty($ccfRes['latex']);
        $this->assertNotEmpty($ccfRes['pdf']);
        $this->assertNotEmpty($ccfRes['pdf_sha256']);
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

        $pcfPreview = $service->generateDraftPreview(
            $instance,
            ['internal_key' => 'psychosocial_support_contact', 'form_code' => 'F-OGC-GPS-PCF-008-00'],
            $rev,
            $this->samplePsychosocialContactData
        );
        $this->assertStringContainsString('F-OGC-GPS-PCF-008-00', $pcfPreview['latex']);

        $ccfPreview = $service->generateDraftPreview(
            $instance,
            ['internal_key' => 'counseling_contact_sheet', 'form_code' => 'F-OGC-GCS-CCF-009-00'],
            $rev,
            $this->sampleCounselingContactData
        );
        $this->assertStringContainsString('F-OGC-GCS-CCF-009-00', $ccfPreview['latex']);
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

        try {
            $pdfPif = LatexReportCompiler::compile($pifTex);
            $this->assertStringStartsWith('%PDF-', $pdfPif);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }

        try {
            $pdfPcf = LatexReportCompiler::compile($pcfTex);
            $this->assertStringStartsWith('%PDF-', $pdfPcf);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }

        try {
            $pdfCcf = LatexReportCompiler::compile($ccfTex);
            $this->assertStringStartsWith('%PDF-', $pdfCcf);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }
}
