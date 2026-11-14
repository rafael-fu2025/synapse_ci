<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use App\Services\Guidance\GuidanceFormService;
use Closure;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GuidanceFormsController extends BaseController
{
    private GuidanceFormService $formService;
    private GuidanceFormDefinitionService $definitionService;
    private GuidanceDocumentService $documentService;
    private ?Closure $actorResolver = null;

    public function __construct(
        ?GuidanceFormService $formService = null,
        ?GuidanceFormDefinitionService $definitionService = null,
        ?GuidanceDocumentService $documentService = null,
        ?callable $actorResolver = null
    ) {
        $this->formService = $formService ?? new GuidanceFormService();
        $this->definitionService = $definitionService ?? new GuidanceFormDefinitionService();
        $this->documentService = $documentService ?? new GuidanceDocumentService();
        $this->actorResolver = $actorResolver !== null ? Closure::fromCallable($actorResolver) : null;
    }

    private function freshActor(?string $permission = null): array
    {
        if ($this->actorResolver !== null) {
            $actor = ($this->actorResolver)($permission);
            if (!is_array($actor)) {
                throw new RuntimeException(AuthenticatedUser::AUTHENTICATION_MESSAGE, 401);
            }
            return $actor;
        }

        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            throw new RuntimeException(AuthenticatedUser::AUTHENTICATION_MESSAGE, 401);
        }

        if ($permission !== null && !in_array($permission, $user['permissions'] ?? [], true)) {
            throw new DomainException('Guidance permission forbidden.', 403);
        }

        return $user;
    }

    /**
     * Synchronize controlled forms manifest into database catalog.
     */
    public function syncManifest()
    {
        try {
            $actor = $this->freshActor('guidance.surveys.manage');
            $result = $this->definitionService->syncManifestToDatabase((int) $actor['tenant_id']);
            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Manifest synchronized successfully.',
                'synced' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->failJson($e);
        }
    }

    /**
     * Lists active form definitions and metadata.
     */
    public function definitions()
    {
        try {
            $actor = $this->freshActor('guidance.cases.read');
            $tid = (int) $actor['tenant_id'];
            $db = \Config\Database::connect();

            $defs = $db->table('guidance_form_definitions')
                ->where('tenant_id', $tid)
                ->where('is_active', 1)
                ->get()->getResultArray();

            return $this->response->setJSON([
                'status' => 'success',
                'definitions' => $defs,
            ]);
        } catch (Throwable $e) {
            return $this->failJson($e);
        }
    }

    /**
     * Creates a new form instance in draft state.
     */
    public function createInstance()
    {
        try {
            $actor = $this->freshActor('guidance.cases.write');
            $json = (array) ($this->request->getJSON(true) ?? []);

            $personId = (int) ($json['person_id'] ?? 0);
            $internalKey = (string) ($json['internal_key'] ?? '');
            $caseId = !empty($json['case_id']) ? (int) $json['case_id'] : null;
            $sessionId = !empty($json['session_id']) ? (int) $json['session_id'] : null;
            $referralId = !empty($json['referral_id']) ? (int) $json['referral_id'] : null;
            $appointmentId = !empty($json['appointment_id']) ? (int) $json['appointment_id'] : null;

            if ($personId <= 0 || $internalKey === '') {
                throw new InvalidArgumentException('person_id and internal_key are required.', 422);
            }

            $instance = $this->formService->createInstance(
                $personId,
                $internalKey,
                $caseId,
                $sessionId,
                $referralId,
                $appointmentId,
                $actor
            );

            return $this->response->setStatusCode(201)->setJSON([
                'status' => 'success',
                'instance' => $instance,
            ]);
        } catch (Throwable $e) {
            return $this->failJson($e);
        }
    }

    /**
     * Saves or updates mutable draft fields.
     */
    public function saveDraft(int $instanceId)
    {
        try {
            $actor = $this->freshActor('guidance.cases.write');
            $json = (array) ($this->request->getJSON(true) ?? []);
            $data = (array) ($json['data'] ?? []);

            $result = $this->formService->saveDraft($instanceId, $data, $actor);

            return $this->response->setJSON([
                'status' => 'success',
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->failJson($e);
        }
    }

    /**
     * Fetches decrypted draft data.
     */
    public function getDraft(int $instanceId)
    {
        try {
            $actor = $this->freshActor('guidance.cases.read');
            $draftBundle = $this->formService->getDraft($instanceId, $actor);

            return $this->response->setJSON([
                'status' => 'success',
                'instance' => $draftBundle['instance'],
                'data' => $draftBundle['data'],
            ]);
        } catch (Throwable $e) {
            return $this->failJson($e);
        }
    }

    /**
     * Finalizes form instance with digital attestation.
     */
    public function finalize(int $instanceId)
    {
        try {
            $actor = $this->freshActor('guidance.cases.write');
            $json = (array) ($this->request->getJSON(true) ?? []);
            $idempotencyKey = (string) ($json['idempotency_key'] ?? bin2hex(random_bytes(16)));

            $result = $this->formService->finalizeInstance($instanceId, $idempotencyKey, $actor);

            return $this->response->setJSON([
                'status' => 'success',
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->failJson($e);
        }
    }

    /**
     * Retrieves finalized immutable revision with attestation.
     */
    public function getRevision(int $instanceId, int $revisionNumber)
    {
        try {
            $actor = $this->freshActor('guidance.cases.read');
            $bundle = $this->formService->getFinalizedRevision($instanceId, $revisionNumber, $actor);

            return $this->response->setJSON([
                'status' => 'success',
                'revision' => $bundle['revision'],
                'data' => $bundle['data'],
                'attestation' => $bundle['attestation'],
            ]);
        } catch (Throwable $e) {
            return $this->failJson($e);
        }
    }

    /**
     * Preview draft document (with DRAFT watermark).
     */
    public function preview(int $instanceId)
    {
        try {
            $actor = $this->freshActor('guidance.cases.read');
            $draftBundle = $this->formService->getDraft($instanceId, $actor);

            $inst = $draftBundle['instance'];
            $defBundle = $this->definitionService->getActiveDefinition((string) $inst['form_definition_id'], (int) $actor['tenant_id']);
            $layout = (string) ($this->request->getGet('layout') ?? $this->request->getVar('layout') ?? 'single');

            $preview = $this->documentService->generateDraftPreview(
                $inst,
                $defBundle['definition'],
                $defBundle['revision'],
                $draftBundle['data'],
                ['layout' => $layout]
            );

            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'inline; filename="draft-preview-' . $instanceId . '.pdf"')
                ->setHeader('Cache-Control', 'private, no-store, max-age=0')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setBody($preview['pdf']);
        } catch (Throwable $e) {
            return $this->failJson($e);
        }
    }

    /**
     * Issue official finalized PDF document.
     */
    public function issue(int $instanceId)
    {
        try {
            $actor = $this->freshActor('guidance.cases.write');
            $bundle = $this->formService->getFinalizedRevision($instanceId, 1, $actor);

            $inst = $bundle['instance'];
            $defBundle = $this->definitionService->getActiveDefinition((string) $inst['form_definition_id'], (int) $actor['tenant_id']);
            $layout = (string) ($this->request->getGet('layout') ?? $this->request->getVar('layout') ?? 'single');

            $issued = $this->documentService->issueDocument(
                $inst,
                $defBundle['definition'],
                $defBundle['revision'],
                $bundle['data'],
                $bundle['attestation'] ?? [],
                $actor,
                ['layout' => $layout]
            );

            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'attachment; filename="form-' . $inst['instance_number'] . '.pdf"')
                ->setHeader('Cache-Control', 'private, no-store, max-age=0')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setBody($issued['pdf']);
        } catch (Throwable $e) {
            return $this->failJson($e);
        }
    }

    /**
     * Public non-PII verification disclosure.
     */
    public function verify(string $code)
    {
        $verification = $this->documentService->verifyCode($code);

        if ($this->wantsJson()) {
            if ($verification === null) {
                return $this->response->setStatusCode(404)->setJSON([
                    'status' => 'not_found',
                    'message' => 'Document verification code not found.',
                ]);
            }

            return $this->response->setJSON([
                'status' => 'success',
                'verification' => $verification,
            ]);
        }

        $this->response
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Robots-Tag', 'noindex, nofollow');

        if ($verification === null) {
            return $this->response->setStatusCode(404)->setBody(view('guidance/verify', [
                'title'        => 'Document Verification',
                'found'        => false,
                'code'         => trim(strtoupper($code)),
                'verification' => null,
            ]));
        }

        $meta = $this->resolveNonPiiFormMetadata((string) $verification['verification_code']);
        $safeDisclosure = [
            'verification_code' => (string) ($verification['verification_code'] ?? ''),
            'form_code'         => (string) ($verification['form_code'] ?? $meta['form_code'] ?? 'Controlled Form'),
            'revision'          => (string) ($verification['revision'] ?? $meta['revision'] ?? '00'),
            'issued_at'         => (string) ($verification['issued_at'] ?? ''),
            'status'            => (string) ($verification['status'] ?? 'active'),
            'void_reason'       => $verification['void_reason'] ?? null,
            'pdf_sha256'        => (string) ($verification['pdf_sha256'] ?? ''),
        ];

        return $this->response->setStatusCode(200)->setBody(view('guidance/verify', [
            'title'        => 'Document Verification',
            'found'        => true,
            'code'         => $safeDisclosure['verification_code'],
            'verification' => $safeDisclosure,
        ]));
    }

    private function wantsJson(): bool
    {
        return $this->request->isAJAX()
            || strtolower($this->request->getHeaderLine('HX-Request')) === 'true'
            || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'application/json')
            || str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json')
            || $this->request->is('json');
    }

    /**
     * Fetches strictly non-PII form_code and revision for public HTML disclosure.
     *
     * @return array{form_code: string|null, revision: string|null}
     */
    private function resolveNonPiiFormMetadata(string $verificationCode): array
    {
        try {
            $row = \Config\Database::connect()->table('guidance_document_issuances i')
                ->select('d.form_code, dr.revision_number AS def_revision, r.revision_number AS inst_revision')
                ->join('guidance_form_instances fi', 'fi.id = i.form_instance_id', 'left')
                ->join('guidance_form_definitions d', 'd.id = fi.form_definition_id', 'left')
                ->join('guidance_form_definition_revisions dr', 'dr.id = fi.definition_revision_id', 'left')
                ->join('guidance_form_revisions r', 'r.id = i.form_revision_id', 'left')
                ->where('i.verification_code', trim(strtoupper($verificationCode)))
                ->get()
                ->getRowArray();

            if ($row === null) {
                return ['form_code' => null, 'revision' => null];
            }

            return [
                'form_code' => !empty($row['form_code']) ? (string) $row['form_code'] : null,
                'revision'  => !empty($row['def_revision'])
                    ? (string) $row['def_revision']
                    : (!empty($row['inst_revision']) ? (string) $row['inst_revision'] : null),
            ];
        } catch (Throwable) {
            return ['form_code' => null, 'revision' => null];
        }
    }

    private function failJson(Throwable $e)
    {
        $code = $e->getCode();
        $status = is_int($code) && $code >= 400 && $code <= 599 ? $code : 500;
        return $this->response->setStatusCode($status)->setJSON([
            'status' => 'error',
            'message' => $e->getMessage(),
        ]);
    }
}

