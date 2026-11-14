<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceDocumentJobModel extends TenantModel
{
    protected $table = 'guidance_document_jobs';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'form_instance_id',
        'form_revision_id',
        'job_type',
        'status',
        'idempotency_key',
        'pdf_sha256',
        'renderer_version',
        'artifact_path',
        'error_message',
        'attempts',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}

