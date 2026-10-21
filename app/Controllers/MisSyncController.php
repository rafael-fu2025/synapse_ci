<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Filters\WebCsrfFilter;
use App\Services\Directory\MisSyncService;
use RuntimeException;
use Throwable;

final class MisSyncController extends BaseController
{
    public function index()
    {
        if (strtoupper($this->request->getMethod()) !== 'GET') { return $this->methodNotAllowed('GET'); }
        return $this->respond(fn () => view('admin/mis_sync', (new MisSyncService())->status()
            + ['title' => 'MIS reference synchronization']));
    }

    public function sync()
    {
        if (strtoupper($this->request->getMethod()) !== 'POST') { return $this->methodNotAllowed('POST'); }
        // Reuses the request-scoped verification marker when the parent web_csrf filter already ran.
        if (($denied = (new WebCsrfFilter())->before($this->request)) !== null) { return $denied; }
        return $this->respond(function () {
            $result = (new MisSyncService())->sync();
            if ($this->json()) {
                return $this->response->setJSON(['success' => true, 'message' => 'MIS reference catalogs synchronized.',
                    'result' => $result, 'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
            }
            session()->setFlashdata('success', 'MIS reference catalogs synchronized. No directory or account records were changed.');
            return redirect()->to(site_url('admin/mis-sync'))->setHeader('Cache-Control', 'private, no-store');
        });
    }

    private function methodNotAllowed(string $method)
    {
        return $this->response->setStatusCode(405)->setHeader('Allow', $method)
            ->setHeader('Cache-Control', 'private, no-store')->setBody('Method not allowed.');
    }

    private function json(): bool
    {
        return $this->request->isAJAX() || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'json');
    }

    private function respond(callable $operation)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try { return $operation(); }
        catch (Throwable $error) {
            $status = $error instanceof RuntimeException && in_array($error->getCode(), [401, 403, 503], true)
                ? $error->getCode() : 503;
            $message = match ($status) {
                401 => 'Please sign in to continue.',
                403 => 'MIS reference synchronization is not permitted. Reload or sign in again.',
                default => 'MIS reference synchronization is unavailable or incomplete. No synchronization was committed.',
            };
            // Never log the exception: upstream errors can contain API keys or raw payloads.
            $this->response->setStatusCode($status);
            if ($this->json()) {
                return $this->response->setJSON(['success' => false, 'message' => $message,
                    'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
            }
            return $this->response->setContentType('text/plain')->setBody($message);
        }
    }
}
