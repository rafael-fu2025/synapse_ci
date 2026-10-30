<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

final class PermissionsAndRolesSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Clinic
            ['code' => 'clinic.queue.read', 'name' => 'View clinic daily queue', 'module' => 'clinic'],
            ['code' => 'clinic.queue.manage', 'name' => 'Call and manage queue tickets', 'module' => 'clinic'],
            ['code' => 'clinic.encounters.read', 'name' => 'View clinic encounters and medical charts', 'module' => 'clinic'],
            ['code' => 'clinic.encounters.create', 'name' => 'Open and record new clinic encounters', 'module' => 'clinic'],
            ['code' => 'clinic.encounters.write', 'name' => 'Update clinical treatments and vitals', 'module' => 'clinic'],
            ['code' => 'clinic.patients.read', 'name' => 'View patient registry and student profiles', 'module' => 'clinic'],
            ['code' => 'clinic.patients.write', 'name' => 'Update health profiles and allergies', 'module' => 'clinic'],
            ['code' => 'clinic.appointments.read', 'name' => 'View scheduled clinic appointments', 'module' => 'clinic'],
            ['code' => 'clinic.appointments.write', 'name' => 'Manage clinic appointment bookings', 'module' => 'clinic'],
            ['code' => 'clinic.inventory.read', 'name' => 'View medicine inventory and stock levels', 'module' => 'clinic'],
            ['code' => 'clinic.inventory.write', 'name' => 'Manage medicine batches and dispensing', 'module' => 'clinic'],

            // Guidance
            ['code' => 'guidance.queue.read', 'name' => 'View guidance daily queue', 'module' => 'guidance'],
            ['code' => 'guidance.queue.manage', 'name' => 'Manage guidance queue transitions', 'module' => 'guidance'],
            ['code' => 'guidance.cases.read', 'name' => 'View assigned counseling cases', 'module' => 'guidance'],
            ['code' => 'guidance.cases.read_any', 'name' => 'Oversight access to all guidance cases', 'module' => 'guidance'],
            ['code' => 'guidance.cases.create', 'name' => 'Open new counseling cases and sessions', 'module' => 'guidance'],
            ['code' => 'guidance.cases.write', 'name' => 'Write encrypted session notes and plans', 'module' => 'guidance'],
            ['code' => 'guidance.schedule.read', 'name' => 'View counsellor availability and calendar', 'module' => 'guidance'],
            ['code' => 'guidance.schedule.manage', 'name' => 'Configure counsellor availability slots', 'module' => 'guidance'],

            // Campus content
            ['code' => 'clinic.announcements.manage', 'name' => 'Manage Clinic announcements', 'module' => 'clinic'],
            ['code' => 'guidance.announcements.manage', 'name' => 'Manage Guidance announcements', 'module' => 'guidance'],
            ['code' => 'guidance.surveys.manage', 'name' => 'Manage identified Guidance surveys', 'module' => 'guidance'],
            ['code' => 'guidance.responses.read', 'name' => 'Read confidential survey responses', 'module' => 'guidance'],

            // Referrals
            ['code' => 'referrals.create', 'name' => 'Create inter-module referrals', 'module' => 'referrals'],
            ['code' => 'referrals.read', 'name' => 'View referrals in transit', 'module' => 'referrals'],
            ['code' => 'referrals.acknowledge', 'name' => 'Acknowledge received referrals', 'module' => 'referrals'],
            ['code' => 'referrals.review', 'name' => 'Review and transition referral status', 'module' => 'referrals'],
            ['code' => 'referrals.close', 'name' => 'Close and complete referrals', 'module' => 'referrals'],

            // Facilities
            ['code' => 'facilities.read', 'name' => 'View composting units and batches', 'module' => 'facilities'],
            ['code' => 'facilities.manage', 'name' => 'Manage composting runs and release logs', 'module' => 'facilities'],
            ['code' => 'facilities.configure', 'name' => 'Configure facilities rotation schedules', 'module' => 'facilities'],
            ['code' => 'facilities.hardware.manage', 'name' => 'Commission facilities hardware', 'module' => 'facilities'],

            // Governance
            ['code' => 'rbac.manage', 'name' => 'Manage users and role assignments', 'module' => 'admin'],
            ['code' => 'audit.read', 'name' => 'View and verify immutable audit chain', 'module' => 'admin'],
            ['code' => 'mis.sync', 'name' => 'Trigger manual MIS directory synchronization', 'module' => 'admin'],
            ['code' => 'reports.read', 'name' => 'View operational reports and analytics', 'module' => 'reports'],
            ['code' => 'reports.export', 'name' => 'Export CSV and data summaries', 'module' => 'reports'],

            // AI catalog entries are never automatically granted to any role.
            ...\App\Services\Ai\AiPermissions::catalog(),

            // Self-service
            ['code' => 'portal.appointments.read', 'name' => 'View own appointments', 'module' => 'portal'],
            ['code' => 'portal.appointments.create', 'name' => 'Book self-service appointments', 'module' => 'portal'],
            ['code' => 'portal.records.read', 'name' => 'View own health summary', 'module' => 'portal'],
        ];

        $permMap = [];
        foreach ($permissions as $perm) {
            $existing = $this->db->table('permissions')->where('code', $perm['code'])->get()->getRowArray();
            if ($existing) {
                $permId = (int) $existing['id'];
            } else {
                $this->db->table('permissions')->insert($perm);
                $permId = (int) $this->db->insertID();
            }
            $permMap[$perm['code']] = $permId;
        }

        // Role permissions catalog
        $rolePermissions = [
            // Hardware access requires deliberate commissioning, even for the platform owner.
                        'superadmin' => array_values(array_filter(
                                        array_diff(array_keys($permMap), ['facilities.hardware.manage']),
                                        static fn (string $code): bool => !str_starts_with($code, 'ai.')
                                    )),

            'clinic_admin' => [
                'clinic.announcements.manage',
                'clinic.queue.read', 'clinic.queue.manage',
                'clinic.encounters.read', 'clinic.encounters.create', 'clinic.encounters.write',
                'clinic.patients.read', 'clinic.patients.write',
                'clinic.appointments.read', 'clinic.appointments.write',
                'clinic.inventory.read', 'clinic.inventory.write',
                'referrals.create', 'referrals.read', 'referrals.acknowledge', 'referrals.review', 'referrals.close',
                'reports.read', 'reports.export', 'rbac.manage', 'mis.sync',
            ],

            'clinic_staff' => [
                'clinic.queue.read', 'clinic.queue.manage',
                'clinic.encounters.read', 'clinic.encounters.create', 'clinic.encounters.write',
                'clinic.patients.read', 'clinic.patients.write',
                'clinic.appointments.read', 'clinic.appointments.write',
                'clinic.inventory.read', 'clinic.inventory.write',
                'referrals.create', 'referrals.read', 'referrals.acknowledge', 'referrals.review', 'referrals.close',
                'reports.read',
            ],

            'guidance_admin' => [
                'guidance.announcements.manage', 'guidance.surveys.manage', 'guidance.responses.read',
                'guidance.queue.read', 'guidance.queue.manage',
                'guidance.cases.read', 'guidance.cases.read_any', 'guidance.cases.create', 'guidance.cases.write',
                'guidance.schedule.read', 'guidance.schedule.manage',
                'clinic.patients.read',
                'referrals.create', 'referrals.read', 'referrals.acknowledge', 'referrals.review', 'referrals.close',
                'reports.read', 'reports.export', 'rbac.manage', 'mis.sync',
            ],

            'guidance_supervisor' => [
                'guidance.announcements.manage', 'guidance.surveys.manage', 'guidance.responses.read',
                'guidance.queue.read', 'guidance.queue.manage',
                'guidance.cases.read', 'guidance.cases.read_any', 'guidance.cases.create', 'guidance.cases.write',
                'guidance.schedule.read', 'guidance.schedule.manage',
                'clinic.patients.read',
                'referrals.create', 'referrals.read', 'referrals.acknowledge', 'referrals.review', 'referrals.close',
                'reports.read',
            ],

            'counsellor' => [
                'guidance.queue.read', 'guidance.queue.manage',
                'guidance.cases.read', 'guidance.cases.create', 'guidance.cases.write',
                'guidance.schedule.read', 'guidance.schedule.manage',
                'clinic.patients.read',
                'referrals.create', 'referrals.read', 'referrals.acknowledge', 'referrals.review', 'referrals.close',
                'reports.read',
            ],

            'bmg_admin' => [
                'facilities.read', 'facilities.manage', 'facilities.configure',
                'reports.read', 'reports.export',
            ],

            'facilities_op' => [
                'facilities.read', 'facilities.manage',
            ],

            'audit_reader' => [
                'audit.read', 'reports.read', 'reports.export',
            ],

            'report_viewer' => [
                'reports.read', 'reports.export',
            ],

            'student' => [
                'portal.appointments.read', 'portal.appointments.create', 'portal.records.read',
            ],

            'employee' => [
                'portal.appointments.read', 'portal.appointments.create', 'portal.records.read',
            ],
        ];

        // Seed role permissions
        $this->db->table('role_permissions')->emptyTable();
        $batch = [];
        foreach ($rolePermissions as $role => $codes) {
            foreach ($codes as $code) {
                if (isset($permMap[$code])) {
                    $batch[] = [
                        'role'          => $role,
                        'permission_id' => $permMap[$code],
                    ];
                }
            }
        }
        if (!empty($batch)) {
            $this->db->table('role_permissions')->insertBatch($batch);
        }
    }
}
