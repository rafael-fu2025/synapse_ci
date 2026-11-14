<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Reporting\GuidanceFormLatex;
use App\Services\Reporting\LatexReportCompiler;
use CodeIgniter\Test\CIUnitTestCase;

final class GuidanceSlipPdfTest extends CIUnitTestCase
{
    private array $sampleAppointmentData;
    private array $sampleCallSlipData;
    private array $sampleAttestation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sampleAppointmentData = [
            'appointment_number' => 'APP-2026-0042',
            'student_name' => 'Juan Dela Cruz & Maria Santos',
            'student_id' => 'STU-2026-99',
            'course_year' => 'BS Computer Science - 3rd Year',
            'scheduled_date' => '2026-11-20',
            'scheduled_time' => '10:30 AM',
            'counselor_name' => 'Dr. Jane Roe, RGC',
            'purpose' => 'routine_interview',
            'purpose_other' => '',
        ];

        $this->sampleCallSlipData = [
            'call_slip_number' => 'CS-2026-0088',
            'student_name' => 'Juana Dela Cruz',
            'student_id' => 'STU-2026-101',
            'course_year' => 'BS Psychology - 2nd Year',
            'call_date' => '2026-11-21',
            'call_time' => '02:00 PM',
            'issuing_counselor_name' => 'Prof. Alex Mercer, RGC',
            'purpose' => 'personal_counseling',
            'purpose_other' => '',
        ];

        $this->sampleAttestation = [
            'signer_role' => 'Registered Guidance Counselor',
            'attested_at' => '2026-11-13 15:30:00',
            'snapshot_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ];
    }

    public function testLatexEscapingConvention(): void
    {
        $raw = 'Special & symbols % $ # _ { } ~ ^ \\ and café résumé';
        $escaped = LatexReportCompiler::escapeLatex($raw);

        $this->assertStringContainsString('\\&', $escaped);
        $this->assertStringContainsString('\\%', $escaped);
        $this->assertStringContainsString('\\$', $escaped);
        $this->assertStringContainsString('\\#', $escaped);
        $this->assertStringContainsString('\\_', $escaped);
        $this->assertStringContainsString('\\{', $escaped);
        $this->assertStringContainsString('\\}', $escaped);
        $this->assertStringContainsString('\\textasciitilde{}', $escaped);
        $this->assertStringContainsString('\\textasciicircum{}', $escaped);
        $this->assertStringContainsString('\\textbackslash{}', $escaped);
        // Transliterated ASCII (non-accented or ASCII-transliterated representation)
        $this->assertMatchesRegularExpression('/caf.?e/', $escaped);
        $this->assertMatchesRegularExpression('/r.?esum.?e/', $escaped);

        // GuidanceFormLatex::escapeLatex and GuidanceFormLatex::escape match
        $this->assertSame($escaped, GuidanceFormLatex::escapeLatex($raw));
        $this->assertSame($escaped, GuidanceFormLatex::escape($raw));
    }

