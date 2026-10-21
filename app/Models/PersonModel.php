<?php

declare(strict_types=1);

namespace App\Models;

final class PersonModel extends TenantModel
{
    protected $table = 'people';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'kind',
        'mis_id',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'gender',
        'date_of_birth',
        'address',
        'mis_synced_at',
        'mis_payload_hash',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    protected $afterFind = ['populateDerivedEmail'];

    /**
     * Finds a person by their MIS ID and kind within the active tenant.
     */
    public function findByMisId(string $misId, string $kind): ?array
    {
        return $this->scoped()
            ->where('kind', $kind)
            ->where('mis_id', trim($misId))
            ->first();
    }

    /**
     * Search people by name or ID (local database lookup).
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        return $this->scoped()
            ->groupStart()
                ->like('first_name', $term)
                ->orLike('last_name', $term)
                ->orLike('mis_id', $term)
            ->groupEnd()
            ->orderBy('last_name', 'ASC')
            ->orderBy('first_name', 'ASC')
            ->findAll($limit);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function populateDerivedEmail(array $data): array
    {
        if (empty($data['data'])) {
            return $data;
        }

        if (!empty($data['singleton'])) {
            if (empty($data['data']['email']) && !empty($data['data']['first_name']) && !empty($data['data']['last_name'])) {
                $data['data']['email'] = \App\Services\UniversityEmail::derive(
                    (string) $data['data']['first_name'],
                    (string) $data['data']['last_name']
                );
            }
        } elseif (is_array($data['data'])) {
            foreach ($data['data'] as &$row) {
                if (is_array($row) && empty($row['email']) && !empty($row['first_name']) && !empty($row['last_name'])) {
                    $row['email'] = \App\Services\UniversityEmail::derive(
                        (string) $row['first_name'],
                        (string) $row['last_name']
                    );
                }
            }
            unset($row);
        }

        return $data;
    }
}
