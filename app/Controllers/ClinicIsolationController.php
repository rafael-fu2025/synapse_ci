<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\Clinic\IsolationTrackingService;
use Throwable;

final class ClinicIsolationController extends BaseController
{
    private function authorize(bool $write = false): array
    {
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) {
            throw new \RuntimeException('Sign in required.', 401);
        }
        $role = $actor['role'] ?? '';
        $perms = $actor['permissions'] ?? [];
        if ($role === 'superadmin') {
            return $actor;
        }
        if (!in_array($role, ['clinic_admin', 'clinic_physician', 'clinic_staff', 'clinic_intern'], true)) {
            throw new \RuntimeException('Clinic workstation access required.', 403);
        }
        $needed = $write ? 'clinic.isolation.write' : 'clinic.isolation.read';
        if (!in_array($needed, $perms, true)) {
            throw new \RuntimeException('Missing permission: ' . $needed, 403);
        }
        return $actor;
    }

    public function index()
    {
        try {
            $actor = $this->authorize(false);
            $canWrite = ($actor['role'] ?? '') === 'superadmin'
                || in_array('clinic.isolation.write', $actor['permissions'] ?? [], true);
            $service = new IsolationTrackingService();
            $data = $service->listing($this->request->getGet());
            return view('clinic/isolation/index', $data + [
                'title'      => 'Communicable Disease & Isolation Calendar',
                'pageTitle'  => 'Isolation & Outbreak Monitoring',
                'canWrite'   => $canWrite,
                'conditions' => IsolationTrackingService::CONDITIONS,
                'defaultDays'=> IsolationTrackingService::DEFAULT_ISOLATION_DAYS,
                'breadcrumbs' => [
                    ['label' => 'Clinic', 'url' => site_url('/clinic')],
                    ['label' => 'Isolation & Outbreaks', 'url' => ''],
                ],
            ]);
        } catch (Throwable $e) {
            $status = in_array($e->getCode(), [401, 403], true) ? $e->getCode() : 500;
            return $this->response->setStatusCode($status)->setBody(esc($status === 500 ? 'Unable to load isolation calendar.' : $e->getMessage()));
        }
    }

    public function store()
    {
        try {
            $actor = $this->authorize(true);
            $service = new IsolationTrackingService();
            $service->logCase($this->request->getPost(), (int) $actor['id']);
            return redirect()->to('/clinic/isolation')->with('success', 'Communicable isolation case logged.');
        } catch (Throwable $e) {
            $message = in_array($e->getCode(), [403, 404, 422], true) || $e instanceof \InvalidArgumentException
                ? $e->getMessage() : 'Could not log isolation case.';
            return redirect()->to('/clinic/isolation')->with('error', $message);
        }
    }

    public function clear(int $id)
    {
        try {
            $actor = $this->authorize(true);
            $status = (string) ($this->request->getPost('clearance_status') ?? 'cleared');
            $notes = $this->request->getPost('notes');
            (new IsolationTrackingService())->clearCase($id, (int) $actor['id'], $status, is_string($notes) ? $notes : null);
            return redirect()->back()->with('success', 'Isolation case status updated.');
        } catch (Throwable $e) {
            $message = in_array($e->getCode(), [403, 404, 422], true) || $e instanceof \InvalidArgumentException
                ? $e->getMessage() : 'Could not update isolation case.';
            return redirect()->back()->with('error', $message);
        }
    }
}

