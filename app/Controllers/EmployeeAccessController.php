<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EmployeeAccessService;
use RuntimeException;
use Throwable;

final class EmployeeAccessController extends BaseController
{
    public function index()
    {
        return $this->respond(function () {
            $data = (new EmployeeAccessService())->listing($this->request->getGet())
                + ['title' => 'Employees & Access', 'baseUrl' => site_url('admin/access')];
            if ($this->request->getGet('fragment') !== '1') return view('admin/employee_access', $data);
            return $this->response->setBody('<div data-table-content>' . view('admin/_employee_access_results', $data) . '</div>');
        });
    }

    public function edit(int $id)
    {
        return $this->respond(fn () => view('admin/employee_access_form', (new EmployeeAccessService())->detail($id)
            + ['title' => 'Manage Employee Access', 'formModalPage' => true, 'baseUrl' => site_url('admin/access'), 'cancelUrl' => site_url('admin/access')]));
    }

    public function update(int $id)
    {
        return $this->respond(function () use ($id) {
            (new EmployeeAccessService())->assign($id, $this->request->getPost());
            $message = 'Employee access updated. Existing sessions have been revoked; the employee must sign in again.';
            session()->setFlashdata('success', $message);
            if ($this->json()) return $this->response->setJSON(['success' => true, 'message' => $message,
                'redirect' => site_url('admin/access'), 'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
            return redirect()->to(site_url('admin/access'))->setHeader('Cache-Control', 'private, no-store');
        });
    }

    private function json(): bool
    {
        return $this->request->isAJAX() || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'json');
    }

    private function respond(callable $operation)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try { return $operation(); }
        catch (Throwable $e) {
            $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 409, 422], true) ? $e->getCode() : 503;
            if ($status === 503) log_message('error', 'Employee access operation failed: {exception}', ['exception' => $e]);
            $message = $status === 503 ? 'Employee access is temporarily unavailable. No access change was completed.' : $e->getMessage();
            $this->response->setStatusCode($status);
            if ($this->json()) return $this->response->setJSON(['success' => false, 'message' => $message,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
            return $this->response->setContentType('text/plain')->setBody($message);
        }
    }
}
