<?php

// CLI-only real-template fixture. No controllers, device API, SQL or hardware.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
// Page fixtures must not inherit realtime connections or retry writes from local .env.
config(\Config\Realtime::class)->enabled = false;
$app = config('App');
$app->baseURL = $fixture['origin'] . ($fixture['basePath'] ?? '/');
$app->indexPage = $fixture['indexPage'] ?? '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $fixture['path'];
parse_str(parse_url($fixture['path'], PHP_URL_QUERY) ?? '', $_GET);
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $fixture['path']), null, new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get', $_GET);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
session()->set(['user' => ['id' => 1, 'name' => 'BMG Fixture', 'username' => 'bmg-fixture', 'role' => 'superadmin', 'logged_in' => true], 'tenant_id' => 1]);
$variant = $_GET['variant'] ?? '';
$literal = '<img src=x onerror="window.bmgXss=1"><script>window.bmgXss=1</script>&"';
$unit = ['id' => 11, 'unit_code' => 'DRUM-11', 'drum_capacity_kg' => '275.50', 'name' => $variant === 'malicious' ? $literal : 'North drum', 'status' => $variant === 'maintenance' ? 'maintenance' : 'active'];
$schedule = ['version' => 3, 'enabled' => false, 'timezone' => 'Asia/Manila', 'times' => ['08:00', '16:00'], 'duration_seconds' => 60, 'pwm' => null, 'direction' => 'forward'];
$device = $variant === 'unbound' ? null : ['id' => 21, 'unit_id' => 11, 'chip_mac' => 'AA:BB:CC:DD:EE:FF', 'firmware_version' => $variant === 'malicious' ? $literal : '1.0', 'status' => 'active', 'last_seen_at' => null, 'rtc_valid' => null, 'applied_schedule_version' => null];
$device = $device === null ? null : $device + ['firmware_reported_at' => null, 'rtc_reported_at' => null, 'schedule_reported_at' => null];
if (in_array($variant, ['reported', 'stale', 'malicious', 'untimestamped'], true)) {
    $device = array_replace($device, ['last_seen_at' => '2026-10-17 10:00:00', 'rtc_valid' => false, 'applied_schedule_version' => 0]);
    if ($variant !== 'untimestamped') {
        $receivedAt = $variant === 'stale' ? '2026-10-17 08:00:00' : '2026-10-17 10:00:00';
        $device = array_replace($device, ['firmware_reported_at' => $receivedAt, 'rtc_reported_at' => $receivedAt, 'schedule_reported_at' => $receivedAt]);
    }
}
if ($variant === 'partial') {
    $device = array_replace($device, ['last_seen_at' => '2026-10-17 10:00:00', 'firmware_reported_at' => '2026-10-17 08:00:00', 'applied_schedule_version' => 0, 'schedule_reported_at' => '2026-10-17 10:00:00']);
}
if ($variant === 'zero-version') { $schedule['version'] = 0; }
$hardware_enabled = !in_array($variant, ['gate-off', 'detail-gate-off'], true);
$runs = [['occurred_at' => '2026-10-17 08:00:00', 'motor_state' => 'turned_normal', 'duration_seconds' => 60, 'created_at' => '2026-10-17 08:01:00']];
$commands = [['created_at' => '2026-10-17 07:59:00', 'command' => 'run_now', 'duration_seconds' => 60, 'status' => 'acknowledged', 'ack_status' => 'accepted', 'ack_message' => $variant === 'malicious' ? $literal : 'Accepted by device', 'acknowledged_at' => '2026-10-17 08:00:00', 'expires_at' => '2026-10-17 08:00:00']];
$detail = compact('unit', 'schedule', 'device', 'hardware_enabled', 'runs', 'commands');
$batch = ['id' => 31, 'batch_code' => 'BATCH-31', 'status' => 'processing', 'started_at' => '2026-10-17 07:00:00', 'total_input_weight_kg' => '120.000', 'output_weight_kg' => null, 'completed_at' => null, 'notes' => $variant === 'malicious' ? $literal : 'Operator notes'];
$data = ['title' => 'Mechanized drum rotation', 'canManage' => !in_array($variant, ['reader', 'config-only'], true), 'canConfigure' => !in_array($variant, ['reader', 'operator', 'operator-idle'], true), 'canHardware' => !in_array($variant, ['reader', 'no-hardware'], true), 'hardwareEnabled' => !in_array($variant, ['gate-off', 'controller-gate-off'], true)];
$kind = $fixture['kind'];
if ($kind === 'index') {
    $data['archivedView'] = ($_GET['view'] ?? '') === 'archived';
    $data['openCreate'] = ($_GET['create'] ?? '') === '1' && $data['canConfigure'];
    if ($data['archivedView']) {
        $detail['unit']['deleted_at'] = '2026-10-18 09:30:00';
        $detail['unit']['status'] = 'offline';
        $detail['schedule']['enabled'] = false;
        $detail['device'] = null;
    }
    $data['units'] = $variant === 'empty' ? [] : [$detail];
} elseif ($kind === 'unit_detail') {
    $data += $detail + ['activeBatch' => in_array($variant, ['idle', 'operator-idle'], true) ? null : $batch, 'pastBatches' => $variant === 'empty' ? [] : [array_replace($batch, ['status' => 'released', 'completed_at' => '2026-10-17 09:00:00'])]];
    if ($variant === 'empty') { $data['runs'] = []; $data['commands'] = []; }
} elseif ($kind === 'device_token') {
    $data['tokenResult'] = ['token' => $variant === 'malicious' ? $literal : 'fixture-secret-not-a-real-token', 'device_id' => 21, 'unit_id' => 11];
} else {
    throw new InvalidArgumentException('Unknown BMG fixture kind');
}
echo view('facilities/' . $kind, $data);
