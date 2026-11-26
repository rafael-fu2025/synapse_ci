<?php

declare(strict_types=1);

/**
 * Prepares standard demo accounts and domain prerequisites in humus_db
 * via prepare_defense_env.php, then outputs runner context JSON.
 */

ob_start();
require dirname(__DIR__) . '/prepare_defense_env.php';
ob_end_clean();

$usersByName = [];
foreach ($pdo->query('SELECT id, person_id, username FROM users WHERE deleted_at IS NULL') as $u) {
    $usersByName[$u['username']] = $u;
}

$counsellorId    = (int) ($usersByName['test.counsellor']['id'] ?? 8);
$counsellor2Id   = (int) ($usersByName['test.counsellor2']['id'] ?? 9);
$studentPersonId = (int) ($usersByName['test.student']['person_id'] ?? 0);
$medicineId      = (int) $pdo->query('SELECT id FROM clinic_medicines WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();

$info = [
    'tenant_id' => $tenantId,
    'student_person_id' => $studentPersonId,
    'counsellor_id' => $counsellorId,
    'counsellor2_id' => $counsellor2Id,
    'medicine_id' => $medicineId,
    'accounts' => [
        'student' => 'test.student',
        'employee' => 'test.employee',
        'clinic_intern' => 'test.clinic.intern',
        'clinic_staff' => 'test.clinic.staff',
        'clinic_physician' => 'test.clinic.physician',
        'clinic_admin' => 'test.clinic.admin',
        'guidance_intern' => 'test.guidance.intern',
        'counsellor' => 'test.counsellor',
        'counsellor2' => 'test.counsellor2',
        'guidance_supervisor' => 'test.guidance.supervisor',
        'guidance_admin' => 'test.guidance.admin',
        'bmg_intern' => 'test.bmg.intern',
        'facilities_op' => 'test.facilities.op',
        'bmg_admin' => 'test.bmg.admin',
        'report_viewer' => 'test.report.viewer',
        'audit_reader' => 'test.audit.reader',
        'superadmin' => 'admin@foundationu.com',
        'ai_staff' => 'test.clinic.admin',
    ],
    'ai_department_access' => $pdo->query('SELECT role, unit, permission FROM ai_department_access')->fetchAll(PDO::FETCH_ASSOC),
    'counselling_cases' => $pdo->query('SELECT id, case_number, counsellor_user_id, person_id, status FROM counselling_cases WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC),
    'published_surveys' => $pdo->query('SELECT id, title, status FROM surveys WHERE status = "published" AND deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC),
    'bmg_units' => $pdo->query('SELECT id, name, status FROM bmg_units WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC),
    'bmg_devices' => $pdo->query('SELECT id, unit_id, status, revoked_at FROM bmg_devices')->fetchAll(PDO::FETCH_ASSOC),
    'guidance_gates' => $pdo->query('SELECT gate_key, is_enabled FROM guidance_activation_gates')->fetchAll(PDO::FETCH_ASSOC),
];

echo json_encode($info, JSON_PRETTY_PRINT) . "\n";
