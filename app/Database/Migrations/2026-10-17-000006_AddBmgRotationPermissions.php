<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class AddBmgRotationPermissions extends Migration
{
    public static function catalog(): array
    {
        return [
            'facilities.configure' => 'Configure facilities rotation schedules',
            'facilities.hardware.manage' => 'Commission facilities hardware',
        ];
    }

    public function up(): void
    {
        if (!$this->db->transBegin()) { throw new RuntimeException('Unable to start facilities permission transaction.'); }
        try {
            foreach (self::catalog() as $code => $name) {
                $rows = $this->db->table('permissions')->where('code', $code)->get()->getResultArray();
                if (count($rows) > 1) { throw new RuntimeException('Duplicate facilities permission.'); }
                if ($rows === []) {
                    if (!$this->db->table('permissions')->insert(['code' => $code, 'name' => $name, 'module' => 'facilities'])) {
                        throw new RuntimeException('Unable to install facilities permission.');
                    }
                    $id = (int) $this->db->insertID();
                } else {
                    // Existing metadata is never overwritten; incompatible catalogs require review.
                    if ($rows[0]['module'] !== 'facilities' || $rows[0]['name'] !== $name) {
                        throw new RuntimeException('Incompatible facilities permission metadata.');
                    }
                    $id = (int) $rows[0]['id'];
                }
                // Hardware commissioning is deliberately opt-in, including for superadmins.
                foreach ($code === 'facilities.configure' ? ['superadmin', 'bmg_admin'] : [] as $role) {
                    $key = ['role' => $role, 'permission_id' => $id];
                    if ($this->db->table('role_permissions')->where($key)->countAllResults() === 0
                        && !$this->db->table('role_permissions')->insert($key)) {
                        throw new RuntimeException('Unable to install facilities configure grant.');
                    }
                }
            }
            if (!$this->db->transStatus() || !$this->db->transCommit()) { throw new RuntimeException('Facilities permission transaction failed.'); }
        } catch (\Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Facilities permissions are forward-only; preserve explicit commissioning grants.');
    }
}
