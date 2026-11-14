<?php

declare(strict_types=1);

namespace App\Models;

final class ClinicEncounterAddendumModel extends TenantModel
{
    protected $table = 'clinic_encounter_addenda';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'encounter_id',
        'addendum_type',
        'note',
        'lab_result_id',
        'author_user_id',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}

