<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\GuidanceEvaluationTokenModel;
use App\Models\GuidanceServiceEvaluationModel;
use App\Services\Api\ApiContext;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Service for Phase 7 Pseudonymous Service Evaluations & Threshold-Safe Aggregates:
 * - Psychosocial Support Evaluation Form (F-OGC-GPS-PEF-008-00)
 * - Counseling Evaluation Form (F-OGC-GCS-CEF-009-00)
 *
 * Guarantees strict cryptographic unlinkability between student identity and evaluation rows,
 * one-time token issuance and redemption, encrypted qualitative feedback, comment moderation
 * gating, and minimum cohort size (MIN_COHORT_SIZE = 5) threshold suppression.
 */
class GuidanceServiceEvaluationService
{
    public const MIN_COHORT_SIZE = 5;

    public const TYPE_PSYCHOSOCIAL = 'psychosocial_support_evaluation';
    public const TYPE_COUNSELING   = 'counseling_evaluation';

    public const FORM_CODE_PSYCHOSOCIAL = 'F-OGC-GPS-PEF-008-00';
    public const FORM_CODE_COUNSELING   = 'F-OGC-GCS-CEF-009-00';

    public const FORM_CODES = [
        self::TYPE_PSYCHOSOCIAL => self::FORM_CODE_PSYCHOSOCIAL,
        self::TYPE_COUNSELING   => self::FORM_CODE_COUNSELING,
    ];

    public const CODE_TO_TYPE = [
        self::FORM_CODE_PSYCHOSOCIAL => self::TYPE_PSYCHOSOCIAL,
        self::FORM_CODE_COUNSELING   => self::TYPE_COUNSELING,
    ];

    public const PSYCHOSOCIAL_VISIT_TYPES = [
        'walk_in',
        'called',
        'referred',
        'follow_up',
    ];

    public const PSYCHOSOCIAL_RATING_FIELDS = [
        'rating_welcoming',
        'rating_empathy',
        'rating_clarity',
        'rating_helpfulness',
        'rating_environment',
    ];

    public const COUNSELING_RATING_FIELDS = [
        'rating_rapport',
        'rating_understanding',
        'rating_goal_progress',
        'rating_confidentiality_comfort',
        'rating_overall_service',
    ];

    public const MODERATION_STATUSES = [
        'pending_review',
        'approved',
        'redacted',
    ];

    private GuidanceEvaluationTokenModel $tokenModel;
    private GuidanceServiceEvaluationModel $evaluationModel;
    private EncryptionService $encryptionService;
    private GuidanceAccessPolicy $accessPolicy;
    private BaseConnection $db;
    private ?array $defaultActor;

    public function __construct(
        ?GuidanceEvaluationTokenModel $tokenModel = null,
        ?GuidanceServiceEvaluationModel $evaluationModel = null,
        ?EncryptionService $encryptionService = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?BaseConnection $db = null,
        ?array $defaultActor = null
    ) {
        $models = array_filter([$tokenModel, $evaluationModel]);
        $this->db = $db ?? ($models !== [] ? reset($models)->db : Database::connect());

        foreach ($models as $model) {
            if ($model->db !== $this->db) {
                throw new InvalidArgumentException('Guidance evaluation models must share their database connection.');
            }
        }

        $this->tokenModel        = $tokenModel ?? new GuidanceEvaluationTokenModel($this->db);
        $this->evaluationModel   = $evaluationModel ?? new GuidanceServiceEvaluationModel($this->db);
        $this->encryptionService = $encryptionService ?? new EncryptionService();
        $this->accessPolicy      = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
        $this->defaultActor      = $defaultActor;
    }

    /**
     * Set or clear default actor context for service calls.
     *
     * @param array<string, mixed>|null $actor
     */
    public function setActor(?array $actor): self
    {
        $this->defaultActor = $actor;
        return $this;
    }

    /**
     * Return a clone with the given actor context bound.
     *
     * @param array<string, mixed>|null $actor
     */
    public function withActor(?array $actor): self
    {
        $clone = clone $this;
        $clone->defaultActor = $actor;
        return $clone;
    }

