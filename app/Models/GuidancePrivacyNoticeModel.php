<?php

declare(strict_types=1);

namespace App\Models;

final class GuidancePrivacyNoticeModel extends TenantModel
{
    protected $table = 'guidance_privacy_notices';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'notice_code',
        'title',
        'version',
        'content',
        'content_hash',
        'is_active',
        'effectivity_date',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';
}

