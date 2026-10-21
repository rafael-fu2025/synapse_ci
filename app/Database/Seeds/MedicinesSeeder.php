<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

final class MedicinesSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $meds = [
            [
                'tenant_id'           => 1,
                'name'                => 'Biogesic',
                'generic_name'        => 'Paracetamol',
                'category'            => 'Analgesic / Antipyretic',
                'dosage_form'         => 'Tablet',
                'strength'            => '500mg',
                'unit_of_measure'     => 'tablet',
                'minimum_stock_level' => 100,
                'total_stock'         => 0,
                'created_at'          => $now,
                'updated_at'          => $now,

            ],
            [
                'tenant_id'           => 1,
                'name'                => 'Advil',
                'generic_name'        => 'Ibuprofen',
                'category'            => 'NSAID',
                'dosage_form'         => 'Softgel',
                'strength'            => '200mg',
                'unit_of_measure'     => 'softgel',
                'minimum_stock_level' => 50,
                'total_stock'         => 0,
                'created_at'          => $now,
                'updated_at'          => $now,

            ],
            [
                'tenant_id'           => 1,
                'name'                => 'Kremil-S',
                'generic_name'        => 'Aluminum Hydroxide + Magnesium Hydroxide + Simethicone',
                'category'            => 'Antacid',
                'dosage_form'         => 'Chewable Tablet',
                'strength'            => 'Regular',
                'unit_of_measure'     => 'tablet',
                'minimum_stock_level' => 50,
                'total_stock'         => 0,
                'created_at'          => $now,
                'updated_at'          => $now,

            ],
            [
                'tenant_id'           => 1,
                'name'                => 'Alnix',
                'generic_name'        => 'Cetirizine HCl',
                'category'            => 'Antihistamine',
                'dosage_form'         => 'Tablet',
                'strength'            => '10mg',
                'unit_of_measure'     => 'tablet',
                'minimum_stock_level' => 50,
                'total_stock'         => 0,
                'created_at'          => $now,
                'updated_at'          => $now,

            ],
        ];

        foreach ($meds as $medData) {
            // Catalog only. Real stock must enter via InventoryService::receive with an actor/key.

            $existing = $this->db->table('clinic_medicines')
                ->where('tenant_id', 1)
                ->where('name', $medData['name'])
                ->get()
                ->getRowArray();

            if (!$existing) {
                $this->db->table('clinic_medicines')->insert($medData);
            }
        }
    }
}
