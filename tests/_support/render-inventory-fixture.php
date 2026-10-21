<?php

// CLI-only presentation fixture. No controllers, application server, MIS or SQL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new \ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
$app = config('App');
$app->baseURL = $fixture['origin'] . '/';
$app->indexPage = '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $fixture['path'];
parse_str(parse_url($fixture['path'], PHP_URL_QUERY) ?? '', $_GET);
if (!empty($fixture['fragment'])) $_GET['fragment'] = '1';
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $fixture['path']), null, new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get', $_GET);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
// Session identity and capability flags are for UI coverage, not backend security.
$profile = $_GET['profile'] ?? 'manager';
if (!in_array($profile, ['manager', 'staff', 'reader'], true)) throw new \InvalidArgumentException('Unknown profile');
session()->set(['user' => ['id' => 1, 'name' => 'Inventory Fixture', 'username' => 'inventory-fixture',
    'role' => $profile === 'manager' ? 'superadmin' : 'clinic_staff', 'logged_in' => true], 'tenant_id' => 1]);
$literal = '\"><img src=x onerror="window.inventoryXss=1"><script>window.inventoryXss=1</script>&';
$malicious = ($_GET['variant'] ?? '') === 'malicious';
$corrected = ($_GET['variant'] ?? '') === 'corrected';
$today = date('Y-m-d');
$medicine = ['id' => 11, 'name' => $malicious ? $literal : 'Fixture Medicine', 'generic_name' => $malicious ? $literal : 'Fixture Generic',
    'category' => $malicious ? $literal : 'Analgesic', 'dosage_form' => 'tablet', 'strength' => '500 mg',
    'unit_of_measure' => 'tablet', 'minimum_stock_level' => 12, 'is_active' => 1, 'version' => 3,
    'usable_stock' => 10, 'total_stock' => 15, 'expired_stock' => 2, 'quarantined_stock' => 3,
    'near_expiry_stock' => 10, 'low_stock' => true];
if (($_GET['variant'] ?? '') === 'archived') $medicine['is_active'] = 0;
$batch = ['id' => 31, 'medicine_id' => 11, 'batch_number' => 'LOT-31',
    'expiration_date' => date('Y-m-d', strtotime('+30 days')), 'status' => 'active', 'initial_quantity' => 10, 'remaining_quantity' => 10];
$batches = [$batch,
    array_replace($batch, ['id' => 32, 'batch_number' => 'EXPIRED-32', 'expiration_date' => date('Y-m-d', strtotime('-1 day')), 'initial_quantity' => 2, 'remaining_quantity' => 2]),
    array_replace($batch, ['id' => 33, 'batch_number' => 'QUARANTINE-33', 'status' => 'quarantined', 'initial_quantity' => 3, 'remaining_quantity' => 3])];
$operation = ['id' => 41, 'medicine_id' => 11, 'type' => 'dispense', 'created_at' => $today . ' 09:00:00',
    'encounter_id' => 21, 'actor_id' => 1, 'reason' => $malicious ? $literal : 'Original dispensation snapshot',
    'supplier' => null, 'reference' => null, 'reversal_of' => null, 'reversal_mode' => null,
    'reversed_by_operation_id' => $corrected ? 43 : null, 'correction_mode' => $corrected ? 'restore_unissued' : null];
$receipt = array_replace($operation, ['id' => 42, 'type' => 'receipt', 'encounter_id' => null,
    'supplier' => $malicious ? $literal : 'Fixture Supplier', 'reference' => 'DELIVERY-42', 'reason' => 'Received lot',
    'reversed_by_operation_id' => null, 'correction_mode' => null]);
$operations = [$receipt, $operation];
$movement = ['id' => 51, 'batch_id' => 31, 'operation_id' => 41, 'delta' => -2,
    'created_at' => $today . ' 09:00:00', 'actor_id' => 1, 'reason' => $operation['reason'], 'original_movement_id' => null];
