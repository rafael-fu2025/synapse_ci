<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Audit\AuditExplorer;
use App\Services\Audit\AuditExplorerQuery;
use App\Services\Audit\AuditService;

final class AuditController extends BaseController
{
    private AuditService $auditService;

    public function __construct()
    {
        $this->auditService = new AuditService();
    }

    /**
     * Audit log viewer and chain inspector.
     */
    public function index()
    {
        $query = AuditExplorerQuery::fromQuery($this->request->getGet());
        $page = ['events' => [], 'older_cursor' => null, 'newer_cursor' => null];
        if ($query->errors === []) {
            $page = (new AuditExplorer())->page($query);
        } else {
            $this->response->setStatusCode(400);
        }
        $url = static fn (?string $cursor = null, string $direction = 'older'): string =>
            site_url('/admin/audit') . '?' . http_build_query($query->parameters($cursor, $direction));

        return view('admin/audit', [
            'title'       => 'Audit Trail',
            'pageTitle'   => 'Cryptographic Audit Ledger',
            'events'      => $page['events'],
            'filters'     => $query->filters,
            'filterErrors' => $query->errors,
            'hasCursor'   => $query->cursor !== null,
            'pagination'  => [
                'first_url' => $url(),
                'older_url' => $page['older_cursor'] === null ? null : $url($page['older_cursor']),
                'newer_url' => $page['newer_cursor'] === null ? null : $url($page['newer_cursor'], 'newer'),
            ],
            'breadcrumbs' => [
                ['label' => 'Governance', 'url' => site_url('/admin/audit')],
                ['label' => 'Audit Ledger', 'url' => ''],
            ],
        ]);
    }

    /**
     * Verifies the cryptographic SHA-256 hash chain from genesis to head.
     */
    public function verify()
    {
        $result = $this->auditService->verifyChain();

        if ($result['valid']) {
            $msg = "Hash-chain verification passed for {$result['verified_count']} tenant audit records. No external checkpoint was checked.";
        } else {
            $location = $result['broken_id'] === null ? 'an invalid event ID' : 'audit event ID #' . $result['broken_id'];
            $msg = "INTEGRITY ALERT: Hash-chain verification failed at {$location}. Investigate possible corruption or tampering.";
        }

        if ($this->request->isAJAX() || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'json')) {
            if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                session()->setFlashdata($result['valid'] ? 'success' : 'error', $msg);
            }
            return $this->response->setStatusCode($result['valid'] ? 200 : 422)
                ->setHeader('Cache-Control', 'private, no-store')->setJSON([
                    'success' => $result['valid'], 'message' => $msg,
                    'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
        }

        $redirect = redirect()->to('/admin/audit');
        return $this->request->getHeaderLine('X-Synapse-Feedback') === 'toast'
            ? $redirect : $redirect->with($result['valid'] ? 'success' : 'error', $msg);
    }
}