    public function testRenderAppointmentSlipSingleLayout(): void
    {
        $snapshot = array_merge($this->sampleAppointmentData, [
            'verification_code' => 'SYN-DOC-TEST1234',
            'attestation' => $this->sampleAttestation,
            'is_draft' => false,
        ]);

        $latex = GuidanceFormLatex::renderAppointmentSlip($snapshot, ['layout' => 'single']);

        // Check canonical codes, in-doc code, revision, effectivity date
        $this->assertStringContainsString('F-OGC-GAP-006-00', $latex);
        $this->assertStringContainsString('F-OGC-GRE-GAP-006-00', $latex);
        $this->assertStringContainsString('00', $latex);
        $this->assertStringContainsString('September 1, 2025', $latex);

        // Header and institution
        $this->assertStringContainsString('OFFICE OF THE GUIDANCE CENTER', $latex);
        $this->assertStringContainsString('FOUNDATION UNIVERSITY', $latex);
        $this->assertStringContainsString('GUIDANCE APPOINTMENT FORM', $latex);

        // Details
        $this->assertStringContainsString('APP-2026-0042', $latex);
        $this->assertStringContainsString('Juan Dela Cruz \\& Maria Santos', $latex);
        $this->assertStringContainsString('STU-2026-99', $latex);
        $this->assertStringContainsString('BS Computer Science - 3rd Year', $latex);
        $this->assertStringContainsString('2026-11-20', $latex);
        $this->assertStringContainsString('10:30 AM', $latex);
        $this->assertStringContainsString('Dr. Jane Roe, RGC', $latex);

        // Purpose checkbox: routine interview checked (\boxtimes), others unchecked (\square)
        $this->assertStringContainsString('$\\boxtimes$ Routine Interview', $latex);
        $this->assertStringContainsString('$\\square$ Counseling', $latex);
        $this->assertStringContainsString('$\\square$ Follow-up', $latex);
        $this->assertStringContainsString('$\\square$ Intake', $latex);
        $this->assertStringContainsString('$\\square$ Inquiry', $latex);
        $this->assertStringContainsString('$\\square$ Others', $latex);

        // Verification code & digital attestation
        $this->assertStringContainsString('SYN-DOC-TEST1234', $latex);
        $this->assertStringContainsString('Registered Guidance Counselor', $latex);
        $this->assertStringContainsString('e3b0c44298fc1c14...', $latex);

        // Confidentiality notice
        $this->assertStringContainsString('RA 10173', $latex);

        // Single document structure
        $this->assertStringStartsWith('\\documentclass', $latex);
        $this->assertStringEndsWith('\\end{document}', trim($latex));
        $this->assertStringNotContainsString('DRAFT - PREVIEW ONLY', $latex);
    }

    public function testRenderAppointmentSlipQuadLayout(): void
    {
        $snapshot = array_merge($this->sampleAppointmentData, [
            'purpose' => 'intake',
            'verification_code' => 'SYN-DOC-QUAD5678',
            'attestation' => $this->sampleAttestation,
            'is_draft' => false,
        ]);

        $latex = GuidanceFormLatex::renderAppointmentSlip($snapshot, ['layout' => 'quad']);

        // Check canonical codes in quad layout
        $this->assertStringContainsString('F-OGC-GAP-006-00', $latex);
        $this->assertStringContainsString('F-OGC-GRE-GAP-006-00', $latex);
        $this->assertStringContainsString('2025-09-01', $latex);

        // 4 copies replicated
        $this->assertStringContainsString('Copy 1 (Student Copy)', $latex);
        $this->assertStringContainsString('Copy 2 (Counselor / Office Copy)', $latex);
        $this->assertStringContainsString('Copy 3 (Teacher / Department Copy)', $latex);
        $this->assertStringContainsString('Copy 4 (Guard / File Copy)', $latex);

        // Dashed cut lines
        $this->assertStringContainsString('CUT ALONG DASHED LINE', $latex);
        $this->assertStringContainsString('\\cleaders\\hbox', $latex);
        $this->assertStringContainsString('\\cleaders\\vbox', $latex);

        // Checkbox intake checked
        $this->assertStringContainsString('$\\boxtimes$ Intake', $latex);
        $this->assertStringContainsString('$\\square$ Routine Int.', $latex);

        // Also test '4up' alias
        $latex4up = GuidanceFormLatex::renderAppointmentSlip($snapshot, ['layout' => '4up']);
        $this->assertSame($latex, $latex4up);
    }