$movements = [$movement, array_replace($movement, ['id' => 50, 'operation_id' => null, 'delta' => 12,
    'actor_id' => null, 'reason' => 'Legacy opening balance'])];
if ($corrected) {
    array_unshift($operations, array_replace($operation, ['id' => 43, 'type' => 'reversal', 'reversal_of' => 41,
        'reversal_mode' => 'restore_unissued', 'reason' => 'All units remained unissued', 'reversed_by_operation_id' => null, 'correction_mode' => null]));
    array_unshift($movements, array_replace($movement, ['id' => 52, 'operation_id' => 43, 'delta' => 2,
        'original_movement_id' => 51, 'reason' => 'All units remained unissued']));
}
if ($malicious) {
    session()->setFlashdata('_ci_old_input', ['get' => [], 'post' => [
        'name' => $literal, 'generic_name' => $literal, 'category' => $literal,
        'supplier' => $literal, 'reference' => $literal, 'reason' => $literal, 'note' => $literal,
    ]]);
}
$base = '/clinic/inventory';
$kind = $fixture['kind'];
$data = ['title' => 'Clinic Inventory', 'pageTitle' => 'Medicine Inventory', 'formModalPage' => false,
    'canManage' => $profile === 'manager', 'canWrite' => $profile !== 'reader'];
