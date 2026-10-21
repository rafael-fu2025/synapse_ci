<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditEventModel;
use App\Services\Audit\AuditService;

final class AuditController extends BaseController
{
    private AuditService $auditService;
    private AuditEventModel $auditModel;

    public function __construct()
    {
        $this->auditService = new AuditService();
        $this->auditModel = new AuditEventModel();
    }

    /**
     * Audit log viewer and chain inspector.
     */
    public function index()
    {
        $events = $this->auditModel->scoped()
            ->select('audit_events.*, u.username as actor_name')
            ->join('users u', 'u.id = audit_events.actor_user_id', 'left')
            ->orderBy('audit_events.id', 'DESC')
            ->findAll(100);

        return view('admin/audit', [
            'title'       => 'Audit Trail',
            'pageTitle'   => 'Cryptographic Audit Ledger',
            'events'      => $events,
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
            $msg = "Cryptographic chain verified: All {$result['verified_count']} audit records intact without tampering.";
            return redirect()->to('/admin/audit')->with('success', $msg);
        }

        $msg = "INTEGRITY ALERT: Cryptographic chain broken at audit event ID #{$result['broken_id']}. Tampering detected.";
        return redirect()->to('/admin/audit')->with('error', $msg);
    }
}