    public function testRenderAppointmentSlipCustomOtherPurpose(): void
    {
        $snapshot = array_merge($this->sampleAppointmentData, [
            'purpose' => 'others',
            'purpose_other' => 'Scholarship Clearance & Exit Interview',
            'is_draft' => true,
        ]);

        $latex = GuidanceFormLatex::renderAppointmentSlip($snapshot, ['layout' => 'single']);

        $this->assertStringContainsString('$\\boxtimes$ Others (Scholarship Clearance \\& Exit Interview)', $latex);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $latex);
        $this->assertStringContainsString('Unfinalized draft record', $latex);
    }

    public function testRenderCallSlipSingleLayout(): void
    {
        $snapshot = array_merge($this->sampleCallSlipData, [
            'verification_code' => 'SYN-DOC-CALL9988',
            'attestation' => $this->sampleAttestation,
            'is_draft' => false,
        ]);

        $latex = GuidanceFormLatex::renderCallSlip($snapshot, ['layout' => 'single']);

        // Canonical code, rev, eff date
        $this->assertStringContainsString('F-OGC-GSP-CSP-007-00', $latex);
        $this->assertStringContainsString('00', $latex);
        $this->assertStringContainsString('September 1, 2025', $latex);

        // Header and excuse lead-in
        $this->assertStringContainsString('OFFICE OF THE GUIDANCE CENTER', $latex);
        $this->assertStringContainsString('GUIDANCE CALL SLIP', $latex);
        $this->assertStringContainsString('Please excuse the student named below', $latex);

        // Details
        $this->assertStringContainsString('CS-2026-0088', $latex);
        $this->assertStringContainsString('Juana Dela Cruz', $latex);
        $this->assertStringContainsString('STU-2026-101', $latex);
        $this->assertStringContainsString('BS Psychology - 2nd Year', $latex);
        $this->assertStringContainsString('2026-11-21', $latex);
        $this->assertStringContainsString('02:00 PM', $latex);
        $this->assertStringContainsString('Prof. Alex Mercer, RGC', $latex);

        // Checkboxes: personal counseling checked
        $this->assertStringContainsString('$\\boxtimes$ Personal Counseling', $latex);
        $this->assertStringContainsString('$\\square$ Career Counseling', $latex);
        $this->assertStringContainsString('$\\square$ Routine Interview', $latex);
        $this->assertStringContainsString('$\\square$ Follow-up', $latex);
        $this->assertStringContainsString('$\\square$ Academic Counseling', $latex);
        $this->assertStringContainsString('$\\square$ Others', $latex);

        // Tear-off slip lines and teacher acknowledgment
        $this->assertStringContainsString('TEAR-OFF RETURN SLIP / TEACHER ACKNOWLEDGMENT', $latex);
        $this->assertStringContainsString('Teacher / Instructor Acknowledgment Return Section', $latex);
        $this->assertStringContainsString('Teacher / Instructor Signature:', $latex);

        // Verification and attestation
        $this->assertStringContainsString('SYN-DOC-CALL9988', $latex);
        $this->assertStringContainsString('Registered Guidance Counselor', $latex);
    }

    public function testRenderCallSlipQuadLayout(): void
    {
        $snapshot = array_merge($this->sampleCallSlipData, [
            'purpose' => 'career_counseling',
            'verification_code' => 'SYN-DOC-CALL4UP',
            'attestation' => $this->sampleAttestation,
            'is_draft' => false,
        ]);

        $latex = GuidanceFormLatex::renderCallSlip($snapshot, ['layout' => 'quad']);

        // Canonical code
        $this->assertStringContainsString('F-OGC-GSP-CSP-007-00', $latex);
        $this->assertStringContainsString('2025-09-01', $latex);

        // 4 copies
        $this->assertStringContainsString('Copy 1 (Student Copy)', $latex);
        $this->assertStringContainsString('Copy 2 (Guidance File Copy)', $latex);
        $this->assertStringContainsString('Copy 3 (Teacher / Class Copy)', $latex);
        $this->assertStringContainsString('Copy 4 (Department / Admin Copy)', $latex);

        // Dashed cut lines
        $this->assertStringContainsString('CUT ALONG DASHED LINE', $latex);
        $this->assertStringContainsString('Tear-Off Return Slip', $latex);

        // Checkbox: career counseling checked
        $this->assertStringContainsString('$\\boxtimes$ Career', $latex);
        $this->assertStringContainsString('$\\square$ Personal', $latex);
    }

    public function testGuidanceDocumentServiceIntegration(): void
    {
        $docService = new GuidanceDocumentService();

        // Appointment slip preview
        $apptBundle = [
            'data' => $this->sampleAppointmentData,
            'is_draft' => true,
        ];
        $apptResult = $docService->renderAppointmentSlipPdf($apptBundle, ['layout' => 'single']);
        $this->assertNotEmpty($apptResult['latex']);
        $this->assertNotEmpty($apptResult['pdf']);
        $this->assertNotEmpty($apptResult['pdf_sha256']);
        $this->assertStringContainsString('F-OGC-GAP-006-00', $apptResult['latex']);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $apptResult['latex']);

        // Call slip PDF quad layout
        $callBundle = [
            'data' => $this->sampleCallSlipData,
            'verification_code' => 'SYN-DOC-INTEG999',
            'attestation' => $this->sampleAttestation,
            'is_draft' => false,
        ];
        $callResult = $docService->renderCallSlipPdf($callBundle, ['layout' => 'quad']);
        $this->assertNotEmpty($callResult['latex']);
        $this->assertNotEmpty($callResult['pdf']);
        $this->assertNotEmpty($callResult['pdf_sha256']);
        $this->assertStringContainsString('F-OGC-GSP-CSP-007-00', $callResult['latex']);
        $this->assertStringContainsString('CUT ALONG DASHED LINE', $callResult['latex']);
    }

    public function testGuidanceFormLatexRenderDelegatesAppointmentAndCallSlip(): void
    {
        $instance = ['id' => 1, 'instance_number' => 'OGC-2026-000001', 'status' => 'draft'];
        $appDef = ['internal_key' => 'guidance_appointment_form', 'form_code' => 'F-OGC-GAP-006-00'];
        $callDef = ['internal_key' => 'guidance_call_slip', 'form_code' => 'F-OGC-GSP-CSP-007-00'];
        $rev = ['revision_number' => '00', 'effectivity_date' => '2025-09-01'];

        $renderedApp = GuidanceFormLatex::render(
            $instance,
            $appDef,
            $rev,
            $this->sampleAppointmentData,
            null,
            null,
            true
        );
        $this->assertStringContainsString('F-OGC-GAP-006-00', $renderedApp);
        $this->assertStringContainsString('F-OGC-GRE-GAP-006-00', $renderedApp);
        $this->assertStringContainsString('GUIDANCE APPOINTMENT FORM', $renderedApp);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $renderedApp);

        $renderedCall = GuidanceFormLatex::render(
            $instance,
            $callDef,
            $rev,
            $this->sampleCallSlipData,
            $this->sampleAttestation,
            'SYN-DOC-CALL77',
            false
        );
        $this->assertStringContainsString('F-OGC-GSP-CSP-007-00', $renderedCall);
        $this->assertStringContainsString('GUIDANCE CALL SLIP', $renderedCall);
        $this->assertStringContainsString('TEAR-OFF RETURN SLIP', $renderedCall);
        $this->assertStringContainsString('SYN-DOC-CALL77', $renderedCall);
    }

    public function testLatexCompilationIfPdflatexAvailable(): void
    {
        $snapshot = array_merge($this->sampleAppointmentData, [
            'verification_code' => 'SYN-DOC-REALPDF',
            'is_draft' => false,
        ]);

        $singleTex = GuidanceFormLatex::renderAppointmentSlip($snapshot, ['layout' => 'single']);
        $quadTex = GuidanceFormLatex::renderAppointmentSlip($snapshot, ['layout' => 'quad']);
        $callSingleTex = GuidanceFormLatex::renderCallSlip($this->sampleCallSlipData, ['layout' => 'single']);
        $callQuadTex = GuidanceFormLatex::renderCallSlip($this->sampleCallSlipData, ['layout' => 'quad']);

        try {
            $pdfSingle = LatexReportCompiler::compile($singleTex);
            $this->assertStringStartsWith('%PDF-', $pdfSingle);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }

        try {
            $pdfQuad = LatexReportCompiler::compile($quadTex);
            $this->assertStringStartsWith('%PDF-', $pdfQuad);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }

        try {
            $pdfCallSingle = LatexReportCompiler::compile($callSingleTex);
            $this->assertStringStartsWith('%PDF-', $pdfCallSingle);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }

        try {
            $pdfCallQuad = LatexReportCompiler::compile($callQuadTex);
            $this->assertStringStartsWith('%PDF-', $pdfCallQuad);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }
}
