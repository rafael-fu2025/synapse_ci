<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Remove the unsupported employee clinical-referral grant without changing any other role. */
final class RetireEmployeeReferralGrant extends Migration
{
    public function up(): void
    {
        $permission = $this->db->table('permissions')->select('id')->where('code', 'referrals.create')->get()->getRowArray();
        if ($permission !== null) {
            $this->db->table('role_permissions')->where('role', 'employee')
                ->where('permission_id', (int) $permission['id'])->delete();
        }
    }

    public function down(): void
    {
        // Deliberately do not restore the unsupported grant on rollback.
    }
}
