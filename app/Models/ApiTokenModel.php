<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class ApiTokenModel extends Model
{
    protected $table = 'api_tokens';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $allowedFields = [
        'tenant_id', 'user_id', 'auth_version', 'token_hash', 'refresh_hash', 'family_id',
        'device_label', 'created_ip', 'last_used_at', 'access_expires_at', 'refresh_expires_at',
        'rotated_at', 'revoked_at',
    ];
}
