<?php

declare(strict_types=1);

namespace App\Models;

final class UserModel extends TenantModel
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'username',
        'email',
        'password_hash',
        'role',
        'status',
        'last_login_at',
    ];

    protected $beforeUpdate = ['invalidatePasswordSessions'];

    /** Keep replacement and revocation in one SQL UPDATE, including bulk updates. */
    protected function invalidatePasswordSessions(array $event): array
    {
        if (array_key_exists('password_hash', $event['data'])) {
            $event['data']['auth_version'] = new \CodeIgniter\Database\RawSql('auth_version + 1');
        }
        return $event;
    }

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    /**
     * Find active user by username or email.
     */
    public function findByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        return $this->scoped()
            ->groupStart()
                ->where('username', $identifier)
                ->orWhere('email', $identifier)
            ->groupEnd()
            ->where('status', 'active')
            ->first();
    }

    /**
     * Get permission codes granted to a given role.
     *
     * @return array<int, string>
     */
    public function getPermissionsForRole(string $role): array
    {
        $db = \Config\Database::connect();
        $rows = $db->table('role_permissions rp')
            ->select('p.code')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role', $role)
            ->get()
            ->getResultArray();

        $codes = array_column($rows, 'code');
        // Staff retain employee self-service, not employee departmental/referral authority.
        if (in_array($role, ['clinic_admin', 'clinic_staff', 'guidance_admin', 'guidance_supervisor', 'counsellor',
            'bmg_admin', 'facilities_op', 'audit_reader', 'report_viewer'], true)) {
            $baseline = $db->table('role_permissions rp')->select('p.code')
                ->join('permissions p', 'p.id = rp.permission_id')->where('rp.role', 'employee')
                ->whereIn('p.code', ['portal.appointments.read', 'portal.appointments.create', 'portal.records.read'])->get()->getResultArray();
            $codes = array_values(array_unique(array_merge($codes, array_column($baseline, 'code'))));
        }
        return $codes;
    }
}