    /**
     * Normalize evaluation type or canonical form code to internal evaluation_type.
     */
    public function resolveEvaluationType(string $evaluationTypeOrCode): string
    {
        $trimmed = trim($evaluationTypeOrCode);
        if (isset(self::FORM_CODES[$trimmed])) {
            return $trimmed;
        }
        if (isset(self::CODE_TO_TYPE[$trimmed])) {
            return self::CODE_TO_TYPE[$trimmed];
        }

        throw new InvalidArgumentException("Invalid evaluation type: {$evaluationTypeOrCode}", 422);
    }

    /**
     * Compute deterministic eligibility HMAC over tenant, personId, episodeId/sessionId, and evaluationType.
     * Prevents duplicate token issuance without storing raw person_id in evaluation records.
     */
    public function computeEligibilityHmac(
        int $tenantId,
        int $personId,
        string $evaluationType,
        ?int $episodeId = null,
        ?int $sessionId = null
    ): string {
        $canonicalType = $this->resolveEvaluationType($evaluationType);
        $epPart = $episodeId !== null && $episodeId > 0 ? $episodeId : 0;
        $sesPart = $sessionId !== null && $sessionId > 0 ? $sessionId : 0;

        $payload = sprintf(
            'geval_elig:v1:tenant=%d|person=%d|episode=%d|session=%d|type=%s',
            $tenantId,
            $personId,
            $epPart,
            $sesPart,
            $canonicalType
        );

        $secret = (string) (env('COUNSELLING_KEY') ?: env('encryption.key') ?: 'humus-guidance-eval-hmac-key-v1');

        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Issue a one-time evaluation redemption token for a student and episode/session.
     * Rejects duplicate token issuance for the same student + episode/session + evaluationType (409 DomainException).
     *
     * @param int $personId
     * @param string $evaluationType
     * @param int $providerUserId
     * @param array<string, mixed> $context Optional episode_id, session_id, expires_at, actor
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed> One-time raw token and metadata
     */
    public function issueEvaluationToken(
        int $personId,
        string $evaluationType,
        int $providerUserId,
        array $context = [],
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        if ($personId <= 0) {
            throw new InvalidArgumentException('Valid person ID is required to issue an evaluation token.', 422);
        }
        if ($providerUserId <= 0) {
            throw new InvalidArgumentException('Valid provider user ID is required to issue an evaluation token.', 422);
        }

        $canonicalType = $this->resolveEvaluationType($evaluationType);
        $formCode = self::FORM_CODES[$canonicalType];
        $tid = $tenantId ?? (isset($context['tenant_id']) ? (int) $context['tenant_id'] : null) ?? CurrentTenant::id();

        $episodeId = !empty($context['episode_id']) ? (int) $context['episode_id'] : null;
        $sessionId = !empty($context['session_id']) ? (int) $context['session_id'] : null;

        $eligibilityHmac = $this->computeEligibilityHmac($tid, $personId, $canonicalType, $episodeId, $sessionId);

        $existing = $this->tokenModel->findByEligibilityHmac($eligibilityHmac, $tid);
        if ($existing !== null) {
            throw new DomainException(
                'An evaluation token has already been issued for this student and service context.',
                409
            );
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $issuedAt = $context['issued_at'] ?? date('Y-m-d H:i:s');
        $expiresAt = array_key_exists('expires_at', $context)
            ? $context['expires_at']
            : date('Y-m-d H:i:s', strtotime('+14 days'));

        $insertData = [
            'tenant_id'        => $tid,
            'episode_id'       => $episodeId,
            'session_id'       => $sessionId,
            'evaluation_type'  => $canonicalType,
            'provider_user_id' => $providerUserId,
            'eligibility_hmac' => $eligibilityHmac,
            'token_hash'       => $tokenHash,
            'status'           => 'issued',
            'issued_at'        => $issuedAt,
            'redeemed_at'      => null,
            'expires_at'       => $expiresAt,
        ];

        $id = $this->tokenModel->insert($insertData);
        if ($id === false) {
            throw new RuntimeException('Failed to issue guidance evaluation token.', 500);
        }

        $tokenId = (int) $id;

        return [
            'id'               => $tokenId,
            'token_id'         => $tokenId,
            'tenant_id'        => $tid,
            'episode_id'       => $episodeId,
            'session_id'       => $sessionId,
            'evaluation_type'  => $canonicalType,
            'form_code'        => $formCode,
            'provider_user_id' => $providerUserId,
            'eligibility_hmac' => $eligibilityHmac,
            'token_hash'       => $tokenHash,
            'token'            => $rawToken,
            'raw_token'        => $rawToken,
            'status'           => 'issued',
            'issued_at'        => $issuedAt,
            'redeemed_at'      => null,
            'expires_at'       => $expiresAt,
        ];
    }

    /**
     * Redeem a one-time evaluation token and persist a strictly pseudonymous evaluation row.
     *
     * Verifies token exists, is 'issued', and is not expired.
     * Validates ratings (1..5) and required fields for the evaluation type.
     * Marks token 'redeemed' and inserts pseudonymous row into guidance_service_evaluations
     * (encrypting qualitative comments via EncryptionService).
     * Rejects re-use of redeemed token (throws 409 DomainException).
     *
     * @param string $rawToken
     * @param array<string, mixed> $payload
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function submitEvaluation(
        string $rawToken,
        array $payload,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $tid = $tenantId ?? (isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : null) ?? CurrentTenant::id();

        $effectiveActor = $actor ?? (isset($payload['actor']) && is_array($payload['actor']) ? $payload['actor'] : $this->defaultActor);
        if ($effectiveActor !== null) {
            $this->authorizeActor($effectiveActor, 'guidance.evaluations.submit', $tid);
        }

        $cleanToken = trim($rawToken);
        if ($cleanToken === '') {
            throw new InvalidArgumentException('Evaluation redemption token is required.', 422);
        }

        $tokenHash = hash('sha256', $cleanToken);
        $tokenRow = $this->tokenModel->findByTokenHash($tokenHash, $tid);

        if ($tokenRow === null) {
            throw new InvalidArgumentException('Invalid or unknown evaluation redemption token.', 404);
        }

        if ($tokenRow['status'] === 'redeemed') {
            throw new DomainException('Evaluation token has already been redeemed and cannot be reused.', 409);
        }

        if ($tokenRow['status'] === 'expired') {
            throw new DomainException('Evaluation token has expired.', 409);
        }

        if (!empty($tokenRow['expires_at']) && strtotime((string) $tokenRow['expires_at']) < time()) {
            $this->tokenModel->update((int) $tokenRow['id'], ['status' => 'expired']);
            throw new DomainException('Evaluation token has expired.', 409);
        }

        $evaluationType = (string) $tokenRow['evaluation_type'];
        $formCode = self::FORM_CODES[$evaluationType] ?? self::FORM_CODE_PSYCHOSOCIAL;
        $providerUserId = (int) $tokenRow['provider_user_id'];

        $validated = $this->validateEvaluationPayload($evaluationType, $payload);

        $commentsCipher = null;
        if ($validated['comments'] !== null && $validated['comments'] !== '') {
            $commentsCipher = $this->encryptComment($validated['comments']);
        }

        $submittedDate = isset($payload['submitted_date']) && is_string($payload['submitted_date'])
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['submitted_date'])
            ? $payload['submitted_date']
            : date('Y-m-d');

        $academicTerm = isset($payload['academic_term']) && is_string($payload['academic_term']) && trim($payload['academic_term']) !== ''
            ? substr(trim($payload['academic_term']), 0, 64)
            : null;
        $academicYear = isset($payload['academic_year']) && is_string($payload['academic_year']) && trim($payload['academic_year']) !== ''
            ? substr(trim($payload['academic_year']), 0, 32)
            : null;

        // Mark token as redeemed
        $this->tokenModel->update((int) $tokenRow['id'], [
            'status'      => 'redeemed',
            'redeemed_at' => date('Y-m-d H:i:s'),
        ]);

        // Insert unlinkable pseudonymous evaluation row (NO person_id, NO session_id, NO episode_id, NO token_id)
        $evalInsert = [
            'tenant_id'                 => $tid,
            'evaluation_type'           => $evaluationType,
            'form_code'                 => $formCode,
            'provider_user_id'          => $providerUserId,
            'academic_term'             => $academicTerm,
            'academic_year'             => $academicYear,
            'visit_type'                => $validated['visit_type'],
            'ratings_json'              => json_encode($validated['ratings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'boolean_response'          => $validated['boolean_response'],
            'comments_cipher'           => $commentsCipher,
            'comment_moderation_status' => 'pending_review',
            'moderated_by_user_id'      => null,
            'moderated_at'              => null,
            'submitted_date'            => $submittedDate,
        ];

        $evalId = $this->evaluationModel->insert($evalInsert);
        if ($evalId === false) {
            throw new RuntimeException('Failed to store service evaluation.', 500);
        }

        $evalId = (int) $evalId;
        $record = $this->evaluationModel->scoped($tid)->find($evalId) ?? $evalInsert;
        $record['id'] = $evalId;
        $record['evaluation_id'] = $evalId;
        $record['ratings'] = $validated['ratings'];

        return $record;
    }

    /**
     * Moderate a qualitative comment on a service evaluation.
     * Requires guidance.evaluations.moderate permission.
     * Sets comment_moderation_status to 'approved' or 'redacted'.
     *
     * @param int $evaluationId
     * @param string $status 'approved' or 'redacted'
     * @param int|array<string, mixed>|null $tenantId
     * @param int|array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function moderateComment(
        int $evaluationId,
        string $status,
        int|array|null $tenantId = null,
        int|array|null $actor = null
    ): array {
        if (is_array($tenantId)) {
            $actor = $tenantId;
            $tenantId = isset($actor['tenant_id']) ? (int) $actor['tenant_id'] : null;
        }
        if (is_int($actor)) {
            $actor = ['id' => $actor];
        }

        $tid = $tenantId ?? (is_array($actor) && isset($actor['tenant_id']) ? (int) $actor['tenant_id'] : null) ?? CurrentTenant::id();

        $normalizedStatus = strtolower(trim($status));
        if (!in_array($normalizedStatus, ['approved', 'redacted'], true)) {
            throw new InvalidArgumentException(
                "Invalid comment moderation status '{$status}'. Must be 'approved' or 'redacted'.",
                422
            );
        }

        $effectiveActor = $actor ?? $this->resolveAmbientActor();
        $resolvedActor = $this->authorizeActor($effectiveActor, 'guidance.evaluations.moderate', $tid);

        $evaluation = $this->evaluationModel->scoped($tid)->find($evaluationId);
        if ($evaluation === null) {
            throw new InvalidArgumentException("Service evaluation {$evaluationId} not found.", 404);
        }

        $moderatorUserId = $resolvedActor !== null && !empty($resolvedActor['id'])
            ? (int) $resolvedActor['id']
            : null;
        $now = date('Y-m-d H:i:s');

        $this->evaluationModel->update($evaluationId, [
            'comment_moderation_status' => $normalizedStatus,
            'moderated_by_user_id'      => $moderatorUserId,
            'moderated_at'              => $now,
        ]);

        $updated = $this->evaluationModel->scoped($tid)->find($evaluationId);
        if ($updated !== null && isset($updated['ratings_json'])) {
            $updated['ratings'] = json_decode((string) $updated['ratings_json'], true) ?: [];
        }

        return $updated ?? [];
    }

    /**
     * Retrieve threshold-safe aggregate metrics and moderated comments for a provider.
     *
     * Requires guidance.evaluations.view_aggregates (counselors can view their own aggregates;
     * supervisors/admins can view any provider's aggregates).
     * Enforces MIN_COHORT_SIZE = 5:
     * - When response count < 5: returns cohort_met => false, averages => null, boolean_rate => null, comments => [].
     * - When response count >= 5: returns cohort_met => true, dimension means + overall_mean, boolean_rate,
     *   and only decrypted 'approved' comments.
     *
     * @param int $providerUserId
     * @param string $evaluationType
     * @param array<string, mixed> $filters
     * @param int|array<string, mixed>|null $tenantId
     * @param int|array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function getProviderAggregates(
        int $providerUserId,
        string $evaluationType,
        array $filters = [],
        int|array|null $tenantId = null,
        int|array|null $actor = null
    ): array {
        if (is_array($tenantId)) {
            $actor = $tenantId;
            $tenantId = isset($actor['tenant_id']) ? (int) $actor['tenant_id'] : null;
        }
        if (is_int($actor)) {
            $actor = ['id' => $actor];
        }

        if ($actor === null) {
            if (isset($filters['actor']) && is_array($filters['actor'])) {
                $actor = $filters['actor'];
            } elseif (isset($filters['actor']) && is_int($filters['actor'])) {
                $actor = ['id' => $filters['actor']];
            } elseif (isset($filters['viewer_user_id']) && is_numeric($filters['viewer_user_id'])) {
                $actor = ['id' => (int) $filters['viewer_user_id']];
            } elseif (isset($filters['actor_user_id']) && is_numeric($filters['actor_user_id'])) {
                $actor = ['id' => (int) $filters['actor_user_id']];
            } elseif (isset($filters['id']) && is_numeric($filters['id'])
                && (isset($filters['role']) || isset($filters['tenant_id']) || isset($filters['permissions']))) {
                $actor = $filters;
            }
        }

        $tid = $tenantId
            ?? (is_array($actor) && isset($actor['tenant_id']) ? (int) $actor['tenant_id'] : null)
            ?? (isset($filters['tenant_id']) ? (int) $filters['tenant_id'] : null)
            ?? CurrentTenant::id();

        $canonicalType = $this->resolveEvaluationType($evaluationType);
        $formCode = self::FORM_CODES[$canonicalType];

        $effectiveActor = $actor ?? $this->resolveAmbientActor();
        $this->assertCanViewProviderAggregates($effectiveActor, $providerUserId, $tid);

        $rows = $this->evaluationModel->getForProvider($providerUserId, $canonicalType, $filters, $tid);
        $count = count($rows);

        if ($count < self::MIN_COHORT_SIZE) {
            return [
                'cohort_met'              => false,
                'total_responses'         => $count,
                'minimum_required'        => self::MIN_COHORT_SIZE,
                'evaluation_type'         => $canonicalType,
                'form_code'               => $formCode,
                'provider_user_id'        => $providerUserId,
                'academic_term'           => $filters['academic_term'] ?? null,
                'academic_year'           => $filters['academic_year'] ?? null,
                'averages'                => null,
                'boolean_rate'            => null,
                'visit_type_distribution' => null,
                'comments'                => [],
            ];
        }

        $dimensions = $canonicalType === self::TYPE_PSYCHOSOCIAL
            ? self::PSYCHOSOCIAL_RATING_FIELDS
            : self::COUNSELING_RATING_FIELDS;

        $sums = array_fill_keys($dimensions, 0.0);
        $trueCount = 0;
        $visitDistribution = $canonicalType === self::TYPE_PSYCHOSOCIAL
            ? array_fill_keys(self::PSYCHOSOCIAL_VISIT_TYPES, 0)
            : null;
        $approvedComments = [];

        foreach ($rows as $row) {
            $ratings = json_decode((string) ($row['ratings_json'] ?? '{}'), true) ?: [];
            foreach ($dimensions as $dim) {
                $sums[$dim] += (float) ($ratings[$dim] ?? 0);
            }

            if ((int) ($row['boolean_response'] ?? 0) === 1) {
                $trueCount++;
            }

            if ($visitDistribution !== null && !empty($row['visit_type'])) {
                $vt = (string) $row['visit_type'];
                $visitDistribution[$vt] = ($visitDistribution[$vt] ?? 0) + 1;
            }

            // Strictly include ONLY 'approved' qualitative comments
            if (($row['comment_moderation_status'] ?? '') === 'approved' && !empty($row['comments_cipher'])) {
                $decrypted = $this->decryptComment((string) $row['comments_cipher']);
                if ($decrypted !== null && $decrypted !== '') {
                    $approvedComments[] = $decrypted;
                }
            }
        }

        $averages = [];
        $totalAllDimensions = 0.0;
        foreach ($dimensions as $dim) {
            $averages[$dim] = round($sums[$dim] / $count, 2);
            $totalAllDimensions += $sums[$dim];
        }
        $averages['overall_mean'] = round($totalAllDimensions / ($count * count($dimensions)), 2);

        $booleanRate = round($trueCount / $count, 4);

        return [
            'cohort_met'              => true,
            'total_responses'         => $count,
            'minimum_required'        => self::MIN_COHORT_SIZE,
            'evaluation_type'         => $canonicalType,
            'form_code'               => $formCode,
            'provider_user_id'        => $providerUserId,
            'academic_term'           => $filters['academic_term'] ?? null,
            'academic_year'           => $filters['academic_year'] ?? null,
            'averages'                => $averages,
            'boolean_rate'            => $booleanRate,
            'boolean_percentage'      => round(($trueCount / $count) * 100, 2),
            'boolean_positive_count'  => $trueCount,
            'visit_type_distribution' => $visitDistribution,
            'comments'                => $approvedComments,
        ];
    }

    /**
     * Validate evaluation submission payload according to evaluation type.
     *
     * @param string $evaluationType
     * @param array<string, mixed> $payload
     * @return array{
     *     visit_type: string|null,
     *     ratings: array<string, int>,
     *     boolean_response: int,
     *     comments: string|null
     * }
     */
    private function validateEvaluationPayload(string $evaluationType, array $payload): array
    {
        $visitType = null;
        if ($evaluationType === self::TYPE_PSYCHOSOCIAL) {
            $vt = $payload['visit_type'] ?? null;
            if (!is_string($vt) || !in_array($vt, self::PSYCHOSOCIAL_VISIT_TYPES, true)) {
                throw new InvalidArgumentException(
                    'Psychosocial evaluation requires a valid visit_type (' . implode(', ', self::PSYCHOSOCIAL_VISIT_TYPES) . ').',
                    422
                );
            }
            $visitType = $vt;
            $dimensions = self::PSYCHOSOCIAL_RATING_FIELDS;
            $boolField = 'future_visit_willingness';
            $commentRaw = $payload['qualitative_comments'] ?? $payload['qualitative_feedback'] ?? $payload['comments'] ?? null;
        } else {
            if (isset($payload['visit_type']) && is_string($payload['visit_type']) && trim($payload['visit_type']) !== '') {
                $visitType = trim($payload['visit_type']);
            }
            $dimensions = self::COUNSELING_RATING_FIELDS;
            $boolField = 'recommend_service';
            $commentRaw = $payload['qualitative_feedback'] ?? $payload['qualitative_comments'] ?? $payload['comments'] ?? null;
        }

        $ratingsSource = isset($payload['ratings']) && is_array($payload['ratings'])
            ? array_merge($payload['ratings'], $payload)
            : $payload;

        $ratings = [];
        foreach ($dimensions as $dim) {
            if (!array_key_exists($dim, $ratingsSource)) {
                throw new InvalidArgumentException("Missing required rating field '{$dim}'.", 422);
            }
            $val = $ratingsSource[$dim];
            if (!is_int($val) && !(is_string($val) && preg_match('/^-?\d+$/D', $val))) {
                throw new InvalidArgumentException("Rating field '{$dim}' must be an integer between 1 and 5.", 422);
            }
            $intVal = (int) $val;
            if ($intVal < 1 || $intVal > 5) {
                throw new InvalidArgumentException("Rating field '{$dim}' must be between 1 and 5, got {$intVal}.", 422);
            }
            $ratings[$dim] = $intVal;
        }

        $boolValue = $payload[$boolField] ?? ($payload['boolean_response'] ?? null);
        if ($boolValue === null) {
            throw new InvalidArgumentException("Missing required boolean field '{$boolField}'.", 422);
        }
        $booleanInt = $this->parseBooleanResponse($boolValue, $boolField);

        $comments = null;
        if ($commentRaw !== null) {
            if (!is_string($commentRaw)) {
                throw new InvalidArgumentException('Qualitative comments must be a string.', 422);
            }
            $trimmed = trim($commentRaw);
            if (mb_strlen($trimmed) > 2048) {
                throw new InvalidArgumentException('Qualitative comments must not exceed 2048 characters.', 422);
            }
            if ($trimmed !== '') {
                $comments = $trimmed;
            }
        }

        return [
            'visit_type'       => $visitType,
            'ratings'          => $ratings,
            'boolean_response' => $booleanInt,
            'comments'         => $comments,
        ];
    }

    /**
     * Parse boolean input strictly into 0 or 1.
     */
    private function parseBooleanResponse(mixed $value, string $fieldName): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'yes') {
            return 1;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'no') {
            return 0;
        }

        throw new InvalidArgumentException("Field '{$fieldName}' must be a boolean value.", 422);
    }

