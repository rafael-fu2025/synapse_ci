<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\Clinic\IsolationTrackingService;
use Throwable;

final class ClinicIsolationController extends BaseController
{
    private function authorize(bool $write = false, ?string $permission = null): array
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
        $needed = $permission ?? ($write ? 'clinic.isolation.write' : 'clinic.isolation.read');
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
            $canArchive = ($actor['role'] ?? '') === 'superadmin'
                || in_array('archive.manage', $actor['permissions'] ?? [], true);
            $service = new IsolationTrackingService();
            $data = $service->listing($this->request->getGet());
            $viewData = $data + [
                'title'      => 'Communicable Disease & Isolation Calendar',
                'pageTitle'  => 'Isolation & Outbreak Monitoring',
                'canWrite'   => $canWrite,
                'canArchive' => $canArchive,
                'conditions' => IsolationTrackingService::CONDITIONS,
                'defaultDays'=> IsolationTrackingService::DEFAULT_ISOLATION_DAYS,
                'breadcrumbs' => [
                    ['label' => 'Clinic', 'url' => site_url('/clinic')],
                    ['label' => 'Isolation & Outbreaks', 'url' => ''],
                ],
            ];
            if ($this->request->getGet('fragment') === '1') {
                return $this->response->setContentType('text/html')->setBody(view('clinic/isolation/_body', $viewData));
            }
            return view('clinic/isolation/index', $viewData);
        } catch (Throwable $e) {
            $status = in_array($e->getCode(), [401, 403], true) ? $e->getCode() : 500;
            $message = $status === 500 ? 'Unable to load isolation calendar.' : $e->getMessage();
            if (\App\Services\ErrorPageRenderer::wantsHtml($this->request)) {
                return \App\Services\ErrorPageRenderer::htmlResponse($this->response, $status, $message);
            }
            return $this->response->setStatusCode($status)->setBody(esc($message));
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

    private function wantsJson(): bool
    {
        return $this->request->isAJAX()
            || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'application/json');
    }

    /**
     * Reversibly archive a cleared isolation case.
     */
    public function archive(int $id)
    {
        $reason = trim((string) ($this->request->getPost('reason') ?? ''));
        if ($reason === '') {
            $reason = 'Archived from isolation register';
        }
        try {
            $this->authorize(true, 'archive.manage');
            (new \App\Services\Archive\ArchiveService())->archive('clinic_isolation', $id, $reason);
            $message = 'Isolation case archived reversibly.';
            if ($this->wantsJson()) {
                if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                    session()->setFlashdata('success', $message);
                }
                return $this->response->setStatusCode(200)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->setJSON([
                        'success' => true,
                        'message' => $message,
                        'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }
            return redirect()->back()->with('success', $message);
        } catch (Throwable $e) {
            $status = in_array($e->getCode(), [401, 403, 404, 409, 422], true)
                ? (int) $e->getCode()
                : ($e instanceof \InvalidArgumentException ? 422 : 500);
            $message = $status < 500 ? $e->getMessage() : 'Could not archive isolation case.';
            if ($this->wantsJson()) {
                return $this->response->setStatusCode($status)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->setJSON([
                        'success' => false,
                        'message' => $message,
                        'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }
            return redirect()->back()->with('error', $message);
        }
    }

    /**
     * Restore an archived isolation case.
     */
    public function restore(int $id)
    {
        $reason = trim((string) ($this->request->getPost('reason') ?? ''));
        if ($reason === '') {
            $reason = 'Restored from isolation archive';
        }
        try {
            $this->authorize(true, 'archive.manage');
            (new \App\Services\Archive\ArchiveService())->restore('clinic_isolation', $id, $reason);
            $message = 'Isolation case restored from archive.';
            if ($this->wantsJson()) {
                if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                    session()->setFlashdata('success', $message);
                }
                return $this->response->setStatusCode(200)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->setJSON([
                        'success' => true,
                        'message' => $message,
                        'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }
            return redirect()->back()->with('success', $message);
        } catch (Throwable $e) {
            $status = in_array($e->getCode(), [401, 403, 404, 409, 422], true)
                ? (int) $e->getCode()
                : ($e instanceof \InvalidArgumentException ? 422 : 500);
            $message = $status < 500 ? $e->getMessage() : 'Could not restore isolation case.';
            if ($this->wantsJson()) {
                return $this->response->setStatusCode($status)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->setJSON([
                        'success' => false,
                        'message' => $message,
                        'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }
            return redirect()->back()->with('error', $message);
        }
    }
}

