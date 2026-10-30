<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Services\Ai\AiPermissions;
use CodeIgniter\Database\Seeder;

/** Additive catalog installation only; never resets or grants role permissions. */
final class AiPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AiPermissions::catalog() as $row) {
            if ($this->db->table('permissions')->where('code', $row['code'])->countAllResults() === 0) {
                if (!$this->db->table('permissions')->insert($row)) { throw new \RuntimeException('AI permission catalog installation failed.'); }
            }
        }
    }
}
