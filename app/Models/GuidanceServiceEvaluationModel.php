<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceServiceEvaluationModel extends TenantModel
{
    protected $table            = 'guidance_service_evaluations';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'tenant_id',
        'evaluation_type',
        'form_code',
        'provider_user_id',
        'academic_term',
        'academic_year',
        'visit_type',
        'ratings_json',
        'boolean_response',
        'comments_cipher',
        'comment_moderation_status',
        'moderated_by_user_id',
        'moderated_at',
        'submitted_date',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Retrieve evaluations for a provider and evaluation type with optional filters.
     *
     * @param int $providerUserId
     * @param string $evaluationType
     * @param array<string, mixed> $filters
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getForProvider(
        int $providerUserId,
        string $evaluationType,
        array $filters = [],
        ?int $tenantId = null
    ): array {
        $builder = $this->scoped($tenantId)
            ->where('provider_user_id', $providerUserId)
            ->where('evaluation_type', $evaluationType);

        if (!empty($filters['academic_term']) && is_string($filters['academic_term'])) {
            $builder->where('academic_term', $filters['academic_term']);
        }
        if (!empty($filters['academic_year']) && is_string($filters['academic_year'])) {
            $builder->where('academic_year', $filters['academic_year']);
        }
        if (!empty($filters['visit_type']) && is_string($filters['visit_type'])) {
            $builder->where('visit_type', $filters['visit_type']);
        }
        if (!empty($filters['date_from']) && is_string($filters['date_from'])) {
            $builder->where('submitted_date >=', $filters['date_from']);
        }
        if (!empty($filters['date_to']) && is_string($filters['date_to'])) {
            $builder->where('submitted_date <=', $filters['date_to']);
        }

        return $builder->orderBy('id', 'ASC')->findAll();
    }
}

