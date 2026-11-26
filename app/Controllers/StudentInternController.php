<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\StudentInternService;
use RuntimeException;
use Throwable;

/** Unit-scoped administration of time-bounded student intern placements and supervision. */
final class StudentInternController extends BaseController
{
    private const REFRESH_OWNER = 'admin.interns';

    public function index()
    {
        return $this->respond(function () {
            $data = (new StudentInternService())->listing($this->request->getGet())
                + ['title' => 'Student Interns & Supervision', 'baseUrl' => site_url('admin/interns')];
            if ($this->request->getGet('fragment') !== '1') {
                return view('admin/interns/index', $data);
            }
            return $this->response->setBody('<div data-table-content>' . view('admin/interns/_results', $data) . '</div>');
        });
    }

    public function create()
    {
        return $this->respond(function () {
            $data = (new StudentInternService())->createContext()
                + [
                    'title'         => 'Assign Student Intern',
                    'formModalPage' => true,
                    'baseUrl'       => site_url('admin/interns'),
                    'cancelUrl'     => site_url('admin/interns'),
                ];
            return view('admin/interns/create', $data);
        });
    }

    public function searchStudents()
    {
        return $this->respond(function () {
            $q = (string) ($this->request->getGet('q') ?? '');
            $students = (new StudentInternService())->searchEligibleStudents($q);
            return $this->response->setJSON([
                'success'  => true,
                'students' => $students,
            ]);
        });
    }

    public function store()
    {
        return $this->respond(function () {
            $placement = (new StudentInternService())->createPlacement($this->request->getPost());
            $status = (string) ($placement['status'] ?? 'active');
            $message = $status === 'active'
                ? 'Student intern placement activated. Any prior student session has been rotated.'
                : 'Student intern placement scheduled. Access will activate automatically at the start time.';

            return $this->mutationResponse($message, site_url('admin/interns'));
        });
    }

    public function edit(int $id)
    {
        return $this->respond(function () use ($id) {
            $data = (new StudentInternService())->detail($id)
                + [
                    'title'         => 'Manage Student Intern Placement',
                    'formModalPage' => true,
                    'baseUrl'       => site_url('admin/interns'),
                    'cancelUrl'     => site_url('admin/interns'),
                ];
            return view('admin/interns/edit', $data);
        });
    }

    public function update(int $id)
    {
        return $this->respond(function () use ($id) {
            (new StudentInternService())->updatePlacement($id, $this->request->getPost());
            return $this->mutationResponse('Student intern placement updated.', site_url('admin/interns'));
        });
    }

    public function revoke(int $id)
    {
        return $this->respond(function () use ($id) {
            (new StudentInternService())->revokePlacement($id, $this->request->getPost());
            return $this->mutationResponse(
                'Student intern placement revoked. Account returned to student access and active sessions terminated.',
                site_url('admin/interns')
            );
        });
    }

    private function mutationResponse(string $message, string $redirectUrl)
    {
        if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
            session()->setFlashdata('success', $message);
        }
        if ($this->json()) {
            $payload = [
                'success'  => true,
                'message'  => $message,
                'redirect' => $redirectUrl,
                'csrf'     => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ];
            $refresh = $this->internsRefresh($redirectUrl);
            if ($refresh !== null) {
                $payload['refresh'] = $refresh;
            }
            return $this->response->setJSON($payload);
        }
        return redirect()->to($redirectUrl)->setHeader('Cache-Control', 'private, no-store');
    }

    private function internsRefresh(string $redirectUrl): ?array
    {
        $owner = self::REFRESH_OWNER;
        if (!config(\Config\RefreshRegions::class)->enables($owner)) {
            return null;
        }
        return $this->request->getHeaderLine('X-Synapse-Refresh-Owner') === $owner
            ? $this->refreshRegions($owner, ['results'])
            : $this->refreshNavigation($redirectUrl, 'replace');
    }

    private function json(): bool
    {
        return $this->request->isAJAX() || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'json');
    }

    private function respond(callable $operation)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            return $operation();
        } catch (Throwable $e) {
            $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 409, 422], true)
                ? $e->getCode()
                : 503;
            if ($status === 503) {
                log_message('error', 'Student intern operation failed: {exception}', ['exception' => $e]);
            }
            $message = $status === 503
                ? 'Student intern management is temporarily unavailable. No placement changes were saved.'
                : $e->getMessage();
            $this->response->setStatusCode($status);
            if ($this->json()) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => $message,
                    'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
            }
            if (\App\Services\ErrorPageRenderer::wantsHtml($this->request)) {
                return \App\Services\ErrorPageRenderer::htmlResponse($this->response, $status, $message);
            }
            return $this->response->setContentType('text/plain')->setBody($message);
        }
    }
}
