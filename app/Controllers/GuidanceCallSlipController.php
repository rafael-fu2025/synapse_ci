<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceAppointmentService;
use App\Services\Guidance\GuidanceCallSlipService;
use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use App\Services\Guidance\GuidanceFormService;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GuidanceCallSlipController extends BaseController
{
    private GuidanceCallSlipService $callSlipService;
    private GuidanceAppointmentService $appointmentService;
    private GuidanceFormService $formService;
    private GuidanceFormDefinitionService $definitionService;
    private GuidanceDocumentService $documentService;
    private GuidanceAccessPolicy $accessPolicy;

    public function __construct(
        ?GuidanceCallSlipService $callSlipService = null,
        ?GuidanceAppointmentService $appointmentService = null,
        ?GuidanceFormService $formService = null,
        ?GuidanceFormDefinitionService $definitionService = null,
        ?GuidanceDocumentService $documentService = null,
        ?GuidanceAccessPolicy $accessPolicy = null
    ) {
        $this->callSlipService    = $callSlipService ?? new GuidanceCallSlipService();
        $this->appointmentService = $appointmentService ?? new GuidanceAppointmentService();
        $this->formService        = $formService ?? new GuidanceFormService();
        $this->definitionService  = $definitionService ?? new GuidanceFormDefinitionService();
        $this->documentService    = $documentService ?? new GuidanceDocumentService();
        $this->accessPolicy       = $accessPolicy ?? new GuidanceAccessPolicy();
    }

    /**
     * List guidance call slips with status filters and Issue Call Slip modal.
     */
    public function index()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $actor = $this->resolveStaffActor();
            $tenantId = (int) $actor['tenant_id'];
            $status = trim((string) ($this->request->getGet('status') ?? ''));
            if ($status !== '' && !in_array($status, GuidanceCallSlipService::VALID_STATUSES, true)) {
                $status = '';
            }

            $db = Database::connect();
            $builder = $db->table('guidance_call_slips cs')
                ->select('cs.*, p.first_name, p.last_name, p.mis_id, sp.program, sp.year_level')
                ->select('cu.username AS counselor_username, cp.first_name AS counselor_first_name, cp.last_name AS counselor_last_name')
                ->select('fi.instance_number AS form_instance_number, fi.status AS form_instance_status')
                ->join('people p', 'p.id = cs.person_id AND p.tenant_id = cs.tenant_id')
                ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id', 'left')
                ->join('users cu', 'cu.id = cs.issuing_counselor_user_id AND cu.tenant_id = cs.tenant_id', 'left')
                ->join('people cp', 'cp.id = cu.person_id AND cp.tenant_id = cu.tenant_id', 'left')
                ->join('guidance_form_instances fi', 'fi.id = cs.form_instance_id AND fi.tenant_id = cs.tenant_id', 'left')
                ->where('cs.tenant_id', $tenantId)
                ->where('cs.deleted_at', null)
                ->where('p.deleted_at', null);

            if ($status !== '') {
                $builder->where('cs.attendance_status', $status);
            }

            $callSlips = $builder->orderBy('cs.call_date', 'DESC')->orderBy('cs.call_time', 'DESC')->orderBy('cs.id', 'DESC')->limit(100)->get()->getResultArray();

            // Optional prefill when opened from an episode or case
            $prefillPerson = null;
            $prefillPersonId = (int) ($this->request->getGet('person_id') ?? 0);
            if ($prefillPersonId > 0) {
                $prefillPerson = $db->table('people')
                    ->select('id, mis_id, first_name, middle_name, last_name, kind')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $prefillPersonId)
                    ->where('deleted_at', null)
                    ->get()->getRowArray();
            }
            $prefillEpisodeId = (int) ($this->request->getGet('episode_id') ?? 0);

            return view('guidance/call_slips/index', [
                'title'            => 'Guidance Call Slips',
                'pageTitle'        => 'Guidance Call Slips',
                'callSlips'        => $callSlips,
                'status'           => $status,
                'validStatuses'    => GuidanceCallSlipService::VALID_STATUSES,
                'validPurposes'    => GuidanceCallSlipService::VALID_PURPOSES,
                'prefillPerson'    => $prefillPerson,
                'prefillEpisodeId' => $prefillEpisodeId > 0 ? $prefillEpisodeId : null,
                'actor'            => $actor,
                'breadcrumbs'      => [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'Call Slips', 'url' => ''],
                ],
            ]);
        } catch (Throwable $e) {
            [$code, $message] = $this->safeFailure($e, 'call_slips.index');
            return $this->response->setStatusCode($code)->setContentType('text/html')
                ->setBody('<div class="alert alert-danger m-3">' . esc($message) . '</div>');
        }
    }

    /**
     * Issue a new guidance call slip (POST).
     */
    public function issue()
    {
        return $this->mutate(function (array $actor): array {
            $tenantId = (int) $actor['tenant_id'];
            $personId = (int) $this->request->getPost('person_id');
            $episodeId = (int) ($this->request->getPost('episode_id') ?: 0);

            $slip = $this->callSlipService->issueCallSlip([
                'person_id'                => $personId,
                'episode_id'               => $episodeId > 0 ? $episodeId : null,
                'call_date'                => (string) $this->request->getPost('call_date'),
                'call_time'                => (string) $this->request->getPost('call_time'),
                'purpose'                  => (string) $this->request->getPost('purpose'),
                'purpose_other'            => $this->request->getPost('purpose_other'),
                'parent_guardian_delivery' => $this->request->getPost('parent_guardian_delivery') === '1',
                'delivery_notes'           => $this->request->getPost('delivery_notes'),
                'actor'                    => $actor,
            ], $tenantId);

            return ['/guidance/call-slips', 'Call slip ' . ($slip['call_slip_number'] ?? '') . ' issued.'];
        }, '/guidance/call-slips');
    }

    /**
     * Update call slip attendance status (POST).
     */
    public function updateStatus(int $id)
    {
        return $this->mutate(function (array $actor) use ($id): array {
            $tenantId = (int) $actor['tenant_id'];
            $status = trim((string) ($this->request->getPost('attendance_status') ?? $this->request->getPost('status') ?? ''));

            $this->callSlipService->recordCallSlipStatus($id, $status, $tenantId, $actor);

            return ['/guidance/call-slips', 'Call slip status updated to ' . ucfirst($status) . '.'];
        }, '/guidance/call-slips');
    }

    /**
     * Stream controlled Call Slip PDF (F-OGC-GSP-CSP-007-00) via bound form_instance_id.
     * Supports ?layout=single|quad&mode=preview|issue.
     */
    public function pdf(int $id)
    {
        try {
            $actor = $this->resolveStaffActor();
            $tenantId = (int) $actor['tenant_id'];
            $slip = $this->callSlipService->getCallSlip($id, $tenantId, $actor);

            $layout = $this->normalizedLayout();
            $mode = $this->normalizedMode();
            $formInstanceId = !empty($slip['form_instance_id']) ? (int) $slip['form_instance_id'] : null;

            $pdf = $this->renderControlledPdf(
                $formInstanceId,
                'guidance_call_slip',
                $layout,
                $mode,
                $actor,
                $tenantId,
                function () use ($slip, $layout): string {
                    $snapshot = [
                        'data' => [
                            'call_slip_number'       => $slip['call_slip_number'],
                            'student_name'           => $slip['student']['full_name'] ?? '',
                            'student_id'             => $slip['student']['mis_id'] ?? '',
                            'course_year'            => $slip['student']['course_year'] ?? 'N/A',
                            'call_date'              => $slip['call_date'],
                            'call_time'              => $slip['call_time'],
                            'purpose'                => $slip['purpose'],
                            'purpose_other'          => $slip['purpose_other'],
                            'issuing_counselor_name' => $slip['counselor']['full_name'] ?? '',
                        ],
                        'is_draft' => true,
                    ];
                    return $this->documentService->renderCallSlipPdf($snapshot, ['layout' => $layout])['pdf'];
                }
            );

            $filename = 'call-slip-' . ($slip['call_slip_number'] ?? $id) . '-' . $layout . '.pdf';
            return $this->streamPdf($pdf, $filename);
        } catch (Throwable $e) {
            [$code, $message] = $this->safeFailure($e, 'call_slips.pdf', $id);
            return $this->response->setStatusCode($code)
                ->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setContentType('text/html')
                ->setBody('<div class="alert alert-danger m-3">' . esc($message) . '</div>');
        }
    }

    /**
     * Stream controlled Appointment Form PDF (F-OGC-GAP-006-00) via bound form_instance_id.
     * Supports ?layout=single|quad&mode=preview|issue.
     */
    public function appointmentPdf(int $appointmentId)
    {
        try {
            $actor = $this->resolveStaffActor();
            $tenantId = (int) $actor['tenant_id'];
            $appointment = $this->appointmentService->getAppointment($appointmentId, $tenantId, $actor);

            $layout = $this->normalizedLayout();
            $mode = $this->normalizedMode();
            $formInstanceId = !empty($appointment['form_instance_id']) ? (int) $appointment['form_instance_id'] : null;

            $pdf = $this->renderControlledPdf(
                $formInstanceId,
                'guidance_appointment_form',
                $layout,
                $mode,
                $actor,
                $tenantId,
                function () use ($appointment, $layout): string {
                    $student = $appointment['student'] ?? [];
                    $prog = (string) ($student['program'] ?? '');
                    $yr = (string) ($student['year_level'] ?? '');
                    $courseYear = trim($prog . ($yr !== '' ? ' - ' . $yr : '')) ?: 'N/A';

                    $snapshot = [
                        'data' => [
                            'appointment_number' => $appointment['appointment_number'],
                            'student_name'       => $student['full_name'] ?? '',
                            'student_id'         => $student['mis_id'] ?? '',
                            'course_year'        => $courseYear,
                            'scheduled_date'     => $appointment['scheduled_date'],
                            'scheduled_time'     => $appointment['scheduled_time'],
                            'counselor_name'     => $appointment['counselor']['full_name'] ?? '',
                            'purpose'            => $appointment['purpose'],
                            'purpose_other'      => $appointment['purpose_other'],
                        ],
                        'is_draft' => true,
                    ];
                    return $this->documentService->renderAppointmentSlipPdf($snapshot, ['layout' => $layout])['pdf'];
                }
            );

            $filename = 'appointment-' . ($appointment['appointment_number'] ?? $appointmentId) . '-' . $layout . '.pdf';
            return $this->streamPdf($pdf, $filename);
        } catch (Throwable $e) {
            [$code, $message] = $this->safeFailure($e, 'appointments.pdf', $appointmentId);
            return $this->response->setStatusCode($code)
                ->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setContentType('text/html')
                ->setBody('<div class="alert alert-danger m-3">' . esc($message) . '</div>');
        }
    }

    /**
     * Student portal acknowledgement of a call slip (POST).
     */
    public function portalAcknowledgeCallSlip(int $id)
    {
        try {
            $user = (new AuthenticatedUser())->current();
            if ($user === null) {
                throw new RuntimeException(AuthenticatedUser::AUTHENTICATION_MESSAGE, 401);
            }

            $tenantId = (int) $user['tenant_id'];
            $this->callSlipService->recordCallSlipStatus($id, 'acknowledged', $tenantId, $user);

            $message = 'Call slip acknowledged.';
            if ($this->wantsJson()) {
                return $this->response->setStatusCode(200)->setHeader('Cache-Control', 'no-store')
                    ->setJSON([
                        'success'  => true,
                        'message'  => $message,
                        'redirect' => site_url('/portal'),
                        'csrf'     => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }

            return redirect()->to(site_url('/portal'))->with('success', $message);
        } catch (Throwable $e) {
            [$status, $message] = $this->safeFailure($e, 'portalAcknowledgeCallSlip', $id);
            if ($this->wantsJson()) {
                return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
                    ->setJSON([
                        'success' => false,
                        'message' => $message,
                        'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }
            return redirect()->to(site_url('/portal'))->with('error', $message);
        }
    }

    /**
     * Render controlled PDF via bound form_instance_id (preview or official issuance).
     */
    private function renderControlledPdf(
        ?int $formInstanceId,
        string $expectedKey,
        string $layout,
        string $mode,
        array $actor,
        int $tenantId,
        callable $fallbackRenderer
    ): string {
        if ($formInstanceId === null || $formInstanceId <= 0) {
            return $fallbackRenderer();
        }

        $db = Database::connect();
        $instance = $db->table('guidance_form_instances')
            ->where('id', $formInstanceId)
            ->where('tenant_id', $tenantId)
            ->get()->getRowArray();

        if ($instance === null) {
            return $fallbackRenderer();
        }

        $defBundle = $this->definitionService->getActiveDefinition((string) $instance['form_definition_id'], $tenantId);
        $definition = $defBundle['definition'];
        $revision = $defBundle['revision'];

        if ($mode === 'issue') {
            if (($instance['status'] ?? '') !== 'finalized') {
                $finalResult = $this->formService->finalizeInstance(
                    $formInstanceId,
                    $expectedKey . '-issue-' . $formInstanceId . '-' . bin2hex(random_bytes(4)),
                    $actor
                );
                if (($finalResult['status'] ?? '') !== 'finalized') {
                    throw new DomainException('This form requires supervisor co-signature before official PDF issuance.', 409);
                }
            }

            $bundle = $this->formService->getFinalizedRevision($formInstanceId, 1, $actor);
            $issued = $this->documentService->issueDocument(
                $bundle['instance'],
                $definition,
                $revision,
                $bundle['data'],
                $bundle['attestation'] ?? [],
                $actor,
                ['layout' => $layout]
            );
            return $issued['pdf'];
        }

        // Preview mode
        if (($instance['status'] ?? '') === 'finalized') {
            $bundle = $this->formService->getFinalizedRevision($formInstanceId, 1, $actor);
            $snapshot = [
                'instance'    => $bundle['instance'],
                'definition'  => $definition,
                'revision'    => $revision,
                'data'        => $bundle['data'],
                'attestation' => $bundle['attestation'],
                'is_draft'    => false,
            ];
            return $expectedKey === 'guidance_appointment_form'
                ? $this->documentService->renderAppointmentSlipPdf($snapshot, ['layout' => $layout])['pdf']
                : $this->documentService->renderCallSlipPdf($snapshot, ['layout' => $layout])['pdf'];
        }

        $draftBundle = $this->formService->getDraft($formInstanceId, $actor);
        $preview = $this->documentService->generateDraftPreview(
            $draftBundle['instance'],
            $definition,
            $revision,
            $draftBundle['data'],
            ['layout' => $layout]
        );
        return $preview['pdf'];
    }

    private function streamPdf(string $pdf, string $filename)
    {
        $disposition = $this->request->getGet('download') === '1' ? 'attachment' : 'inline';
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'document.pdf';

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', $disposition . '; filename="' . $safeName . '"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($pdf);
    }

    private function normalizedLayout(): string
    {
        $raw = strtolower(trim((string) ($this->request->getGet('layout') ?? 'single')));
        return in_array($raw, ['quad', '4up', '4-up'], true) ? 'quad' : 'single';
    }

    private function normalizedMode(): string
    {
        $raw = strtolower(trim((string) ($this->request->getGet('mode') ?? 'preview')));
        return $raw === 'issue' ? 'issue' : 'preview';
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
        $allowed = in_array('guidance.call_slips.issue', $perms, true)
            || in_array('guidance.appointments.manage', $perms, true)
            || in_array('guidance.cases.read', $perms, true)
            || in_array('guidance.cases.read_any', $perms, true)
            || in_array('guidance.documents.print', $perms, true)
            || ($actor['role'] ?? '') === 'superadmin';
        if (!$allowed) {
            throw new DomainException('Guidance permission forbidden.', 403);
        }

        return $actor;
    }

    private function mutate(callable $operation, string $fallback)
    {
        try {
            $actor = $this->resolveStaffActor();
            [$url, $message] = $operation($actor);
        } catch (Throwable $error) {
            [$status, $message] = $this->safeFailure($error, 'call_slips.mutation');
            if ($this->wantsJson()) {
                return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
                    ->setJSON([
                        'success' => false,
                        'message' => $message,
                        'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }
            return ($status === 422 ? redirect()->back() : redirect()->to($fallback))->with('error', $message);
        }

        $targetUrl = site_url($url);
        if ($this->wantsJson()) {
            if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                session()->setFlashdata('success', $message);
            }
            return $this->response->setStatusCode(200)->setHeader('Cache-Control', 'no-store')
                ->setJSON([
                    'success'  => true,
                    'message'  => $message,
                    'redirect' => $targetUrl,
                    'csrf'     => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
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
            log_message('error', 'Guidance call slip error: operation={operation} record_id={record_id} exception={exception_class} code={error_code}', [
                'operation'       => $operation,
                'record_id'       => $recordId,
                'exception_class' => get_class($error),
                'error_code'      => $error->getCode(),
            ]);
        }

        $message = match ($status) {
            401     => AuthenticatedUser::AUTHENTICATION_MESSAGE,
            403     => $error->getMessage() !== '' ? $error->getMessage() : 'You are not authorized to perform this action.',
            404     => 'The requested record was not found.',
            409     => $error->getMessage() !== '' ? $error->getMessage() : 'Action cannot be completed in the current state.',
            422     => $error->getMessage() !== '' ? $error->getMessage() : 'Please check the required fields and try again.',
            default => 'Unable to complete the request. Please try again later.',
        };

        return [$status, $message];
    }
}