switch ($kind) {
    case 'stepper':
        $view = 'clinic/encounters/stepper';
        $patient = ['id' => 11, 'first_name' => 'Ada', 'last_name' => 'Student', 'kind' => 'student', 'mis_id' => 'S001'];
        $visit = ['id' => 21, 'person_id' => 11, 'status' => ($_GET['variant'] ?? '') === 'closed' ? 'completed' : 'in_progress',
            'chief_complaint' => 'Headache', 'assessment_notes' => 'Fixture assessment', 'disposition' => 'discharged',
            'follow_up_date' => null, 'disposition_notes' => 'Fixture discharge notes'];
        $dispensation = ['id' => 61, 'medicine_name' => 'Original medicine snapshot', 'strength' => '500 mg',
            'batch_number' => 'ORIGINAL-LOT-31', 'expiration_date' => '2030-07-19', 'quantity' => 2,
            'unit_of_measure' => 'tablet', 'instructions' => 'Original directions', 'operation_id' => 41,
            'snapshot_reconstructed' => 0, 'reversed_by_operation_id' => 43, 'correction_mode' => 'restore_unissued'];
        $history = [$dispensation,
            array_replace($dispensation, ['id' => 62, 'medicine_name' => 'Record-only medicine snapshot',
                'batch_number' => 'RECORD-ONLY-LOT', 'operation_id' => 44, 'reversed_by_operation_id' => 45, 'correction_mode' => 'record_only']),
            array_replace($dispensation, ['id' => 63, 'medicine_name' => 'Legacy medicine snapshot',
                'batch_number' => 'LEGACY-LOT', 'expiration_date' => '2020-03-09', 'operation_id' => null,
                'snapshot_reconstructed' => 1, 'reversed_by_operation_id' => null, 'correction_mode' => null])];
        // This is the model's already-filtered result, not a test of its SQL:
        // expired-only, quarantined-only and archived medicines are not supplied.
        $available = [$medicine, array_replace($medicine, ['id' => 12, 'name' => 'Fixture Liquid',
            'generic_name' => 'Liquid Generic', 'strength' => '100 ml', 'unit_of_measure' => 'bottle',
            'dosage_form' => 'liquid', 'usable_stock' => 4, 'total_stock' => 9])];
        $dispenseKey = 'inventory-fixture-dispense-key';
        if (($_GET['variant'] ?? '') === 'retained') {
            session()->setFlashdata('_ci_old_input', ['get' => [], 'post' => [
                'medicine_id' => '12', 'quantity' => '3', 'instructions' => $literal, 'idempotency_key' => $dispenseKey,
            ]]);
        }
        $data = array_replace($data, ['title' => 'Visit: Student', 'pageTitle' => 'Clinical Consultation Stepper',
            'encounter' => ['patient' => $patient, 'encounter' => $visit,
                'vitals' => ['bp_systolic' => 120, 'bp_diastolic' => 80, 'temp_c' => 36.5, 'pulse_bpm' => 72,
                    'spo2_pct' => 98, 'weight_kg' => 65, 'height_cm' => 168, 'bmi' => 23.0],
                'treatment' => ['diagnosis' => 'Fixture diagnosis', 'services_rendered' => 'Assessment', 'treatment_plan' => 'Rest'],
                'dispensations' => $history],
            'healthProfile' => ['blood_type' => 'O+', 'allergies' => 'Fixture allergy'],
            'studentProfile' => ['program' => 'BSCS', 'year_level' => 2], 'employeeProfile' => null,
            'availableMedicines' => $available, 'dispenseKey' => $dispenseKey,
            // Keep this true for the closed manager fixture to exercise the
            // template's encounter-status gate independently of its capability gate.
            'canDispense' => $profile !== 'reader']);
        break;
    case 'queue-clinic':
    case 'queue-guidance':
        $view = 'queue/board_partial';
        $unit = substr($kind, strlen('queue-'));
        $ticket = ['id' => 71, 'person_id' => 11, 'ticket_code' => 'FIX-071', 'priority' => 'normal',
            'first_name' => 'Ada', 'last_name' => 'Student', 'mis_id' => 'S001',
            'program' => 'BSCS', 'year_level' => 2, 'employee_dept' => null,
            'called_at' => $today . ' 09:00:00', 'started_at' => $today . ' 09:05:00',
            'completed_at' => null, 'skip_until' => null];
        $data = ['unit' => $unit, 'queueDate' => $today, 'summary' => [
            'waiting' => [], 'called' => [], 'in_consultation' => [$ticket], 'done' => [], 'skipped' => [],
            'counts' => ['waiting' => 0, 'called' => 0, 'in_consultation' => 1, 'done' => 0, 'skipped' => 0, 'total' => 1],
        ]];
        break;
    case 'index':
        $view = 'inventory/index';
        $data += ['medicines' => [$medicine], 'filters' => ['q' => $_GET['q'] ?? '', 'status' => $_GET['status'] ?? 'all']];
        break;
    case 'show':
        $view = 'inventory/show';
        $data += compact('medicine', 'batches', 'operations', 'movements');
        $data += ['movementPage' => max(1, (int) ($_GET['page'] ?? 1)), 'hasMoreMovements' => empty($_GET['page'])];
        break;
    case 'catalog-create':
    case 'catalog-edit':
    case 'receive':
    case 'adjust':
    case 'status':
    case 'reverse':
        [$viewName, $title, $action] = match ($kind) {
            'catalog-create' => ['catalog', 'Add Medicine', $base . '/store'],
            'catalog-edit' => ['catalog', 'Edit Medicine', $base . '/11/update'],
            'receive' => ['receive', 'Receive Medicine', $base . '/11/receive'],
            'adjust' => ['adjust', 'Remove Stock', $base . '/batches/31/adjust'],
            'status' => ['status', 'Change Batch Status', $base . '/batches/31/status'],
            'reverse' => ['reverse', 'Correct Dispensation', $base . '/operations/41/reverse'],
        };
        $view = 'inventory/' . $viewName;
        $data = array_replace($data, ['title' => $title, 'pageTitle' => $title, 'formModalPage' => true,
            'formAction' => site_url($action), 'cancelUrl' => site_url($kind === 'catalog-create' ? $base : $base . '/11'),
            'idempotencyKey' => 'inventory-fixture-' . $kind . '-key', 'medicine' => $kind === 'catalog-create' ? null : $medicine,
            'batch' => $batch, 'operation' => $operation]);
        break;
    default:
        throw new \InvalidArgumentException('Unknown inventory fixture kind');
}
echo view($view, $data);
