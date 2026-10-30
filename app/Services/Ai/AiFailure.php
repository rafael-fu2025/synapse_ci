<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

final class AiFailure extends RuntimeException
{
    public const REASONS = [
        'invalid_config', 'disabled', 'governance_required', 'tenant_not_approved',
        'unit_not_approved', 'revision_not_approved', 'endpoint_not_allowed',
        'dns_failed', 'address_not_allowed', 'http_dispatch_not_approved',
        'invalid_request', 'request_too_large', 'response_too_large',
        'transport_unavailable', 'transport_failed', 'provider_auth_failed',
        'provider_rate_limited', 'provider_unavailable', 'provider_rejected',
        'invalid_response', 'completion_rejected', 'structured_output_invalid',
        'probe_failed', 'compatibility_required',
        'insufficient_data', 'invalid_snapshot', 'analytics_unavailable',
        'encryption_unavailable', 'payload_unavailable', 'store_unavailable',
        'snapshot_changed_requires_review', 'snapshot_expired', 'invalid_job',
        'daily_budget_exceeded', 'job_not_found', 'invalid_lease', 'worker_busy',
        'invalid_review', 'insight_not_found', 'review_conflict', 'invalid_review_transition',
        'snapshot_not_found', 'lease_lost', 'invalid_scope', 'invalid_payload',
        'independent_transaction_required',
        'access_denied', 'authentication_required', 'library_not_approved',
        'output_rejected', 'job_expired', 'endpoint_changed',
        'dispatch_paused', 'dispatch_budget_exceeded', 'input_budget_exceeded',
        'invalid_settings', 'settings_unavailable', 'settings_busy', 'settings_conflict',
        'settings_not_saved', 'https_required', 'privacy_confirmation_required', 'discovery_key_required',
    ];

    public readonly string $reason;

    public function __construct(#[\SensitiveParameter] string $reason, int $status = 503)
    {
        $this->reason = in_array($reason, self::REASONS, true) ? $reason : 'transport_failed';
        parent::__construct($this->reason, $status >= 400 && $status <= 599 ? $status : 503);
    }
}
