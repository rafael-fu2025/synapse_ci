<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Archive\ArchiveAccessPolicy;
use App\Services\Archive\ArchiveEntityRegistry;
use App\Services\Archive\ArchiveService;
use App\Services\Archive\LegalHoldService;
use App\Services\Archive\RetentionPolicyService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ArchiveController extends BaseController
{
    private ArchiveService $archiveService;
    private LegalHoldService $legalHoldService;
    private RetentionPolicyService $retentionService;
    private ArchiveAccessPolicy $accessPolicy;

    public function __construct(
        ?ArchiveService $archiveService = null,
        ?LegalHoldService $legalHoldService = null,
        ?RetentionPolicyService $retentionService = null,
        ?ArchiveAccessPolicy $accessPolicy = null
    ) {
        $this->accessPolicy     = $accessPolicy ?? new ArchiveAccessPolicy();
        $this->retentionService = $retentionService ?? new RetentionPolicyService(policy: $this->accessPolicy);
        $this->legalHoldService = $legalHoldService ?? new LegalHoldService(policy: $this->accessPolicy);
        $this->archiveService   = $archiveService ?? new ArchiveService(
            policy: $this->accessPolicy,
            retention: $this->retentionService,
            holds: $this->legalHoldService
        );
    }

    public function index()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');

        try {
            $query = $this->request->getGet();
            $tab = (string) ($query['tab'] ?? 'records');
            if (!in_array($tab, ['records', 'holds', 'policies', 'eligible'], true)) {
                $tab = 'records';
            }

            $listing = $this->archiveService->listing($query);
            $actor = $listing['actor'];

            $holds = $tab === 'holds'
                ? $this->legalHoldService->listHolds($actor, $query)
                : ['items' => [], 'pagination' => null, 'filters' => ['status' => 'active', 'module' => '', 'q' => '']];
            $policies = $tab === 'policies'
                ? $this->retentionService->listPolicies($actor)
                : [];
            $eligible = $tab === 'eligible'
                ? $this->archiveService->eligibleListing($query)
                : ['items' => [], 'entityType' => (string) array_key_first($listing['allowedTypes']), 'allowedTypes' => $listing['allowedTypes'], 'q' => ''];

            return view('admin/archive/index', [
                'title'            => 'Archive Management',
                'pageTitle'        => 'System Archive & Retention Center',
                'tab'              => $tab,
                'actor'            => $actor,
                'scope'            => $listing['scope'],
                'records'          => $listing['items'],
                'pagination'       => $listing['pagination'],
                'filters'          => $listing['filters'],
                'kpis'             => $listing['kpis'],
                'allowedTypes'     => $listing['allowedTypes'],
                'modules'          => ArchiveEntityRegistry::MODULES,
                'holds'            => $holds['items'],
                'holdsPagination'  => $holds['pagination'],
                'holdsFilters'     => $holds['filters'],
                'policies'         => $policies,
                'eligible'         => $eligible['items'],
                'eligibleType'     => $eligible['entityType'],
                'eligibleQuery'    => $eligible['q'],
                'immutableLedgers' => ArchiveEntityRegistry::IMMUTABLE_ENTITIES,
                'breadcrumbs'      => [
                    ['label' => 'Governance', 'url' => site_url('/admin/archive')],
                    ['label' => 'Archive Management', 'url' => ''],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->handleReadError($e);
        }
    }

    public function archive(): ResponseInterface|RedirectResponse
    {
        return $this->handleMutation(function (): array {
            $entityType = trim((string) $this->request->getPost('entity_type'));
            $entityId = (int) $this->request->getPost('entity_id');
            $reason = (string) $this->request->getPost('reason');
            $archived = $this->archiveService->archive($entityType, $entityId, $reason);

            return [
                'message' => 'Record archived reversibly (' . ($archived['record_ref'] ?? ($entityType . ' #' . $entityId)) . ').',
                'default' => '/admin/archive?tab=records',
                'data'    => ['record' => $archived],
            ];
        });
    }

    public function restore(): ResponseInterface|RedirectResponse
    {
        return $this->handleMutation(function (): array {
            $entityType = trim((string) $this->request->getPost('entity_type'));
            $entityId = (int) $this->request->getPost('entity_id');
            $reason = (string) $this->request->getPost('reason');
            $restored = $this->archiveService->restore($entityType, $entityId, $reason);

            return [
                'message' => 'Record restored to active state (' . ($restored['record_ref'] ?? ($entityType . ' #' . $entityId)) . ').',
                'default' => '/admin/archive?tab=records',
                'data'    => ['record' => $restored],
            ];
        });
    }

    public function placeHold(): ResponseInterface|RedirectResponse
    {
        return $this->handleMutation(function (): array {
            $entityType = trim((string) $this->request->getPost('entity_type'));
            $entityId = (int) $this->request->getPost('entity_id');
            $reason = (string) $this->request->getPost('reason');
            $refCode = $this->request->getPost('reference_code');
            $hold = $this->legalHoldService->placeHold(
                $entityType,
                $entityId,
                $reason,
                is_string($refCode) ? $refCode : null
            );

            return [
                'message' => 'Legal hold placed on ' . ($hold['record_ref'] ?? ($entityType . ' #' . $entityId)) . '.',
                'default' => '/admin/archive?tab=holds',
                'data'    => ['hold' => $hold],
            ];
        });
    }

    public function liftHold(int $id): ResponseInterface|RedirectResponse
    {
        return $this->handleMutation(function () use ($id): array {
            $reason = (string) $this->request->getPost('lift_reason');
            $hold = $this->legalHoldService->liftHold($id, $reason);

            return [
                'message' => 'Legal hold lifted for ' . ($hold['record_ref'] ?? ('#' . $id)) . '.',
                'default' => '/admin/archive?tab=holds',
                'data'    => ['hold' => $hold],
            ];
        });
    }

    public function savePolicy(): ResponseInterface|RedirectResponse
    {
        return $this->handleMutation(function (): array {
            $rawId = trim((string) ($this->request->getPost('policy_id') ?? ''));
            $id = ($rawId !== '' && ctype_digit($rawId) && (int) $rawId > 0) ? (int) $rawId : null;
            $policy = $this->retentionService->savePolicy($id, [
                'policy_code'               => $this->request->getPost('policy_code'),
                'module'                    => $this->request->getPost('module'),
                'entity_type'               => $this->request->getPost('entity_type'),
                'title'                     => $this->request->getPost('title'),
                'duration_months'           => $this->request->getPost('duration_months'),
                'legal_basis'               => $this->request->getPost('legal_basis'),
                'auto_archive_after_months' => $this->request->getPost('auto_archive_after_months'),
                'is_active'                 => $this->request->getPost('is_active') ?? '1',
            ]);

            return [
                'message' => 'Retention policy ' . ($policy['policy_code'] ?? '') . ' saved.',
                'default' => '/admin/archive?tab=policies',
                'data'    => ['policy' => $policy],
            ];
        });
    }

    public function togglePolicy(int $id): ResponseInterface|RedirectResponse
    {
        return $this->handleMutation(function () use ($id): array {
            $policy = $this->retentionService->togglePolicy($id);
            $label = ((int) ($policy['is_active'] ?? 0) === 1) ? 'activated' : 'deactivated';

            return [
                'message' => 'Retention policy ' . ($policy['policy_code'] ?? '') . ' ' . $label . '.',
                'default' => '/admin/archive?tab=policies',
                'data'    => ['policy' => $policy],
            ];
        });
    }

    /** @param callable(): array<string, mixed> $fn */
    private function handleMutation(callable $fn): ResponseInterface|RedirectResponse
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');

        try {
            $result = $fn();
            $redirectTo = $this->safeReturnUrl($this->request->getPost('return_url'), $result['default'] ?? '/admin/archive');

            if ($this->wantsJson()) {
                return $this->response->setStatusCode(200)->setJSON([
                    'success'  => true,
                    'message'  => $result['message'] ?? 'Archive updated.',
                    'redirect' => site_url($redirectTo),
                    'csrf'     => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ] + ($result['data'] ?? []));
            }

            return redirect()->to($redirectTo)->with('success', $result['message'] ?? 'Archive updated.');
        } catch (Throwable $e) {
            $code = $this->errorStatus($e);
            $msg = $code >= 500 ? 'Unable to complete the archive operation right now.' : $e->getMessage();
            $fallback = $this->safeReturnUrl($this->request->getPost('return_url'), '/admin/archive');

            if ($this->wantsJson()) {
                return $this->response->setStatusCode($code)->setJSON([
                    'success' => false,
                    'message' => $msg,
                    'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
            }

            return redirect()->to($fallback)->with('error', $msg);
        }
    }

    private function handleReadError(Throwable $e): ResponseInterface|RedirectResponse
    {
        $code = $this->errorStatus($e);
        $msg = $code >= 500 ? 'Archive workspace is currently unavailable.' : $e->getMessage();
        if ($this->wantsJson()) {
            return $this->response->setStatusCode($code)->setJSON([
                'success' => false,
                'message' => $msg,
            ]);
        }
        return redirect()->to('/portal')->with('error', $msg);
    }

    private function wantsJson(): bool
    {
        return $this->request->isAJAX()
            || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'application/json');
    }

    private function errorStatus(Throwable $e): int
    {
        $code = (int) $e->getCode();
        if ($e instanceof InvalidArgumentException) {
            return ($code >= 400 && $code < 500) ? $code : 422;
        }
        if ($e instanceof DomainException || $e instanceof RuntimeException) {
            return ($code >= 400 && $code <= 503) ? $code : 403;
        }
        return 500;
    }

    private function safeReturnUrl(mixed $raw, string $default): string
    {
        if (!is_string($raw)) {
            return $default;
        }
        $trimmed = trim($raw);
        if ($trimmed === '' || str_starts_with($trimmed, '//') || !str_starts_with($trimmed, '/')
            || str_contains($trimmed, '://') || preg_match('/[\x00-\x1F\x7F\\\\]/', $trimmed) === 1) {
            return $default;
        }
        return $trimmed;
    }
}
