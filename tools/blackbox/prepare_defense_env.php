<?php

declare(strict_types=1);

/**
 * Prepare humus_db for live adviser black-box evaluation or automated dry-run.
 *
 * Idempotently ensures:
 *  1. All 6 default Guidance activation gates are signed off & enabled (`is_enabled = 1`)
 *     so EMP-01 (GGR-005 Live Referral) is ready without waiting on prerequisite steps.
 *  2. Demo accounts exist (password: `password123`) for roles not in the default seed:
 *     - test.clinic.physician (`clinic_physician`)
 *     - test.clinic.intern (`clinic_intern`, active shift supervised by test.clinic.admin)
 *     - test.guidance.intern (`guidance_intern`, active shift supervised by test.guidance.supervisor)
 *     - test.bmg.intern (`bmg_intern`, active shift supervised by test.bmg.admin)
 *  3. Active `department_staff` rows exist for Clinic, Guidance, and BMG team members.
 *  4. At least one active Clinic medicine + positive stock batch (`Paracetamol 500mg`) exists.
 *  5. `login_attempt_buckets` is cleared so evaluators do not hit leftover IP lockouts.
 */

$root = dirname(__DIR__, 2);
$env = [];
foreach (file($root . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    $env[$k] = trim($v, "\"'");
}

$host = $env['database.default.hostname'] ?? '127.0.0.1';
$port = $env['database.default.port'] ?? '3306';
$db   = $env['database.default.database'] ?? 'humus_db';
$user = $env['database.default.username'] ?? 'root';
$pass = $env['database.default.password'] ?? '';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tenantId = (int) $pdo->query('SELECT id FROM tenants WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($tenantId <= 0) {
    fwrite(STDERR, "No active tenant found in humus_db.\n");
    exit(1);
}

$now = date('Y-m-d H:i:s');
$pwHash = password_hash('password123', PASSWORD_BCRYPT);

function ensureDepartmentStaff(PDO $pdo, int $tenantId, int $personId, string $unit, string $now): void
{
    $check = $pdo->prepare('SELECT id, unit FROM department_staff WHERE tenant_id = ? AND person_id = ? ORDER BY id ASC LIMIT 1');
    $check->execute([$tenantId, $personId]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $resolvedUnit = trim((string) $row['unit']) !== '' ? (string) $row['unit'] : $unit;
        $pdo->prepare('UPDATE department_staff SET unit = ?, status = "active", updated_at = ? WHERE id = ?')
            ->execute([$resolvedUnit, $now, $row['id']]);
    } else {
        $pdo->prepare('INSERT INTO department_staff (tenant_id, person_id, unit, status, enrolled_by_user_id, created_at, updated_at) VALUES (?, ?, ?, "active", 1, ?, ?)')
            ->execute([$tenantId, $personId, $unit, $now, $now]);
    }
}

function upsertEmployeeUser(
    PDO $pdo,
    int $tenantId,
    string $username,
    string $role,
    string $first,
    string $last,
    string $misId,
    string $dept,
    string $position,
    ?string $staffUnit,
    string $pwHash,
    string $now
): int {
    $stmt = $pdo->prepare('SELECT id, person_id FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $userId = (int) $row['id'];
        $personId = (int) $row['person_id'];
        $pdo->prepare('UPDATE users SET role = ?, status = "active", password_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$role, $pwHash, $now, $userId]);
    } else {
        $email = $username . '@foundationu.local';
        $pdo->prepare('INSERT INTO people (tenant_id, kind, mis_id, first_name, last_name, email, created_at, updated_at) VALUES (?, "employee", ?, ?, ?, ?, ?, ?)')
            ->execute([$tenantId, $misId, $first, $last, $email, $now, $now]);
        $personId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO employee_profiles (tenant_id, person_id, department, position, employment_status, is_teaching) VALUES (?, ?, ?, ?, "active", 0)')
            ->execute([$tenantId, $personId, $dept, $position]);
        $pdo->prepare('INSERT INTO users (tenant_id, person_id, username, email, password_hash, role, status, auth_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, "active", 1, ?, ?)')
            ->execute([$tenantId, $personId, $username, $email, $pwHash, $role, $now, $now]);
        $userId = (int) $pdo->lastInsertId();
    }

    if ($staffUnit !== null) {
        ensureDepartmentStaff($pdo, $tenantId, $personId, $staffUnit, $now);
    }

    return $userId;
}

function upsertInternUser(
    PDO $pdo,
    int $tenantId,
    string $username,
    string $unit,
    string $internRole,
    string $first,
    string $last,
    string $misId,
    int $supervisorUserId,
    string $pwHash,
    string $now
): int {
    $stmt = $pdo->prepare('SELECT id, person_id FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $userId = (int) $row['id'];
        $personId = (int) $row['person_id'];
        $pdo->prepare('UPDATE users SET role = ?, status = "active", password_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$internRole, $pwHash, $now, $userId]);
    } else {
        $email = $username . '@foundationu.local';
        $pdo->prepare('INSERT INTO people (tenant_id, kind, mis_id, first_name, last_name, email, created_at, updated_at) VALUES (?, "student", ?, ?, ?, ?, ?, ?)')
            ->execute([$tenantId, $misId, $first, $last, $email, $now, $now]);
        $personId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO student_profiles (tenant_id, person_id, program, year_level, created_at, updated_at) VALUES (?, ?, "BSIT", 4, ?, ?)')
            ->execute([$tenantId, $personId, $now, $now]);
        $pdo->prepare('INSERT INTO users (tenant_id, person_id, username, email, password_hash, role, status, auth_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, "active", 1, ?, ?)')
            ->execute([$tenantId, $personId, $username, $email, $pwHash, $internRole, $now, $now]);
        $userId = (int) $pdo->lastInsertId();
    }

    // Active shift spanning [now - 1 day, now + 30 days] in UTC for defense reliability.
    $pdo->prepare('DELETE FROM student_intern_placements WHERE user_id = ?')->execute([$userId]);
    $starts = gmdate('Y-m-d H:i:s', time() - 86400);
    $ends   = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
    $pdo->prepare('INSERT INTO student_intern_placements (tenant_id, user_id, person_id, unit, intern_role, supervisor_user_id, status, starts_at, ends_at, academic_term, assignment_reason, activated_at, created_by_user_id, updated_by_user_id, version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, "active", ?, ?, "2026-2027 1st Sem", "Pre-defense evaluation placement", ?, 1, 1, 1, ?, ?)')
        ->execute([$tenantId, $userId, $personId, $unit, $internRole, $supervisorUserId, $starts, $ends, $starts, $now, $now]);

    return $userId;
}

// Clear login rate-limit buckets before evaluation starts.
$pdo->exec('DELETE FROM login_attempt_buckets');

// Index existing users by username.
$usersByName = [];
foreach ($pdo->query('SELECT id, person_id, username FROM users WHERE deleted_at IS NULL') as $u) {
    $usersByName[$u['username']] = $u;
}

$clinicAdminId        = (int) ($usersByName['test.clinic.admin']['id'] ?? 3);
$guidanceSupervisorId = (int) ($usersByName['test.guidance.supervisor']['id'] ?? 7);
$bmgAdminId           = (int) ($usersByName['test.bmg.admin']['id'] ?? 10);

// 1. Ensure active department_staff rows for team workspace collaboration.
foreach ([
    [$usersByName['test.clinic.staff']['person_id'] ?? null, 'clinic'],
    [$usersByName['test.clinic.staff2']['person_id'] ?? null, 'clinic'],
    [$usersByName['test.clinic.admin']['person_id'] ?? null, 'clinic'],
    [$usersByName['test.counsellor']['person_id'] ?? null, 'guidance'],
    [$usersByName['test.counsellor2']['person_id'] ?? null, 'guidance'],
    [$usersByName['test.guidance.supervisor']['person_id'] ?? null, 'guidance'],
    [$usersByName['test.guidance.admin']['person_id'] ?? null, 'guidance'],
    [$usersByName['test.facilities.op']['person_id'] ?? null, 'bmg'],
    [$usersByName['test.bmg.admin']['person_id'] ?? null, 'bmg'],
] as [$pid, $unit]) {
    if ($pid) {
        ensureDepartmentStaff($pdo, $tenantId, (int) $pid, $unit, $now);
    }
}

// 2. Ensure the 4 demo accounts not in default seed exist with password `password123`.
$physicianId      = upsertEmployeeUser($pdo, $tenantId, 'test.clinic.physician', 'clinic_physician', 'Dr. Jose', 'Santos', 'HUMUS-TEST-CLINIC-PHYSICIAN', 'Health Services', 'University Physician', 'clinic', $pwHash, $now);
$clinicInternId   = upsertInternUser($pdo, $tenantId, 'test.clinic.intern', 'clinic', 'clinic_intern', 'Clara', 'Intern', 'HUMUS-TEST-CLINIC-INTERN', $clinicAdminId, $pwHash, $now);
$guidanceInternId = upsertInternUser($pdo, $tenantId, 'test.guidance.intern', 'guidance', 'guidance_intern', 'Gina', 'Intern', 'HUMUS-TEST-GUIDANCE-INTERN', $guidanceSupervisorId, $pwHash, $now);
$bmgInternId      = upsertInternUser($pdo, $tenantId, 'test.bmg.intern', 'bmg', 'bmg_intern', 'Ben', 'Intern', 'HUMUS-TEST-BMG-INTERN', $bmgAdminId, $pwHash, $now);

// 3. Ensure all 6 Guidance rollout activation gates have 3 required sign-offs and `is_enabled = 1`.
$signoffUserId = $guidanceSupervisorId > 0 ? $guidanceSupervisorId : 1;
$defaultGates = [
    'canonical_forms_signoff' => [
        'Canonical Form Codes, Titles, and Revisions Signoff',
        'Canonical form codes/titles/revisions (General Referral 00 vs footer 01, Appointment code mismatch, Counseling Contact Form/Sheet title)',
    ],
    'safety_escalation_sop_signoff' => [
        'Safety, Abuse, and Self-Harm Escalation SOP Signoff',
        'Official safety/abuse/self-harm escalation SOP, after-hours owner, deadlines, and permitted channels',
    ],
    'practitioner_credentials_signoff' => [
        'Practitioner Credentials, Supervision, Co-Sign, and Closure Rules Signoff',
        'Counselor Associate/Counselor/qualified-assessor credential, supervision, co-sign, and closure rules',
    ],
    'privacy_retention_dpo_signoff' => [
        'Privacy Notices, Retention, Disclosures, and Guardian Identity DPO Signoff',
        'Final privacy notices, legal bases, signer-capacity rules, retention periods, disclosure/printing policy, and guardian identity process',
    ],
    'mi_reproduction_signoff' => [
        'Multiple Intelligence Survey Reproduction and Interpretation Policy Signoff',
        'MI reproduction/adaptation authority, exact reviewed wording/mapping, intended population, interpretation copy, and result-release policy',
    ],
    'evaluation_cohort_policy_signoff' => [
        'Service Evaluation Cohort Threshold and Comment Review Policy Signoff',
        'Minimum evaluation aggregate cohort and comment-review policy',
    ],
];

$reqJson = json_encode(['guidance_head', 'qa_office', 'dpo'], JSON_UNESCAPED_SLASHES);
$recJson = json_encode([
    'guidance_head' => ['authority_role' => 'guidance_head', 'user_id' => $signoffUserId, 'notes' => 'Pre-defense evaluation signoff', 'signed_at' => $now],
    'qa_office'     => ['authority_role' => 'qa_office',     'user_id' => $signoffUserId, 'notes' => 'Pre-defense evaluation signoff', 'signed_at' => $now],
    'dpo'           => ['authority_role' => 'dpo',           'user_id' => $signoffUserId, 'notes' => 'Pre-defense evaluation signoff', 'signed_at' => $now],
], JSON_UNESCAPED_SLASHES);

foreach ($defaultGates as $gateKey => [$title, $desc]) {
    $gStmt = $pdo->prepare('SELECT id, approved_at FROM guidance_activation_gates WHERE tenant_id = ? AND gate_key = ? LIMIT 1');
    $gStmt->execute([$tenantId, $gateKey]);
    $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($gRow) {
        $gateId = (int) $gRow['id'];
        $pdo->prepare('UPDATE guidance_activation_gates SET recorded_signoffs_json = ?, is_enabled = 1, approved_at = COALESCE(approved_at, ?), updated_at = ? WHERE id = ?')
            ->execute([$recJson, $now, $now, $gateId]);
    } else {
        $pdo->prepare('INSERT INTO guidance_activation_gates (tenant_id, gate_key, title, description, required_signoffs_json, recorded_signoffs_json, is_enabled, approved_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)')
            ->execute([$tenantId, $gateKey, $title, $desc, $reqJson, $recJson, $now, $now, $now]);
        $gateId = (int) $pdo->lastInsertId();
    }
    if ($pdo->query("SHOW TABLES LIKE 'guidance_activation_gate_signoffs'")->fetchColumn()) {
        $pdo->prepare('DELETE FROM guidance_activation_gate_signoffs WHERE tenant_id = ? AND gate_id = ?')->execute([$tenantId, $gateId]);
        $insSignoff = $pdo->prepare('INSERT INTO guidance_activation_gate_signoffs (tenant_id, gate_id, authority_role, user_id, notes, signed_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (['guidance_head', 'qa_office', 'dpo'] as $role) {
            $insSignoff->execute([$tenantId, $gateId, $role, $signoffUserId, 'Pre-defense evaluation signoff', $now]);
        }
    }
}

// 4. Ensure at least one active medicine + positive stock batch exists for Clinic tests.
$medCount = (int) $pdo->query('SELECT COUNT(*) FROM clinic_medicines WHERE is_active = 1')->fetchColumn();
if ($medCount === 0) {
    $pdo->prepare('INSERT INTO clinic_medicines (tenant_id, name, generic_name, category, dosage_form, strength, unit_of_measure, minimum_stock_level, total_stock, is_active, version, created_at, updated_at) VALUES (?, "Paracetamol 500mg", "Paracetamol", "Analgesic", "Tablet", "500mg", "tablet", 20, 100, 1, 1, ?, ?)')
        ->execute([$tenantId, $now, $now]);
    $medId = (int) $pdo->lastInsertId();
    $exp = date('Y-m-d', strtotime('+365 days'));
    $pdo->prepare('INSERT INTO clinic_medicine_batches (tenant_id, medicine_id, batch_number, expiration_date, initial_quantity, remaining_quantity, status, created_at, updated_at) VALUES (?, ?, "LOT-DEFENSE-01", ?, 100, 100, "active", ?, ?)')
        ->execute([$tenantId, $medId, $exp, $now, $now]);
} else {
    $medId = (int) $pdo->query('SELECT id FROM clinic_medicines WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
}

$enabledGates = (int) $pdo->query("SELECT COUNT(*) FROM guidance_activation_gates WHERE tenant_id = {$tenantId} AND is_enabled = 1")->fetchColumn();
echo json_encode([
    'tenant_id' => $tenantId,
    'enabled_gates' => $enabledGates,
    'medicine_id' => $medId,
    'demo_accounts_ready' => [
        'test.clinic.physician' => $physicianId,
        'test.clinic.intern'    => $clinicInternId,
        'test.guidance.intern'  => $guidanceInternId,
        'test.bmg.intern'       => $bmgInternId,
    ],
    'login_attempt_buckets_cleared' => true,
], JSON_PRETTY_PRINT) . "\n";
