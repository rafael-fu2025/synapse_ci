<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PersonModel;
use App\Models\ReferralModel;
use App\Services\AuthenticatedUser;
use App\Services\PortalContext;
use App\Services\Referrals\ReferralService;
use RuntimeException;
use Throwable;

final class ReferralController extends BaseController
{
    private ReferralService $referralService;
    private ReferralModel $referralModel;

    public function __construct()
    {
        $this->referralService = new ReferralService();
        $this->referralModel = new ReferralModel();
    }

    private function actor(string $permission): array
    {
        $this->response->setHeader('Cache-Control', 'no-store, private');
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) { throw new RuntimeException('Referral access forbidden.', 403); }
        return $this->referralService->authorize($actor, $permission);
    }

    private function context(array $actor, ?string $unit): string
    {
        if ($unit === null && in_array(PortalContext::current(), ['clinic', 'guidance'], true)) {
            $unit = PortalContext::current();
        }
        return $this->referralService->context($actor, $unit);
    }

    /** Old bookmarks remain safe, but ordinary workflow uses explicit workstation routes. */
    private function legacy(array $actor, string $suffix = '')
    {
        $requested = $this->request->getGet('target');
        if ($requested !== null && !is_string($requested)) { throw new RuntimeException('Invalid referral workstation.', 422); }
        if ($requested === null && ReferralService::ownModule($actor) === null && PortalContext::current() === 'main') {
            return view('referrals/workstation', ['title' => 'Choose Referral Workstation', 'suffix' => $suffix]);
        }
        $unit = $this->context($actor, $requested);
        return redirect()->to(site_url('/' . $unit . '/referrals' . $suffix))->setHeader('Cache-Control', 'private, no-store');
    }

    public function index(?string $unit = null)
    {
        try {
            $actor = $this->actor('referrals.read');
            if ($unit === null) { return $this->legacy($actor); }
            $unit = $this->context($actor, $unit);
            $tab = $this->request->getGet('tab') ?? 'incoming';
            if (!is_string($tab)) { throw new RuntimeException('Invalid referral tab.', 422); }
            $data = $this->referralService->listing($unit, $tab, $this->request->getGet(), $actor);
            $data += ['unit' => $unit, 'tab' => $tab, 'actor' => $actor,
                'baseUrl' => site_url('/' . $unit . '/referrals'),
                'title' => ucfirst($unit) . ' Referrals', 'pageTitle' => ucfirst($unit) . ' Referrals',
                'formModalPage' => false, 'breadcrumbs' => [
                    ['label' => ucfirst($unit), 'url' => site_url('/' . $unit . '/queue')],
                    ['label' => 'Referrals', 'url' => ''],
                ]];
            if ($this->request->getGet('fragment') === '1') {
                return $this->response->setContentType('text/html')->setBody(view('referrals/_results', $data));
            }
            return view('referrals/index', $data);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function create(?string $unit = null)
    {
        try {
            $actor = $this->actor('referrals.create');
            if ($unit === null) { return $this->legacy($actor, '/create'); }
            $unit = $this->context($actor, $unit);
            $target = $unit === 'clinic' ? 'guidance' : 'clinic';
            $patient = null;
            $encounterId = $this->request->getGet('source_encounter_id');
            $personId = $this->request->getGet('person_id');
            if ($encounterId !== null) {
                $this->referralService->authorize($actor, 'clinic.encounters.read', 'clinic');
                $encounterId = ReferralService::positiveId($encounterId);
                $encounter = \Config\Database::connect()->table('clinic_encounters')->where('tenant_id', $actor['tenant_id'])
                    ->where('id', $encounterId)->where('deleted_at', null)->get()->getRowArray();
                if (!$encounter) { throw new RuntimeException('Source visit not found.', 404); }
                if ($personId !== null && ReferralService::positiveId($personId) !== (int) $encounter['person_id']) {
                    throw new RuntimeException('Source visit does not belong to this person.', 422);
                }
                $personId = $encounter['person_id'];
            }
            if ($personId !== null) {
                $patient = (new PersonModel())->allowCallbacks(false)->scoped()->select('id,first_name,last_name,mis_id,kind')->find(ReferralService::positiveId($personId));
                if (!$patient) { throw new RuntimeException('Person not found.', 404); }
            }
            return view('referrals/create', [
                'title' => 'Refer to ' . ucfirst($target), 'pageTitle' => 'Refer to ' . ucfirst($target),
                'unit' => $unit, 'targetModule' => $target, 'reasonOptions' => $this->referralService->reasonOptions($target),
                'requestKey' => bin2hex(random_bytes(32)), 'patient' => $patient, 'sourceEncounterId' => $encounterId,
                'formAction' => site_url('/' . $unit . '/referrals/store'),
                'cancelUrl' => site_url('/' . $unit . '/referrals?tab=sent'), 'formModalPage' => true,
            ]);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function store(?string $unit = null)
    {
        try {
            $actor = $this->actor('referrals.create');
            if ($unit === null && $actor['role'] === 'superadmin') {
                $unit = $this->text('workstation');
            }
            $unit = $this->context($actor, $unit);
            $key = $this->text('request_key');
            if (!preg_match('/^[a-fA-F0-9]{64}$/D', $key)) { throw new RuntimeException('Reload the referral form before submitting.', 422); }
            $data = ['workstation' => $unit, 'request_key' => $key,
                'patient_person_id' => $this->request->getPost('patient_person_id'),
                'source_encounter_id' => $this->request->getPost('source_encounter_id'), 'issuer_user_id' => $actor['id'],
                'priority' => $this->text('priority'), 'reason_code' => $this->text('reason_code'),
                'reason_description' => $this->text('reason_description')];
            foreach (['source_module', 'target_module'] as $field) {
                if ($this->request->getPost($field) !== null) { $data[$field] = $this->request->getPost($field); }
            }
            $referral = $this->referralService->createReferral($data, $actor);
            return $this->success('Referral ' . $referral['referral_code'] . ' sent successfully.', '/' . $unit . '/referrals?tab=sent');
        } catch (Throwable $e) { return $this->failure($e); }
    }

    private function actionContext(int $id, array $actor, ?string $unit): string
    {
        $unit = $this->context($actor, $unit);
        $ref = $this->referralModel->scoped()->find($id);
        if (!$ref) { throw new RuntimeException('Referral not found.', 404); }
        if ($ref['target_module'] !== $unit) { throw new RuntimeException('Only the receiving workstation can perform this action.', 403); }
        return $unit;
    }

    public function acknowledge(int $id, ?string $unit = null)
    {
        return $this->transition($id, $unit, 'acknowledge', 'referrals.acknowledge', 'Referral acknowledged.');
    }

    public function review(int $id, ?string $unit = null)
    {
        return $this->transition($id, $unit, 'review', 'referrals.review', 'Referral is under review.');
    }

    public function close(int $id, ?string $unit = null)
    {
        return $this->transition($id, $unit, 'close', 'referrals.close', 'Referral closed.');
    }

    private function transition(int $id, ?string $unit, string $action, string $permission, string $message)
    {
        try {
            $actor = $this->actor($permission);
            $unit = $this->actionContext($id, $actor, $unit);
            if ($action === 'close') { $this->referralService->close($id, $actor); }
            else { $this->referralService->$action($id, (int) $actor['id'], $actor); }
            return $this->success($message, '/' . $unit . '/referrals?tab=' . ($action === 'close' ? 'history' : 'incoming'));
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function handoff(int $id, ?string $unit = null)
    {
        try {
            $actor = $this->actor('referrals.review');
            $unit = $this->actionContext($id, $actor, $unit);
            $data = $this->referralService->handoffOptions($id, $actor);
            return view('referrals/handoff', $data + [
                'unit' => $unit, 'title' => $unit === 'clinic' ? 'Start or Link Clinic Visit' : 'Open or Link Guidance Case',
                'formAction' => site_url('/' . $unit . '/referrals/' . $id . '/handoff'),
                'cancelUrl' => site_url('/' . $unit . '/referrals?tab=incoming'), 'formModalPage' => true,
            ]);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function handoffStore(int $id, ?string $unit = null)
    {
        try {
            $actor = $this->actor('referrals.review');
            $this->actionContext($id, $actor, $unit);
            $result = $this->referralService->handoff($id, [
                'mode' => $this->text('mode'), 'target_id' => $this->request->getPost('target_id'),
                'title' => $this->text('title'), 'category' => $this->text('category'),
            ], $actor);
            return $this->success($result['created'] ? 'Referral handoff created.' : 'Referral linked to the existing record.', $result['target_url']);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    /** Compatibility for old POST-only conversion callers; no default title/category. */
    public function convertToCase(int $id, ?string $unit = null)
    {
        try {
            $actor = $this->actor('referrals.review');
            $this->actionContext($id, $actor, $unit);
            $result = $this->referralService->convertToCase($id, (int) $actor['id'], $this->text('title'), $this->text('category'), $actor);
            return $this->success('Guidance case linked.', '/guidance/cases/' . $result['case']['case']['id']);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    private function text(string $field): string
    {
        $value = $this->request->getPost($field);
        if ($value !== null && !is_string($value)) { throw new RuntimeException('Invalid ' . $field . '.', 422); }
        return $value ?? '';
    }

    private function failure(Throwable $e)
    {
        $expected = $e instanceof RuntimeException || $e instanceof \InvalidArgumentException;
        $status = $expected && in_array($e->getCode(), [401, 403, 404, 422, 409], true) ? $e->getCode() : 503;
        if ($status === 503) {
            // SQL messages, traces and request data can contain private clinical content.
            log_message('error', 'Referral operation failed: {type} (code {code}) at {file}:{line}', [
                'type' => get_class($e), 'code' => $e->getCode(), 'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);
        }
        $message = $status === 503 ? 'Referral operation could not be completed. Please retry or contact support.' : $e->getMessage();
        $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store, private');
        if ($this->request->isAJAX() || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'json')) {
            return $this->response->setJSON(['success' => false, 'message' => $message, 'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
        }
        return $this->response->setContentType('text/plain')->setBody($message);
    }

    private function success(string $message, string $redirect)
    {
        if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
            session()->setFlashdata('success', $message);
        }
        if ($this->request->isAJAX() || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'json')) {
            return $this->response->setJSON(['success' => true, 'message' => $message, 'redirect' => site_url($redirect),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
        }
        return redirect()->to(site_url($redirect))->setHeader('Cache-Control', 'no-store, private');
    }
}