    /**
     * Encrypt qualitative comment via EncryptionService.
     */
    private function encryptComment(string $plaintext): string
    {
        try {
            $encrypted = $this->encryptionService->encrypt($plaintext);
            return json_encode([
                'c' => base64_encode($encrypted['cipher']),
                'n' => base64_encode($encrypted['nonce']),
                't' => base64_encode($encrypted['tag']),
                'v' => $encrypted['key_version'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to encrypt evaluation comment: ' . $e->getMessage(), 500, $e);
        }
    }

    /**
     * Decrypt qualitative comment via EncryptionService.
     */
    public function decryptComment(string $cipherPayload): ?string
    {
        try {
            $decoded = json_decode($cipherPayload, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !isset($decoded['c'], $decoded['n'], $decoded['t'], $decoded['v'])) {
                return null;
            }

            return $this->encryptionService->decrypt(
                base64_decode((string) $decoded['c'], true) ?: '',
                base64_decode((string) $decoded['n'], true) ?: '',
                base64_decode((string) $decoded['t'], true) ?: '',
                (int) $decoded['v']
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve ambient actor from defaultActor, ApiContext, AuthenticatedUser, or session.
     *
     * @return array<string, mixed>|null
     */
    private function resolveAmbientActor(): ?array
    {
        if ($this->defaultActor !== null) {
            return $this->defaultActor;
        }

        try {
            $apiIdentity = ApiContext::identity();
            if (is_array($apiIdentity) && !empty($apiIdentity['id'])) {
                return $apiIdentity;
            }
        } catch (Throwable) {
            // Fall through to AuthenticatedUser / session
        }

        try {
            $current = (new AuthenticatedUser())->current();
            if (is_array($current) && !empty($current['id'])) {
                return $current;
            }
        } catch (Throwable) {
            // Fall through
        }

        try {
            $sessionUser = session()->get('user');
            if (is_array($sessionUser) && !empty($sessionUser['id'])) {
                return $sessionUser;
            }
        } catch (Throwable) {
            // No ambient session
        }

        return null;
    }

    /**
     * Authorize actor for a required permission in the tenant.
     *
     * @param array<string, mixed>|null $actor
     * @param string $requiredPermission
     * @param int $tenantId
     * @return array<string, mixed>|null
     */
    private function authorizeActor(?array $actor, string $requiredPermission, int $tenantId): ?array
    {
        if ($actor === null) {
            return null;
        }

        if (isset($actor['tenant_id']) && (int) $actor['tenant_id'] !== $tenantId) {
            throw new DomainException('Cross-tenant evaluation access forbidden.', 403);
        }

        $userId = isset($actor['id']) && is_numeric($actor['id']) ? (int) $actor['id'] : 0;
        if ($userId > 0) {
            $userRow = $this->db->table('users')
                ->where('id', $userId)
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();

            if ($userRow !== null) {
                if (($userRow['status'] ?? '') !== 'active') {
                    throw new DomainException('An active account is required.', 403);
                }

                $role = (string) ($userRow['role'] ?? '');
                $permRows = $this->db->table('role_permissions rp')
                    ->select('p.code')
                    ->join('permissions p', 'p.id = rp.permission_id')
                    ->where('rp.role', $role)
                    ->get()
                    ->getResultArray();
                $permissions = array_column($permRows, 'code');

                $hasPermission = in_array($requiredPermission, $permissions, true)
                    || ($requiredPermission === 'guidance.evaluations.view_aggregates'
                        && in_array('guidance.evaluations.view_aggregate', $permissions, true));

                if (!$hasPermission) {
                    throw new DomainException(
                        "Guidance permission '{$requiredPermission}' forbidden for role '{$role}'.",
                        403
                    );
                }

                return [
                    'id'          => (int) $userRow['id'],
                    'tenant_id'   => (int) $userRow['tenant_id'],
                    'person_id'   => $userRow['person_id'] !== null ? (int) $userRow['person_id'] : null,
                    'role'        => $role,
                    'permissions' => $permissions,
                ];
            }
        }

        if (isset($actor['permissions']) && is_array($actor['permissions'])) {
            $hasPermission = in_array($requiredPermission, $actor['permissions'], true)
                || ($requiredPermission === 'guidance.evaluations.view_aggregates'
                    && in_array('guidance.evaluations.view_aggregate', $actor['permissions'], true));
            if (!$hasPermission) {
                throw new DomainException("Guidance permission '{$requiredPermission}' forbidden.", 403);
            }
            return $actor;
        }

        if (!empty($actor['role'])) {
            $role = (string) $actor['role'];
            $permRows = $this->db->table('role_permissions rp')
                ->select('p.code')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role', $role)
                ->get()
                ->getResultArray();
            $permissions = array_column($permRows, 'code');

            $hasPermission = in_array($requiredPermission, $permissions, true)
                || ($requiredPermission === 'guidance.evaluations.view_aggregates'
                    && in_array('guidance.evaluations.view_aggregate', $permissions, true));

            if (!$hasPermission) {
                throw new DomainException(
                    "Guidance permission '{$requiredPermission}' forbidden for role '{$role}'.",
                    403
                );
            }
            $actor['permissions'] = $permissions;
        }

        return $actor;
    }

    /**
     * Assert that actor can view aggregates for $providerUserId:
     * - Requires guidance.evaluations.view_aggregates
     * - Counselors can view their own aggregates ($actorId === $providerUserId)
     * - Supervisors/admins (or actors with guidance.evaluations.moderate / guidance.cases.read_any)
     *   can view any provider's aggregates.
     *
     * @param array<string, mixed>|null $actor
     * @param int $providerUserId
     * @param int $tenantId
     */
    private function assertCanViewProviderAggregates(?array $actor, int $providerUserId, int $tenantId): void
    {
        $resolvedActor = $this->authorizeActor($actor, 'guidance.evaluations.view_aggregates', $tenantId);
        if ($resolvedActor === null) {
            return;
        }

        $actorId = isset($resolvedActor['id']) ? (int) $resolvedActor['id'] : 0;
        if ($actorId > 0 && $actorId === $providerUserId) {
            return;
        }

        $role = (string) ($resolvedActor['role'] ?? '');
        if (in_array($role, ['guidance_supervisor', 'guidance_admin', 'superadmin'], true)) {
            return;
        }

        $permissions = $resolvedActor['permissions'] ?? [];
        if (in_array('guidance.evaluations.moderate', $permissions, true)
            || in_array('guidance.cases.read_any', $permissions, true)
            || in_array('guidance.evaluations.view_aggregate', $permissions, true)) {
            return;
        }

        throw new DomainException(
            'Access forbidden: counselors may only view their own evaluation aggregates.',
            403
        );
    }
}

