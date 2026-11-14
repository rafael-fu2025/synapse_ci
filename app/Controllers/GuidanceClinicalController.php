<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\Guidance\FormAttestationService;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceClinicalFormService;
use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use App\Services\Guidance\GuidancePractitionerService;
use App\Services\Guidance\GuidanceTransferService;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GuidanceClinicalController extends BaseController
{
    private GuidanceClinicalFormService $clinicalFormService;
    private GuidanceFormDefinitionService $definitionService;
    private GuidanceDocumentService $documentService;
    private GuidancePractitionerService $practitionerService;
    private GuidanceTransferService $transferService;
    private GuidanceAccessPolicy $accessPolicy;

    public function __construct(
        ?GuidanceClinicalFormService $clinicalFormService = null,
        ?GuidanceFormDefinitionService $definitionService = null,
        ?GuidanceDocumentService $documentService = null,
        ?GuidancePractitionerService $practitionerService = null,
        ?GuidanceTransferService $transferService = null,
        ?GuidanceAccessPolicy $accessPolicy = null
    ) {
        $this->clinicalFormService = $clinicalFormService ?? new GuidanceClinicalFormService();
        $this->definitionService   = $definitionService ?? new GuidanceFormDefinitionService();
        $this->documentService     = $documentService ?? new GuidanceDocumentService();
        $this->practitionerService = $practitionerService ?? new GuidancePractitionerService();
        $this->transferService     = $transferService ?? new GuidanceTransferService();
        $this->accessPolicy        = $accessPolicy ?? new GuidanceAccessPolicy();
    }

    /**
     * Supervisor Co-Sign Queue: lists pending_cosign clinical form instances.
     */
    public function cosignQueue()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $actor = $this->resolveStaffActor();
            $tenantId = (int) $actor['tenant_id'];
            $actorId = (int) $actor['id'];

            $practitionerProfile = $this->practitionerService->getPractitionerProfile($actorId, $tenantId);
            $canCoSign = $this->practitionerService->canFinalizeDirectly($actorId, $tenantId);
            $perms = $actor['permissions'] ?? [];
            $hasOversight = ($actor['role'] ?? '') === 'superadmin'
                || in_array('guidance.clinical.cosign', $perms, true)
                || in_array('guidance.cases.read_any', $perms, true)
                || in_array($actor['role'] ?? '', ['guidance_admin', 'guidance_supervisor'], true);

            $db = Database::connect();
            $builder = $db->table('guidance_form_instances gfi')
                ->select('gfi.*, gfd.internal_key AS form_key, gfd.form_code, gfd.canonical_title AS form_title')
                ->select('p.first_name AS client_first_name, p.last_name AS client_last_name, p.mis_id AS client_mis_id')
                ->select('au.username AS author_username, ap.first_name AS author_first_name, ap.last_name AS author_last_name')
                ->select('su.username AS supervisor_username, sp.first_name AS supervisor_first_name, sp.last_name AS supervisor_last_name')
                ->join('guidance_form_definitions gfd', 'gfd.id = gfi.form_definition_id AND gfd.tenant_id = gfi.tenant_id')
                ->join('people p', 'p.id = gfi.person_id AND p.tenant_id = gfi.tenant_id', 'left')
                ->join('users au', 'au.id = gfi.created_by_user_id AND au.tenant_id = gfi.tenant_id', 'left')
                ->join('people ap', 'ap.id = au.person_id AND ap.tenant_id = au.tenant_id', 'left')
                ->join('users su', 'su.id = gfi.assigned_supervisor_user_id AND su.tenant_id = gfi.tenant_id', 'left')
                ->join('people sp', 'sp.id = su.person_id AND sp.tenant_id = su.tenant_id', 'left')
                ->where('gfi.tenant_id', $tenantId)
                ->where('gfi.status', 'pending_cosign')
                ->whereIn('gfd.internal_key', [
                    GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
                    GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_CONTACT,
                    GuidanceClinicalFormService::FORM_COUNSELING_CONTACT,
                ]);

            if (!$hasOversight) {
                $builder->where('gfi.assigned_supervisor_user_id', $actorId);
            }

            $pendingForms = $builder
                ->orderBy('gfi.submitted_for_cosign_at', 'ASC')
                ->orderBy('gfi.id', 'DESC')
                ->get()
                ->getResultArray();

            return view('guidance/clinical/cosign_queue', [
                'title'               => 'Supervisor Co-Sign Queue',
                'pageTitle'           => 'Supervisor Co-Sign Queue',
                'pendingForms'        => $pendingForms,
                'actor'               => $actor,
                'practitionerProfile' => $practitionerProfile,
                'canCoSign'           => $canCoSign,
                'hasOversight'        => $hasOversight,
                'breadcrumbs'         => [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'Co-Sign Queue', 'url' => ''],
                ],
            ]);
        } catch (Throwable $e) {
            [$code, $message] = $this->safeFailure($e, 'clinical.cosignQueue');
            return $this->response->setStatusCode($code)->setContentType('text/html')
                ->setBody('<div class="alert alert-danger m-3">' . esc($message) . '</div>');
        }
    }

    /**
     * Render blank/prefilled clinical form editor for a new draft.
     */
    public function newForm(string $formKey)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $actor = $this->resolveStaffActor();
            $tenantId = (int) $actor['tenant_id'];
            $actorId = (int) $actor['id'];

            $canonicalKey = $this->clinicalFormService->resolveCanonicalKey($formKey);
            $defBundle = $this->ensureActiveDefinition($canonicalKey, $tenantId);
            $definition = $defBundle['definition'];
            $defRevision = $defBundle['revision'];

            $caseId = (int) ($this->request->getGet('case_id') ?? 0);
            $personId = (int) ($this->request->getGet('person_id') ?? 0);
            $episodeId = (int) ($this->request->getGet('episode_id') ?? 0);
            $sessionId = (int) ($this->request->getGet('session_id') ?? 0);
            $appointmentId = (int) ($this->request->getGet('appointment_id') ?? 0);

            $db = Database::connect();
            if ($caseId > 0) {
                $caseRow = $db->table('counselling_cases')
                    ->where('id', $caseId)
                    ->where('tenant_id', $tenantId)
                    ->where('deleted_at', null)
                    ->get()->getRowArray();
                if ($caseRow !== null) {
                    if ($personId <= 0) {
                        $personId = (int) ($caseRow['person_id'] ?? 0);
                    }
                    if ($episodeId <= 0) {
                        $epRow = $db->table('guidance_episodes')
                            ->where('case_id', $caseId)
                            ->where('tenant_id', $tenantId)
                            ->where('deleted_at', null)
                            ->orderBy('id', 'DESC')
                            ->get()->getRowArray();
                        if ($epRow !== null) {
                            $episodeId = (int) $epRow['id'];
                        }
                    }
                }
            }

            $person = $personId > 0 ? $this->loadSubjectPerson($personId, $tenantId) : null;
            $prefillData = $this->buildPrefillData($canonicalKey, $person);

            $practitionerProfile = $this->practitionerService->getPractitionerProfile($actorId, $tenantId);
            $activeSupervision = $this->practitionerService->getActiveSupervision($actorId, $tenantId);
            $canFinalizeDirectly = $this->practitionerService->canFinalizeDirectly($actorId, $tenantId);
            $schema = json_decode((string) ($defRevision['schema_json'] ?? $defRevision['field_schema_json'] ?? '{}'), true) ?: [];
            $attestationStatement = $this->buildFormAttestationStatement($actor, $definition, $defRevision);

            $defTitle = (string) ($definition['canonical_title'] ?? $definition['title'] ?? 'New Clinical Form');

            return view('guidance/clinical/form_editor', [
                'title'                => $defTitle,
                'pageTitle'            => $defTitle,
                'mode'                 => 'create',
                'formKey'              => $canonicalKey,
                'definition'           => $definition,
                'defRevision'          => $defRevision,
                'schema'               => $schema,
                'instance'             => null,
                'draft'                => null,
                'revision'             => null,
                'attestations'         => [],
                'formData'             => $prefillData,
                'person'               => $person,
                'contextIds'           => [
                    'person_id'      => $personId > 0 ? $personId : null,
                    'case_id'        => $caseId > 0 ? $caseId : null,
                    'episode_id'     => $episodeId > 0 ? $episodeId : null,
                    'session_id'     => $sessionId > 0 ? $sessionId : null,
                    'appointment_id' => $appointmentId > 0 ? $appointmentId : null,
                ],
                'actor'                => $actor,
                'practitionerProfile'  => $practitionerProfile,
                'activeSupervision'    => $activeSupervision,
                'canFinalizeDirectly'  => $canFinalizeDirectly,
                'isAssignedSupervisor' => false,
                'authorInfo'           => null,
                'supervisorInfo'       => null,
                'attestationStatement' => $attestationStatement,
                'breadcrumbs'          => [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'New ' . (string) ($definition['form_code'] ?? $canonicalKey), 'url' => ''],
                ],
            ]);
        } catch (Throwable $e) {
            [$code, $message] = $this->safeFailure($e, 'clinical.newForm');
            return $this->response->setStatusCode($code)->setContentType('text/html')
                ->setBody('<div class="alert alert-danger m-3">' . esc($message) . '</div>');
        }
    }

    /**
     * Render clinical form editor for an existing draft, pending_cosign, or finalized instance.
     */
    public function editForm(int $instanceId)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $actor = $this->resolveStaffActor();
            $tenantId = (int) $actor['tenant_id'];
            $actorId = (int) $actor['id'];

            [$instance, $definition, $defRevision] = $this->loadInstanceAndDefinition($instanceId, $tenantId);
            $formKey = (string) ($definition['form_key'] ?? '');
            $status = (string) ($instance['status'] ?? 'draft');

            $draft = null;
            $revision = null;
            $attestations = [];
            if ($status === 'finalized') {
                $revNum = max(1, (int) ($instance['current_revision_number'] ?? 1));
                $bundle = $this->clinicalFormService->getFinalizedClinicalForm($instanceId, $revNum, $tenantId, $actor);
                $instance = $bundle['instance'];
                $revision = $bundle['revision'];
                $formData = $bundle['data'] ?? [];
                $attestations = $bundle['attestations'] ?? [];
            } else {
                $bundle = $this->clinicalFormService->getClinicalDraft($instanceId, $tenantId, $actor);
                $instance = $bundle['instance'];
                $draft = $bundle['draft'];
                $formData = $bundle['data'] ?? [];
            }

            $personId = (int) ($instance['person_id'] ?? 0);
            $person = $personId > 0 ? $this->loadSubjectPerson($personId, $tenantId) : null;
            $authorInfo = !empty($instance['created_by_user_id'])
                ? $this->loadUserSummary((int) $instance['created_by_user_id'], $tenantId)
                : null;
            $supervisorInfo = !empty($instance['assigned_supervisor_user_id'])
                ? $this->loadUserSummary((int) $instance['assigned_supervisor_user_id'], $tenantId)
                : null;

            $practitionerProfile = $this->practitionerService->getPractitionerProfile($actorId, $tenantId);
            $activeSupervision = $this->practitionerService->getActiveSupervision($actorId, $tenantId);
            $canFinalizeDirectly = $this->practitionerService->canFinalizeDirectly($actorId, $tenantId);
            $isAssignedSupervisor = ((int) ($instance['assigned_supervisor_user_id'] ?? 0) === $actorId)
                && $canFinalizeDirectly;

            $schema = json_decode((string) ($defRevision['schema_json'] ?? $defRevision['field_schema_json'] ?? '{}'), true) ?: [];
            $attestationStatement = $this->buildFormAttestationStatement($actor, $definition, $defRevision, $instance);

            $breadcrumbs = [
                ['label' => 'Guidance', 'url' => site_url('/guidance')],
            ];
            if (!empty($instance['case_id'])) {
                $breadcrumbs[] = ['label' => 'Case #' . (int) $instance['case_id'], 'url' => site_url('/guidance/cases/' . (int) $instance['case_id'])];
            }
            $breadcrumbs[] = ['label' => (string) ($instance['instance_number'] ?? ('Form #' . $instanceId)), 'url' => ''];

            $defTitle = (string) ($definition['canonical_title'] ?? $definition['title'] ?? 'Clinical Form');

            return view('guidance/clinical/form_editor', [
                'title'                => $defTitle . ' — ' . (string) ($instance['instance_number'] ?? '#' . $instanceId),
                'pageTitle'            => $defTitle,
                'mode'                 => $status === 'finalized' ? 'view' : 'edit',
                'formKey'              => $formKey,
                'definition'           => $definition,
                'defRevision'          => $defRevision,
                'schema'               => $schema,
                'instance'             => $instance,
                'draft'                => $draft,
                'revision'             => $revision,
                'attestations'         => $attestations,
                'formData'             => $formData,
                'person'               => $person,
                'contextIds'           => [
                    'person_id'      => $personId > 0 ? $personId : null,
                    'case_id'        => !empty($instance['case_id']) ? (int) $instance['case_id'] : null,
                    'episode_id'     => !empty($instance['episode_id']) ? (int) $instance['episode_id'] : null,
                    'session_id'     => !empty($instance['session_id']) ? (int) $instance['session_id'] : null,
                    'appointment_id' => !empty($instance['appointment_id']) ? (int) $instance['appointment_id'] : null,
                ],
                'actor'                => $actor,
                'practitionerProfile'  => $practitionerProfile,
                'activeSupervision'    => $activeSupervision,
                'canFinalizeDirectly'  => $canFinalizeDirectly,
                'isAssignedSupervisor' => $isAssignedSupervisor,
                'authorInfo'           => $authorInfo,
                'supervisorInfo'       => $supervisorInfo,
                'attestationStatement' => $attestationStatement,
                'breadcrumbs'          => $breadcrumbs,
            ]);
        } catch (Throwable $e) {
            [$code, $message] = $this->safeFailure($e, 'clinical.editForm', $instanceId);
            return $this->response->setStatusCode($code)->setContentType('text/html')
                ->setBody('<div class="alert alert-danger m-3">' . esc($message) . '</div>');
        }
    }

    /**
     * Create a new encrypted clinical form draft (POST).
     */
    public function createForm()
    {
        $fallbackCaseId = (int) ($this->request->getPost('case_id') ?? 0);
        $fallback = $fallbackCaseId > 0 ? site_url('/guidance/cases/' . $fallbackCaseId) : site_url('/guidance');

        return $this->mutate(function (array $actor): array {
            $tenantId = (int) $actor['tenant_id'];
            $formKeyInput = trim((string) ($this->request->getPost('form_key') ?? ''));
            $canonicalKey = $this->clinicalFormService->resolveCanonicalKey($formKeyInput);

            $rawFields = $this->request->getPost('fields');
            if (!is_array($rawFields)) {
                $rawFields = $this->request->getPost() ?? [];
            }

            $payload = $this->normalizeClinicalFields($canonicalKey, $rawFields);

            foreach (['person_id', 'case_id', 'episode_id', 'session_id', 'appointment_id'] as $ctxKey) {
                $ctxVal = (int) ($this->request->getPost($ctxKey) ?? 0);
                if ($ctxVal > 0) {
                    $payload[$ctxKey] = $ctxVal;
                }
            }

            $created = $this->clinicalFormService->createClinicalDraft($canonicalKey, $payload, $tenantId, $actor);
            $newId = (int) ($created['id'] ?? 0);
            $editPath = '/guidance/clinical/forms/' . $newId;

            return [
                $editPath,
                'Clinical form draft ' . (string) ($created['instance_number'] ?? ('#' . $newId)) . ' created.',
                ['mode' => 'navigate', 'url' => site_url($editPath)],
            ];
        }, $fallback);
    }

    /**
     * Save updates to an existing clinical form draft (POST).
     */
    public function saveDraft(int $instanceId)
    {
        return $this->mutate(function (array $actor) use ($instanceId): array {
            $tenantId = (int) $actor['tenant_id'];
            [$instance, $definition] = $this->loadInstanceAndDefinition($instanceId, $tenantId);
            $formKey = (string) ($definition['form_key'] ?? '');

            $rawFields = $this->request->getPost('fields');
            if (!is_array($rawFields)) {
                $rawFields = $this->request->getPost() ?? [];
            }

            $payload = $this->normalizeClinicalFields($formKey, $rawFields);
            $this->clinicalFormService->updateClinicalDraft($instanceId, $payload, $tenantId, $actor);

            return [
                '/guidance/clinical/forms/' . $instanceId,
                'Clinical form draft saved.',
            ];
        }, site_url('/guidance/clinical/forms/' . $instanceId));
    }

    /**
     * Associate submits clinical form draft to assigned supervisor for co-sign (POST).
     */
    public function submitForCoSign(int $instanceId)
    {
        return $this->mutate(function (array $actor) use ($instanceId): array {
            $tenantId = (int) $actor['tenant_id'];
            [$instance, $definition] = $this->loadInstanceAndDefinition($instanceId, $tenantId);
            $formKey = (string) ($definition['form_key'] ?? '');

            $rawFields = $this->request->getPost('fields');
            if (is_array($rawFields) && $rawFields !== []) {
                $payload = $this->normalizeClinicalFields($formKey, $rawFields);
                $this->clinicalFormService->updateClinicalDraft($instanceId, $payload, $tenantId, $actor);
            }

            $notes = trim((string) ($this->request->getPost('submission_notes') ?? ''));
            $this->clinicalFormService->submitForCoSign(
                $instanceId,
                $notes !== '' ? $notes : null,
                $tenantId,
                $actor
            );

            return [
                '/guidance/clinical/forms/' . $instanceId,
                'Clinical form submitted to your supervisor for co-signature.',
            ];
        }, site_url('/guidance/clinical/forms/' . $instanceId));
    }

    /**
     * Assigned supervisor co-signs and finalizes a pending_cosign clinical form (POST).
     */
    public function coSignFinalize(int $instanceId)
    {
        return $this->mutate(function (array $actor) use ($instanceId): array {
            $tenantId = (int) $actor['tenant_id'];
            [$instance, $definition] = $this->loadInstanceAndDefinition($instanceId, $tenantId);

            $confirmed = (string) ($this->request->getPost('attestation_confirmed') ?? '1');
            if ($confirmed !== '1' && $confirmed !== 'true' && $confirmed !== 'on') {
                throw new InvalidArgumentException('You must confirm the digital attestation checkbox before co-signing.', 422);
            }

            $statement = trim((string) ($this->request->getPost('attestation_statement') ?? ''));
            if ($statement === '') {
                $statement = $this->buildFormAttestationStatement($actor, $definition, null, $instance);
            }

            $supervisorNotes = trim((string) ($this->request->getPost('supervisor_notes') ?? ''));
            $this->clinicalFormService->coSignAndFinalize(
                $instanceId,
                $statement,
                $supervisorNotes !== '' ? $supervisorNotes : null,
                $tenantId,
                $actor
            );

            return [
                '/guidance/clinical/forms/' . $instanceId,
                'Clinical form co-signed and finalized.',
            ];
        }, site_url('/guidance/clinical/forms/' . $instanceId));
    }

    /**
     * Licensed counselor or supervisor directly attests and finalizes a clinical form (POST).
     */
    public function finalizeDirect(int $instanceId)
    {
        return $this->mutate(function (array $actor) use ($instanceId): array {
            $tenantId = (int) $actor['tenant_id'];
            [$instance, $definition] = $this->loadInstanceAndDefinition($instanceId, $tenantId);
            $formKey = (string) ($definition['form_key'] ?? '');

            $rawFields = $this->request->getPost('fields');
            if (is_array($rawFields) && $rawFields !== [] && ($instance['status'] ?? '') === 'draft') {
                $payload = $this->normalizeClinicalFields($formKey, $rawFields);
                $this->clinicalFormService->updateClinicalDraft($instanceId, $payload, $tenantId, $actor);
            }

            $confirmed = (string) ($this->request->getPost('attestation_confirmed') ?? '1');
            if ($confirmed !== '1' && $confirmed !== 'true' && $confirmed !== 'on') {
                throw new InvalidArgumentException('You must confirm the digital attestation checkbox before finalizing.', 422);
            }

            $statement = trim((string) ($this->request->getPost('attestation_statement') ?? ''));
            if ($statement === '') {
                $statement = $this->buildFormAttestationStatement($actor, $definition, null, $instance);
            }

            $this->clinicalFormService->finalizeDirectly($instanceId, $statement, $tenantId, $actor);

            return [
                '/guidance/clinical/forms/' . $instanceId,
                'Clinical form digitally attested and finalized.',
            ];
        }, site_url('/guidance/clinical/forms/' . $instanceId));
    }

    /**
     * Stream clinical form PDF (?mode=preview|issue) with strict cache headers.
     */
    public function pdf(int $instanceId)
    {
        try {
            $actor = $this->resolveStaffActor();
            $tenantId = (int) $actor['tenant_id'];
            $mode = strtolower(trim((string) ($this->request->getGet('mode') ?? 'preview')));
            if (!in_array($mode, ['preview', 'issue'], true)) {
                $mode = 'preview';
            }

            [$instance, $definition, $defRevision] = $this->loadInstanceAndDefinition($instanceId, $tenantId);
            $formKey = (string) ($definition['form_key'] ?? '');
            $status = (string) ($instance['status'] ?? 'draft');
            $revNum = max(1, (int) ($instance['current_revision_number'] ?? 1));

            if ($mode === 'issue') {
                if ($status !== 'finalized') {
                    throw new DomainException('Clinical form must be finalized or co-signed before issuing an official PDF.', 409);
                }
                $bundle = $this->clinicalFormService->getFinalizedClinicalForm($instanceId, $revNum, $tenantId, $actor);
                $attestations = $bundle['attestations'] ?? [];
                $attestation = !empty($attestations) ? end($attestations) : [];
                $issued = $this->documentService->issueDocument(
                    $bundle['instance'],
                    $definition,
                    $defRevision,
                    $bundle['data'],
                    is_array($attestation) ? $attestation : [],
                    $actor
                );
                $pdfBytes = $issued['pdf'];
            } else {
                if ($status === 'finalized') {
                    $bundle = $this->clinicalFormService->getFinalizedClinicalForm($instanceId, $revNum, $tenantId, $actor);
                    $attestations = $bundle['attestations'] ?? [];
                    $attestation = !empty($attestations) ? end($attestations) : [];
                    $snapshot = [
                        'instance'    => $bundle['instance'],
                        'definition'  => $definition,
                        'revision'    => $defRevision,
                        'data'        => $bundle['data'],
                        'attestation' => is_array($attestation) ? $attestation : [],
                        'is_draft'    => false,
                    ];
                    $pdfBytes = match ($formKey) {
                        GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW => $this->documentService->renderPsychosocialInterviewPdf($snapshot)['pdf'],
                        GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_CONTACT   => $this->documentService->renderPsychosocialContactPdf($snapshot)['pdf'],
                        GuidanceClinicalFormService::FORM_COUNSELING_CONTACT     => $this->documentService->renderCounselingContactPdf($snapshot)['pdf'],
                        default => throw new InvalidArgumentException('Unsupported clinical form key.', 422),
                    };
                } else {
                    $draftBundle = $this->clinicalFormService->getClinicalDraft($instanceId, $tenantId, $actor);
                    $preview = $this->documentService->generateDraftPreview(
                        $draftBundle['instance'],
                        $definition,
                        $defRevision,
                        $draftBundle['data']
                    );
                    $pdfBytes = $preview['pdf'];
                }
            }

            $filename = ($definition['form_code'] ?? $formKey) . '-' . ($instance['instance_number'] ?? $instanceId) . '.pdf';

            return $this->response->setStatusCode(200)
                ->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setHeader('Content-Disposition', 'inline; filename="' . str_replace('"', '', (string) $filename) . '"')
                ->setContentType('application/pdf')
                ->setBody($pdfBytes);
        } catch (Throwable $e) {
            [$code, $message] = $this->safeFailure($e, 'clinical.pdf', $instanceId);
            return $this->response->setStatusCode($code)
                ->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setContentType('text/html')
                ->setBody('<div class="alert alert-danger m-3">' . esc($message) . '</div>');
        }
    }

    /**
     * Request a case transfer or consultation (POST).
     */
    public function requestTransfer(int $caseId)
    {
        return $this->mutate(function (array $actor) use ($caseId): array {
            $toUserId = (int) ($this->request->getPost('to_counsellor_user_id') ?? 0);
            $type = trim((string) ($this->request->getPost('transfer_type') ?? 'full_transfer'));
            $reason = trim((string) ($this->request->getPost('reason') ?? ''));

            if ($toUserId <= 0) {
                throw new InvalidArgumentException('Please select a receiving counselor.', 422);
            }

            $this->transferService->requestTransfer($caseId, $toUserId, $type, $reason, $actor);

            return [
                '/guidance/cases/' . $caseId,
                'Case transfer request submitted.',
            ];
        }, site_url('/guidance/cases/' . $caseId));
    }

    /**
     * Accept or decline a pending case transfer offer (POST).
     */
    public function respondTransfer(int $transferId)
    {
        $fallback = site_url('/guidance/cases');
        return $this->mutate(function (array $actor) use ($transferId): array {
            $decision = strtolower(trim((string) ($this->request->getPost('decision') ?? '')));
            $acceptFlag = $this->request->getPost('accept');
            $accept = $decision !== ''
                ? ($decision === 'accept')
                : in_array((string) $acceptFlag, ['1', 'true', 'on'], true);

            $notes = trim((string) ($this->request->getPost('response_notes') ?? ''));
            $result = $this->transferService->respondTransfer(
                $transferId,
                $accept,
                $notes !== '' ? $notes : null,
                $actor
            );

            $caseId = (int) ($result['case_id'] ?? 0);
            $target = $caseId > 0 ? '/guidance/cases/' . $caseId : '/guidance/cases';
            $verb = $accept ? 'accepted' : 'declined';

            return [$target, 'Case transfer offer ' . $verb . '.'];
        }, $fallback);
    }

    /**
     * Supervisor direct case reassignment (POST).
     */
    public function reassignCase(int $caseId)
    {
        return $this->mutate(function (array $actor) use ($caseId): array {
            $newUserId = (int) ($this->request->getPost('new_counsellor_user_id') ?? 0);
            $reason = trim((string) ($this->request->getPost('reason') ?? ''));

            if ($newUserId <= 0) {
                throw new InvalidArgumentException('Please select a new assigned counselor.', 422);
            }

            $this->transferService->reassignCase($caseId, $newUserId, $reason, $actor);

            return [
                '/guidance/cases/' . $caseId,
                'Case reassigned successfully.',
            ];
        }, site_url('/guidance/cases/' . $caseId));
    }

    private function ensureActiveDefinition(string $formKey, int $tenantId): array
    {
        try {
            return $this->definitionService->getActiveDefinition($formKey, $tenantId);
        } catch (InvalidArgumentException $e) {
            if ((int) $e->getCode() === 404) {
                $this->definitionService->syncManifestToDatabase($tenantId);
                return $this->definitionService->getActiveDefinition($formKey, $tenantId);
            }
            throw $e;
        }
    }

    private function loadInstanceAndDefinition(int $instanceId, int $tenantId): array
    {
        $db = Database::connect();
        $instance = $db->table('guidance_form_instances')
            ->where('id', $instanceId)
            ->where('tenant_id', $tenantId)
            ->get()->getRowArray();

        if ($instance === null) {
            throw new InvalidArgumentException('Clinical form instance not found.', 404);
        }

        $definition = $db->table('guidance_form_definitions')
            ->where('id', (int) $instance['form_definition_id'])
            ->where('tenant_id', $tenantId)
            ->get()->getRowArray();

        if ($definition === null) {
            throw new InvalidArgumentException('Clinical form definition not found.', 404);
        }

        $formKey = (string) ($definition['internal_key'] ?? $definition['form_key'] ?? '');
        $this->clinicalFormService->resolveCanonicalKey($formKey);
        $definition['form_key'] = $formKey;
        $definition['title'] = (string) ($definition['canonical_title'] ?? $definition['title'] ?? '');

        $defRevId = (int) ($instance['definition_revision_id'] ?? $instance['form_definition_revision_id'] ?? 0);
        $defRevision = $db->table('guidance_form_definition_revisions')
            ->where('id', $defRevId)
            ->get()->getRowArray();

        if ($defRevision === null) {
            $bundle = $this->ensureActiveDefinition($formKey, $tenantId);
            $definition = $bundle['definition'];
            $definition['form_key'] = (string) ($definition['internal_key'] ?? $formKey);
            $definition['title'] = (string) ($definition['canonical_title'] ?? $definition['title'] ?? '');
            $defRevision = $bundle['revision'];
        }

        return [$instance, $definition, $defRevision];
    }

    private function loadSubjectPerson(int $personId, int $tenantId): ?array
    {
        $db = Database::connect();
        return $db->table('people p')
            ->select('p.*, sp.program, sp.year_level, sp.section, ep.department, ep.position')
            ->join('student_profiles sp', 'sp.person_id = p.id', 'left')
            ->join('employee_profiles ep', 'ep.person_id = p.id', 'left')
            ->where('p.id', $personId)
            ->where('p.tenant_id', $tenantId)
            ->where('p.deleted_at', null)
            ->get()->getRowArray();
    }

    private function loadUserSummary(int $userId, int $tenantId): ?array
    {
        $db = Database::connect();
        return $db->table('users u')
            ->select('u.id, u.username, u.role, p.first_name, p.last_name, gpp.credential_tier, gpp.license_number')
            ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id', 'left')
            ->join('guidance_practitioner_profiles gpp', 'gpp.user_id = u.id AND gpp.tenant_id = u.tenant_id', 'left')
            ->where('u.id', $userId)
            ->where('u.tenant_id', $tenantId)
            ->where('u.deleted_at', null)
            ->get()->getRowArray();
    }

    private function buildFormAttestationStatement(
        array $actor,
        array $definition,
        ?array $defRevision = null,
        ?array $instance = null
    ): string {
        $signerName = trim((string) (($actor['first_name'] ?? '') . ' ' . ($actor['last_name'] ?? '')));
        if ($signerName === '') {
            $signerName = (string) ($actor['username'] ?? ('user-' . (int) ($actor['id'] ?? 0)));
        }
        $signerRole = (string) ($actor['role'] ?? 'counsellor');
        $formCode   = (string) ($definition['form_code'] ?? $definition['internal_key'] ?? '');
        $revNumber  = (string) ($defRevision['revision_number'] ?? max(1, (int) ($instance['current_revision_number'] ?? 1)));

        return FormAttestationService::buildStatement(
            $signerName,
            $signerRole,
            $formCode,
            $revNumber,
            date('c')
        );
    }

    private function buildPrefillData(string $canonicalKey, ?array $person): array
    {
        $today = date('Y-m-d');
        if ($canonicalKey === GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW) {
            $studentName = '';
            $studentId = '';
            $courseYear = '';
            $age = null;
            $gender = '';
            $contact = '';

            if ($person !== null) {
                $studentName = trim((string) (($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')));
                $studentId = (string) ($person['mis_id'] ?? '');
                $prog = trim((string) ($person['program'] ?? ''));
                $yr = trim((string) ($person['year_level'] ?? ''));
                if ($prog !== '' || $yr !== '') {
                    $courseYear = trim($prog . ($yr !== '' ? ' - ' . $yr : ''));
                } else {
                    $dept = trim((string) ($person['department'] ?? ''));
                    $pos = trim((string) ($person['position'] ?? ''));
                    $courseYear = trim($dept . ($pos !== '' ? ' - ' . $pos : ''));
                }
                if (!empty($person['birth_date'])) {
                    $dobTs = strtotime((string) $person['birth_date']);
                    if ($dobTs !== false) {
                        $age = (int) floor((time() - $dobTs) / 31556952);
                    }
                }
                $gender = (string) ($person['sex'] ?? $person['gender'] ?? '');
                $contact = (string) ($person['contact_number'] ?? $person['email'] ?? '');
            }

            return [
                'student_name'   => $studentName,
                'student_id'     => $studentId,
                'course_year'    => $courseYear,
                'interview_date' => $today,
                'demographics'   => [
                    'age'     => $age,
                    'gender'  => $gender,
                    'contact' => $contact,
                ],
                'mental_health_screening' => [
                    'phq9_score' => null,
                    'gad7_score' => null,
                    'risk_level' => '',
                ],
                'privacy_consent_acknowledged' => false,
            ];
        }

        if ($canonicalKey === GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_CONTACT) {
            return [
                'session_date'   => $today,
                'session_number' => 1,
                'modality'       => 'in_person',
            ];
        }

        return [
            'session_date'   => $today,
            'session_number' => 1,
            'time_start'     => '09:00',
            'time_end'       => '10:00',
            'session_type'   => 'regular',
        ];
    }

    /**
     * Normalize POSTed clinical form fields so integer and boolean types match schema validation.
     */
    private function normalizeClinicalFields(string $formKey, array $raw): array
    {
        if ($formKey === GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW) {
            $demo = is_array($raw['demographics'] ?? null) ? $raw['demographics'] : [];
            $cleanDemo = [];
            if (isset($demo['age']) && $demo['age'] !== '' && is_numeric($demo['age'])) {
                $cleanDemo['age'] = (int) $demo['age'];
            }
            if (isset($demo['gender']) && trim((string) $demo['gender']) !== '') {
                $cleanDemo['gender'] = trim((string) $demo['gender']);
            }
            if (isset($demo['contact']) && trim((string) $demo['contact']) !== '') {
                $cleanDemo['contact'] = trim((string) $demo['contact']);
            }

            $screen = is_array($raw['mental_health_screening'] ?? null) ? $raw['mental_health_screening'] : [];
            $cleanScreen = [];
            if (isset($screen['phq9_score']) && $screen['phq9_score'] !== '' && is_numeric($screen['phq9_score'])) {
                $cleanScreen['phq9_score'] = (int) $screen['phq9_score'];
            }
            if (isset($screen['gad7_score']) && $screen['gad7_score'] !== '' && is_numeric($screen['gad7_score'])) {
                $cleanScreen['gad7_score'] = (int) $screen['gad7_score'];
            }
            if (isset($screen['risk_level']) && trim((string) $screen['risk_level']) !== '') {
                $cleanScreen['risk_level'] = trim((string) $screen['risk_level']);
            }

            $out = [
                'student_name'                 => trim((string) ($raw['student_name'] ?? '')),
                'student_id'                   => trim((string) ($raw['student_id'] ?? '')),
                'course_year'                  => trim((string) ($raw['course_year'] ?? '')),
                'interview_date'               => trim((string) ($raw['interview_date'] ?? '')),
                'demographics'                 => $cleanDemo,
                'presenting_concerns'          => trim((string) ($raw['presenting_concerns'] ?? '')),
                'counselor_assessment'         => trim((string) ($raw['counselor_assessment'] ?? '')),
                'intervention_plan'            => trim((string) ($raw['intervention_plan'] ?? '')),
                'privacy_consent_acknowledged' => in_array((string) ($raw['privacy_consent_acknowledged'] ?? '0'), ['1', 'true', 'on'], true),
            ];

            if ($cleanScreen !== []) {
                $out['mental_health_screening'] = $cleanScreen;
            }
            $family = trim((string) ($raw['family_social_support'] ?? ''));
            if ($family !== '') {
                $out['family_social_support'] = $family;
            }

            return $out;
        }

        if ($formKey === GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_CONTACT) {
            $out = [
                'session_date'      => trim((string) ($raw['session_date'] ?? '')),
                'session_number'    => max(1, (int) ($raw['session_number'] ?? 1)),
                'modality'          => trim((string) ($raw['modality'] ?? 'in_person')),
                'client_state'      => trim((string) ($raw['client_state'] ?? '')),
                'interventions_used' => trim((string) ($raw['interventions_used'] ?? '')),
                'client_response'   => trim((string) ($raw['client_response'] ?? '')),
            ];
            $actionItems = trim((string) ($raw['action_items'] ?? ''));
            if ($actionItems !== '') {
                $out['action_items'] = $actionItems;
            }
            $nextDate = trim((string) ($raw['next_session_date'] ?? ''));
            if ($nextDate !== '') {
                $out['next_session_date'] = $nextDate;
            }
            return $out;
        }

        // FORM_COUNSELING_CONTACT (CCF-009)
        $out = [
            'session_date'               => trim((string) ($raw['session_date'] ?? '')),
            'session_number'             => max(1, (int) ($raw['session_number'] ?? 1)),
            'time_start'                 => trim((string) ($raw['time_start'] ?? '')),
            'time_end'                   => trim((string) ($raw['time_end'] ?? '')),
            'session_type'               => trim((string) ($raw['session_type'] ?? 'regular')),
            'concerns_discussed'         => trim((string) ($raw['concerns_discussed'] ?? '')),
            'counseling_goals'           => trim((string) ($raw['counseling_goals'] ?? '')),
            'interventions_and_responses' => trim((string) ($raw['interventions_and_responses'] ?? '')),
        ];
        $homework = trim((string) ($raw['homework_and_commitments'] ?? ''));
        if ($homework !== '') {
            $out['homework_and_commitments'] = $homework;
        }
        $nextSchedule = trim((string) ($raw['next_schedule'] ?? ''));
        if ($nextSchedule !== '') {
            // Normalize HTML5 datetime-local (YYYY-MM-DDTHH:MM) -> Y-m-d H:i:s
            $nextSchedule = str_replace('T', ' ', $nextSchedule);
            if (strlen($nextSchedule) === 16) {
                $nextSchedule .= ':00';
            }
            $out['next_schedule'] = $nextSchedule;
        }

        return $out;
    }

    private function resolveStaffActor(): array
    {
        try {
            $actor = $this->accessPolicy->current();
        } catch (DomainException|RuntimeException $e) {
            $user = (new AuthenticatedUser())->current();
            if ($user === null) {
                throw new RuntimeException(AuthenticatedUser::AUTHENTICATION_MESSAGE, 401, $e);
            }
            $actor = $this->accessPolicy->authorize([
                'id'        => (int) $user['id'],
                'tenant_id' => (int) $user['tenant_id'],
            ]);
        }

        $perms = $actor['permissions'] ?? [];
        $allowed = in_array('guidance.cases.read', $perms, true)
            || in_array('guidance.cases.read_any', $perms, true)
            || in_array('guidance.clinical.write', $perms, true)
            || in_array('guidance.clinical.cosign', $perms, true)
            || ($actor['role'] ?? '') === 'superadmin';
        if (!$allowed) {
            throw new DomainException('Guidance clinical permission forbidden.', 403);
        }

        return $actor;
    }

    private function mutate(callable $operation, string $fallback)
    {
        try {
            $actor = $this->resolveStaffActor();
            [$url, $message, $refresh] = array_pad($operation($actor), 3, null);
        } catch (Throwable $error) {
            [$status, $message] = $this->safeFailure($error, 'clinical.mutation');
            if ($this->wantsJson()) {
                return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
                    ->setJSON([
                        'success' => false,
                        'message' => $message,
                        'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }
            return ($status === 422 ? redirect()->back()->withInput() : redirect()->to($fallback))->with('error', $message);
        }

        $targetUrl = site_url($url);
        if ($this->wantsJson()) {
            if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                session()->setFlashdata('success', $message);
            }
            $json = [
                'success'  => true,
                'message'  => $message,
                'redirect' => $targetUrl,
                'csrf'     => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ];
            if ($refresh !== null) {
                $json['refresh'] = $refresh;
            }
            return $this->response->setStatusCode(200)->setHeader('Cache-Control', 'no-store')
                ->setJSON($json);
        }

        return redirect()->to($targetUrl)->with('success', $message);
    }

    private function wantsJson(): bool
    {
        return $this->request->isAJAX()
            || strtolower($this->request->getHeaderLine('HX-Request')) === 'true'
            || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'application/json')
            || $this->request->is('json');
    }

    private function safeFailure(Throwable $error, string $operation, int $recordId = 0): array
    {
        $status = (int) $error->getCode();
        if (!in_array($status, [401, 403, 404, 409, 422], true)) {
            $status = 500;
            log_message('error', 'Guidance clinical error: operation={operation} record_id={record_id} exception={exception_class} code={error_code}', [
                'operation'       => $operation,
                'record_id'       => $recordId,
                'exception_class' => get_class($error),
                'error_code'      => $error->getCode(),
            ]);
        }

        $message = match ($status) {
            401     => AuthenticatedUser::AUTHENTICATION_MESSAGE,
            403     => $error->getMessage() !== '' ? $error->getMessage() : 'You are not authorized to perform this clinical action.',
            404     => $error->getMessage() !== '' ? $error->getMessage() : 'The requested clinical form was not found.',
            409     => $error->getMessage() !== '' ? $error->getMessage() : 'Clinical form state conflict.',
            422     => $error->getMessage() !== '' ? $error->getMessage() : 'Please review the clinical form fields and try again.',
            default => 'Unable to complete the clinical form operation. Please try again later.',
        };

        return [$status, $message];
    }
}
