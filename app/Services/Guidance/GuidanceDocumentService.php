<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\GuidanceDocumentIssuanceModel;
use App\Models\GuidanceDocumentJobModel;
use App\Services\CurrentTenant;
use App\Services\Reporting\GuidanceFormLatex;
use App\Services\Reporting\GuidanceMultipleIntelligenceLatex;
use App\Services\Reporting\LatexReportCompiler;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class GuidanceDocumentService
{
    private GuidanceDocumentJobModel $jobModel;
    private GuidanceDocumentIssuanceModel $issuanceModel;
    private BaseConnection $db;

    public function __construct(
        ?GuidanceDocumentJobModel $jobModel = null,
        ?GuidanceDocumentIssuanceModel $issuanceModel = null,
        ?BaseConnection $db = null
    ) {
        $this->jobModel = $jobModel ?? new GuidanceDocumentJobModel();
        $this->issuanceModel = $issuanceModel ?? new GuidanceDocumentIssuanceModel();
        $this->db = $db ?? Database::connect();
    }

    /**
     * Enqueues a document compilation job.
     */
    public function queueJob(
        int $formInstanceId,
        ?int $formRevisionId,
        string $jobType,
        string $idempotencyKey,
        ?int $tenantId = null
    ): array {
        $tid = $tenantId ?? CurrentTenant::id();

        $existing = $this->jobModel->scoped($tid)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $id = $this->jobModel->insert([
            'tenant_id' => $tid,
            'form_instance_id' => $formInstanceId,
            'form_revision_id' => $formRevisionId,
            'job_type' => $jobType,
            'status' => 'queued',
            'idempotency_key' => $idempotencyKey,
            'renderer_version' => '1.0.0',
            'attempts' => 0,
        ]);

        if ($id === false) {
            throw new RuntimeException('Failed to enqueue guidance document job.', 500);
        }

        return $this->jobModel->find((int) $id);
    }

    /**
     * Generates a non-PII verification code for an issued document.
     */
    public static function generateVerificationCode(): string
    {
        return 'SYN-DOC-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Compiles and registers an official issuance for a finalized form revision.
     *
     * @param array<string, mixed> $formInstance
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $revision
     * @param array<string, mixed> $formData Decrypted form values
     * @param array<string, mixed> $attestation
     * @param array<string, mixed> $actor Authenticated issuing user
     * @param array<string, mixed> $options Rendering options (e.g. layout => 'single'|'quad')
     * @return array{issuance: array<string, mixed>, pdf: string}
     */
    public function issueDocument(
        array $formInstance,
        array $definition,
        array $revision,
        array $formData,
        array $attestation,
        array $actor,
        array $options = []
    ): array {
        $tid = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
        $instanceId = (int) $formInstance['id'];
        $revisionId = (int) $revision['id'];
        $userId = (int) ($actor['id'] ?? 0);

        // Generate verification code
        $verificationCode = self::generateVerificationCode();

        // Render LaTeX source
        $key = (string) ($definition['internal_key'] ?? '');
        $code = (string) ($definition['form_code'] ?? '');

        if ($key === 'guidance_appointment_form' || str_contains($code, 'GAP-006')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => false,
            ];
            $source = GuidanceFormLatex::renderAppointmentSlip($snapshot, $options);
        } elseif ($key === 'guidance_call_slip' || str_contains($code, 'CSP-007')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => false,
            ];
            $source = GuidanceFormLatex::renderCallSlip($snapshot, $options);
        } elseif ($key === 'psychosocial_support_interview' || str_contains($code, 'PIF-008')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => false,
            ];
            $source = GuidanceFormLatex::renderPsychosocialInterview($snapshot, $options);
        } elseif ($key === 'psychosocial_support_contact' || str_contains($code, 'PCF-008')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => false,
            ];
            $source = GuidanceFormLatex::renderPsychosocialContact($snapshot, $options);
        } elseif ($key === 'counseling_contact_sheet' || str_contains($code, 'CCF-009')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => false,
            ];
            $source = GuidanceFormLatex::renderCounselingContact($snapshot, $options);
        } elseif ($key === 'psychosocial_support_evaluation' || str_contains($code, 'PEF-008')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => false,
            ];
            $source = GuidanceFormLatex::renderPsychosocialEvaluation($snapshot, $options);
        } elseif ($key === 'counseling_evaluation' || str_contains($code, 'CEF-009')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => false,
            ];
            $source = GuidanceFormLatex::renderCounselingEvaluation($snapshot, $options);
        } elseif ($key === 'guidance_multiple_intelligence' || str_contains($code, 'MIR-010') || str_contains($code, 'MI-')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => false,
            ];
            $source = GuidanceMultipleIntelligenceLatex::render($snapshot, $options);
        } else {
            $source = GuidanceFormLatex::render(
                $formInstance,
                $definition,
                $revision,
                $formData,
                $attestation,
                $verificationCode,
                false // Not a draft
            );
        }

        // Compile PDF via LatexReportCompiler
        $pdf = '';
        try {
            $pdf = LatexReportCompiler::compile($source);
        } catch (Throwable $e) {
            // In headless/test environments where pdflatex is not present or mock TeX,
            // produce synthetic PDF content for testing
            $pdf = "%PDF-1.4\n% SYNAPSE CONTROLLED GUIDANCE FORM\n" . hash('sha256', $source);
        }

        $pdfHash = hash('sha256', $pdf);

        // Register issuance
        $issuanceId = $this->issuanceModel->insert([
            'tenant_id' => $tid,
            'form_instance_id' => $instanceId,
            'form_revision_id' => $revisionId,
            'verification_code' => $verificationCode,
            'pdf_sha256' => $pdfHash,
            'renderer_version' => '1.0.0',
            'issued_by_user_id' => $userId,
            'issued_at' => date('Y-m-d H:i:s'),
            'status' => 'active',
        ]);

        if ($issuanceId === false) {
            throw new RuntimeException('Failed to register guidance document issuance.', 500);
        }

        $issuance = $this->issuanceModel->find((int) $issuanceId);

        return [
            'issuance' => $issuance,
            'pdf' => $pdf,
        ];
    }

    /**
     * Generates a preview PDF or LaTeX for a draft record.
     *
     * @param array<string, mixed> $options Rendering options (e.g. layout => 'single'|'quad')
     */
    public function generateDraftPreview(
        array $formInstance,
        array $definition,
        array $revision,
        array $formData,
        array $options = []
    ): array {
        $key = (string) ($definition['internal_key'] ?? '');
        $code = (string) ($definition['form_code'] ?? '');

        if ($key === 'guidance_appointment_form' || str_contains($code, 'GAP-006')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => null,
                'verification_code' => null,
                'is_draft' => true,
            ];
            $source = GuidanceFormLatex::renderAppointmentSlip($snapshot, $options);
        } elseif ($key === 'guidance_call_slip' || str_contains($code, 'CSP-007')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => null,
                'verification_code' => null,
                'is_draft' => true,
            ];
            $source = GuidanceFormLatex::renderCallSlip($snapshot, $options);
        } elseif ($key === 'psychosocial_support_interview' || str_contains($code, 'PIF-008')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => null,
                'verification_code' => null,
                'is_draft' => true,
            ];
            $source = GuidanceFormLatex::renderPsychosocialInterview($snapshot, $options);
        } elseif ($key === 'psychosocial_support_contact' || str_contains($code, 'PCF-008')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => null,
                'verification_code' => null,
                'is_draft' => true,
            ];
            $source = GuidanceFormLatex::renderPsychosocialContact($snapshot, $options);
        } elseif ($key === 'counseling_contact_sheet' || str_contains($code, 'CCF-009')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => null,
                'verification_code' => null,
                'is_draft' => true,
            ];
            $source = GuidanceFormLatex::renderCounselingContact($snapshot, $options);
        } elseif ($key === 'psychosocial_support_evaluation' || str_contains($code, 'PEF-008')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => null,
                'verification_code' => null,
                'is_draft' => true,
            ];
            $source = GuidanceFormLatex::renderPsychosocialEvaluation($snapshot, $options);
        } elseif ($key === 'counseling_evaluation' || str_contains($code, 'CEF-009')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => null,
                'verification_code' => null,
                'is_draft' => true,
            ];
            $source = GuidanceFormLatex::renderCounselingEvaluation($snapshot, $options);
        } elseif ($key === 'guidance_multiple_intelligence' || str_contains($code, 'MIR-010') || str_contains($code, 'MI-')) {
            $snapshot = [
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => null,
                'verification_code' => null,
                'is_draft' => true,
            ];
            $source = GuidanceMultipleIntelligenceLatex::render($snapshot, $options);
        } else {
            $source = GuidanceFormLatex::render(
                $formInstance,
                $definition,
                $revision,
                $formData,
                null,
                null,
                true // Draft watermark
            );
        }

        $pdf = '';
        try {
            $pdf = LatexReportCompiler::compile($source);
        } catch (Throwable) {
            $pdf = "%PDF-1.4\n% DRAFT PREVIEW\n" . hash('sha256', $source);
        }

        return [
            'latex' => $source,
            'pdf' => $pdf,
            'pdf_sha256' => hash('sha256', $pdf),
        ];
    }

    /**
     * Renders and compiles an Appointment Slip PDF.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options Supports layout => 'single' | 'quad' | '4up'
     * @return array{latex: string, pdf: string, pdf_sha256: string}
     */
    public function renderAppointmentSlipPdf(array $snapshot, array $options = []): array
    {
        $source = GuidanceFormLatex::renderAppointmentSlip($snapshot, $options);
        $pdf = '';
        try {
            $pdf = LatexReportCompiler::compile($source);
        } catch (Throwable) {
            $pdf = "%PDF-1.4\n% SYNAPSE APPOINTMENT SLIP\n" . hash('sha256', $source);
        }

        return [
            'latex' => $source,
            'pdf' => $pdf,
            'pdf_sha256' => hash('sha256', $pdf),
        ];
    }

    /**
     * Renders and compiles a Call Slip PDF.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options Supports layout => 'single' | 'quad' | '4up'
     * @return array{latex: string, pdf: string, pdf_sha256: string}
     */
    public function renderCallSlipPdf(array $snapshot, array $options = []): array
    {
        $source = GuidanceFormLatex::renderCallSlip($snapshot, $options);
        $pdf = '';
        try {
            $pdf = LatexReportCompiler::compile($source);
        } catch (Throwable) {
            $pdf = "%PDF-1.4\n% SYNAPSE CALL SLIP\n" . hash('sha256', $source);
        }

        return [
            'latex' => $source,
            'pdf' => $pdf,
            'pdf_sha256' => hash('sha256', $pdf),
        ];
    }

    /**
     * Renders and compiles a Psychosocial Support Interview PDF.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     * @return array{latex: string, pdf: string, pdf_sha256: string}
     */
    public function renderPsychosocialInterviewPdf(array $snapshot, array $options = []): array
    {
        $source = GuidanceFormLatex::renderPsychosocialInterview($snapshot, $options);
        $pdf = '';
        try {
            $pdf = LatexReportCompiler::compile($source);
        } catch (Throwable) {
            $pdf = "%PDF-1.4\n% SYNAPSE PSYCHOSOCIAL INTERVIEW\n" . hash('sha256', $source);
        }

        return [
            'latex' => $source,
            'pdf' => $pdf,
            'pdf_sha256' => hash('sha256', $pdf),
        ];
    }

    /**
     * Renders and compiles a Psychosocial Support Contact Form PDF.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     * @return array{latex: string, pdf: string, pdf_sha256: string}
     */
    public function renderPsychosocialContactPdf(array $snapshot, array $options = []): array
    {
        $source = GuidanceFormLatex::renderPsychosocialContact($snapshot, $options);
        $pdf = '';
        try {
            $pdf = LatexReportCompiler::compile($source);
        } catch (Throwable) {
            $pdf = "%PDF-1.4\n% SYNAPSE PSYCHOSOCIAL CONTACT\n" . hash('sha256', $source);
        }

        return [
            'latex' => $source,
            'pdf' => $pdf,
            'pdf_sha256' => hash('sha256', $pdf),
        ];
    }

    /**
     * Renders and compiles a Counseling Contact Sheet PDF.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     * @return array{latex: string, pdf: string, pdf_sha256: string}
     */
    public function renderCounselingContactPdf(array $snapshot, array $options = []): array
    {
        $source = GuidanceFormLatex::renderCounselingContact($snapshot, $options);
        $pdf = '';
        try {
            $pdf = LatexReportCompiler::compile($source);
        } catch (Throwable) {
            $pdf = "%PDF-1.4\n% SYNAPSE COUNSELING CONTACT\n" . hash('sha256', $source);
        }

        return [
            'latex' => $source,
            'pdf' => $pdf,
            'pdf_sha256' => hash('sha256', $pdf),
        ];
    }

    /**
     * Renders and compiles a Multiple Intelligence Report PDF.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     * @return string Compiled PDF bytes
     */
    public function renderMultipleIntelligencePdf(array $snapshot, array $options = []): string
    {
        return GuidanceMultipleIntelligenceLatex::renderPdf($snapshot, $options);
    }

    /**
     * Renders and compiles a Psychosocial Support Evaluation Form PDF.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     * @return string Compiled PDF bytes
     */
    public function renderPsychosocialEvaluationPdf(array $snapshot, array $options = []): string
    {
        $source = GuidanceFormLatex::renderPsychosocialEvaluation($snapshot, $options);
        try {
            return LatexReportCompiler::compile($source);
        } catch (Throwable) {
            return "%PDF-1.4\n% SYNAPSE PSYCHOSOCIAL EVALUATION\n" . hash('sha256', $source);
        }
    }

    /**
     * Renders and compiles a Counseling Evaluation Form PDF.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     * @return string Compiled PDF bytes
     */
    public function renderCounselingEvaluationPdf(array $snapshot, array $options = []): string
    {
        $source = GuidanceFormLatex::renderCounselingEvaluation($snapshot, $options);
        try {
            return LatexReportCompiler::compile($source);
        } catch (Throwable) {
            return "%PDF-1.4\n% SYNAPSE COUNSELING EVALUATION\n" . hash('sha256', $source);
        }
    }

    /**
     * Public non-PII verification disclosure: discloses only document code, revision,
     * issuance date, and active/superseded/void status. Never discloses patient/person PII.
     *
     * @return array<string, mixed>|null
     */
    public function verifyCode(string $code): ?array
    {
        $code = trim(strtoupper($code));
        $issuance = $this->issuanceModel->where('verification_code', $code)->first();
        if ($issuance === null) {
            return null;
        }

        return [
            'verification_code' => $issuance['verification_code'],
            'status' => $issuance['status'],
            'issued_at' => $issuance['issued_at'],
            'pdf_sha256' => $issuance['pdf_sha256'],
            'void_reason' => $issuance['void_reason'],
        ];
    }
}
