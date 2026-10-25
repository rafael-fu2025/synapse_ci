<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuthenticatedUser;
use App\Services\Facilities\BmgBuildService;
use App\Services\Facilities\BmgFirmwareService;
use App\Services\Facilities\BmgRotationService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\BmgBuild;
use Config\BmgFirmware;
use Config\Database;
use ReflectionProperty;
use Throwable;

/** Private in-memory SQLite only: no migrations, operational database, compiler, or motor. */
final class BmgUnitCrudTest extends CIUnitTestCase
{
    private BaseConnection $unitDb;
    private array $connections;
    private BmgRotationService $units;
    private ?string $root = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) { $this->markTestSkipped('Disposable SQLite requires SQLite3.'); }
        $this->unitDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000, 'dateFormat' => [
                'date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s']], false);
        $property = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $property->getValue();
        $property->setValue(null, ['tests' => $this->unitDb]);
        $this->fixture();
        service('request')->removeHeader('Authorization');
        service('request')->removeHeader('X-Device-Token');
        AuthenticatedUser::clear();
        session()->set('_auth', ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]);
        session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'tenant_id' => 1, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['facilities.read', 'facilities.manage', 'facilities.configure']]]);
        $this->units = new BmgRotationService($this->unitDb);
    }

    protected function tearDown(): void
    {
        if (isset($this->unitDb)) {
            AuthenticatedUser::clear();
            service('request')->removeHeader('Authorization');
            service('request')->removeHeader('X-Device-Token');
            $this->unitDb->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        }
        if ($this->root !== null) {
            foreach (glob($this->root . '/*/*') ?: [] as $file) { unlink($file); }
            foreach (glob($this->root . '/*', GLOB_ONLYDIR) ?: [] as $dir) { rmdir($dir); }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testCreateReadUpdateArchiveRestoreKeepsHistoryAndCredentialsRetired(): void
    {
        $detail = $this->units->saveUnit(null, $this->input());
        $id = (int) $detail['unit']['id'];
        $this->assertSame('125.50', $detail['unit']['drum_capacity_kg']);
        $this->assertSame(1, $detail['schedule']['version']);
        $this->assertCount(1, $this->units->overview());
        $this->assertSame($detail, $this->units->unit($id));
        $detail = $this->units->saveUnit($id, array_replace($this->input(), ['name' => 'Renamed', 'expected_version' => 1]));
        $this->assertSame('Renamed', $detail['unit']['name']);
        $device = $this->units->registerDevice($id, ['chip_mac' => 'aa:bb:cc:dd:ee:01', 'expected_version' => 2]);
        $this->assertSame(3, $device['schedule_version']);
        $this->assertFalse($this->units->deviceSchedule($device['token'])['enabled']);
        $this->history($id, (int) $device['device_id']);
        $history = $this->historySnapshot();
        // Include an anomalous second binding: archive must revoke all, not just boundDevice().
        $this->unitDb->table('bmg_devices')->insert(['tenant_id' => 1, 'unit_id' => $id, 'status' => 'inactive',
            'device_token_hash' => hash('sha256', 'other-private-token')]);
        $this->unitDb->table('bmg_rotation_schedules')->where('unit_id', $id)->update(['enabled' => 1, 'pwm' => 100]);
        $flags = [env('BMG_HARDWARE_ENABLED'), env('BMG_USB_FLASH_ENABLED')];
        $result = $this->units->archiveUnit($id, ['expected_version' => '3', 'retirement_confirmed' => '1']);
        $this->assertSame(['unit_id' => $id, 'schedule_version' => 4], $result);
        $this->assertSame([], $this->units->overview());
        $archived = $this->units->overview(true);
        $this->assertCount(1, $archived);
        $this->assertNotNull($archived[0]['unit']['deleted_at']);
        $this->assertSame('offline', $archived[0]['unit']['status']);
        $this->assertFalse($archived[0]['schedule']['enabled']);
        $this->assertNull($archived[0]['schedule']['pwm']);
        $this->assertSame($history, $this->historySnapshot());
        $this->assertRetiredDevices(2);
        $this->assertSame('cancelled', $this->row('bmg_rotation_commands', 1)['status']);
        $this->assertSame('completed', $this->row('bmg_rotation_commands', 2)['status']);
        $this->assertSame(2, $this->unitDb->table('bmg_rotation_commands')->countAllResults());
        $this->reject(fn () => $this->units->unit($id), 404);
        $this->reject(fn () => $this->units->archiveUnit($id, ['expected_version' => 4, 'retirement_confirmed' => true]), 404);
        $this->reject(fn () => $this->units->saveUnit($id, $this->input() + ['expected_version' => 4]), 404);
        $this->reject(fn () => $this->units->deviceSchedule($device['token']), 401);
        $this->reject(fn () => $this->units->heartbeat($device['token'], []), 401);
        $this->reject(fn () => $this->units->pendingCommands($device['token']), 401);
        $this->reject(fn () => $this->units->saveUnit(null, $this->input()), 409);
        $detail = $this->units->restoreUnit($id, ['expected_version' => 4]);
        $this->assertSame('offline', $detail['unit']['status']);
        $this->assertNull($detail['unit']['deleted_at']);
        $this->assertNull($detail['device']);
        $this->assertFalse($detail['schedule']['enabled']);
        $this->assertSame(5, $detail['schedule']['version']);
        $this->assertSame([], $this->units->overview(true));
        $this->assertCount(1, $this->units->overview());
        $this->assertRetiredDevices(2);
        $this->assertSame($history, $this->historySnapshot());
        $this->reject(fn () => $this->units->deviceSchedule($device['token']), 401);
        $this->reject(fn () => $this->units->restoreUnit($id, ['expected_version' => 5]), 404);
        $this->assertSame($flags, [env('BMG_HARDWARE_ENABLED'), env('BMG_USB_FLASH_ENABLED')]);
        $events = $this->unitDb->table('audit_events')->whereIn('action', ['archive', 'restore'])->orderBy('id')->get()->getResultArray();
        $this->assertSame(['archive', 'restore'], array_column($events, 'action'));
        foreach ($events as $event) {
            $this->assertSame('bmg_unit', $event['entity_type']);
            $this->assertStringNotContainsString($device['token'], $event['payload_json']);
            $this->assertStringNotContainsString('token', $event['payload_json']);
        }
    }

    public function testStrictValidationAndStaleVersionsHaveNoSideEffects(): void
    {
        $id = (int) $this->units->saveUnit(null, $this->input())['unit']['id'];
        foreach ([null, false, 0, 1, '0', 'true', [], 1.0] as $ack) {
            $this->unchanged(fn () => $this->units->archiveUnit($id, ['expected_version' => 1, 'retirement_confirmed' => $ack]), 0);
        }
        $this->unchanged(fn () => $this->units->archiveUnit($id, ['expected_version' => 1]), 0);
        foreach ([null, true, 1.0, '1e0', -1] as $version) {
            $this->unchanged(fn () => $this->units->archiveUnit($id, ['expected_version' => $version, 'retirement_confirmed' => true]), 0);
        }
        $this->unchanged(fn () => $this->units->archiveUnit($id, ['retirement_confirmed' => true]), 0);
        $this->unchanged(fn () => $this->units->archiveUnit($id, ['expected_version' => 0, 'retirement_confirmed' => true]), 409);
        $this->unchanged(fn () => $this->units->archiveUnit($id, ['expected_version' => 1, 'retirement_confirmed' => true, 'status' => 'offline']), 0);
        $this->unchanged(fn () => $this->units->saveUnit($id, $this->input() + ['expected_version' => 0]), 409);
        $this->units->archiveUnit($id, ['expected_version' => 1, 'retirement_confirmed' => true]);
        $this->unchanged(fn () => $this->units->restoreUnit($id, []), 0);
        $this->unchanged(fn () => $this->units->restoreUnit($id, ['expected_version' => 1]), 409);
        $this->unchanged(fn () => $this->units->restoreUnit($id, ['expected_version' => 2, 'enabled' => true]), 0);
        // Simulate legacy duplicate data without weakening the restore safeguard.
        $this->unitDb->table('bmg_units')->insert(['tenant_id' => 1, 'unit_code' => 'DRUM-1', 'status' => 'offline']);
        $this->unchanged(fn () => $this->units->restoreUnit($id, ['expected_version' => 2]), 409);
    }

    public function testEveryActiveBatchStatusBlocksArchiveBeforeWrites(): void
    {
        $id = (int) $this->units->saveUnit(null, $this->input())['unit']['id'];
        foreach (['processing', 'awaiting_output', 'curing'] as $status) {
            $this->unitDb->table('bmg_batches')->insert(['tenant_id' => 1, 'unit_id' => $id, 'status' => $status]);
            $this->unchanged(fn () => $this->units->archiveUnit($id, ['expected_version' => 1, 'retirement_confirmed' => true]), 409);
            $this->unitDb->table('bmg_batches')->where('tenant_id', 1)->update(['status' => 'completed']);
        }
        $this->unitDb->table('bmg_batches')->insert(['tenant_id' => 2, 'unit_id' => $id, 'status' => 'processing']);
        $this->assertSame(2, $this->units->archiveUnit($id, ['expected_version' => 1, 'retirement_confirmed' => true])['schedule_version']);
    }

    public function testFreshPermissionsTenantAndHumanSessionBarriers(): void
    {
        $id = (int) $this->units->saveUnit(null, $this->input())['unit']['id'];
        $this->unitDb->table('bmg_units')->insert(['id' => 20, 'tenant_id' => 2, 'unit_code' => 'OTHER', 'status' => 'offline']);
        $this->unchanged(fn () => $this->units->archiveUnit(20, ['expected_version' => 0, 'retirement_confirmed' => true]), 404);
        $this->unitDb->table('bmg_units')->where('id', 20)->update(['deleted_at' => '2026-01-01 00:00:00']);
        $this->unchanged(fn () => $this->units->restoreUnit(20, ['expected_version' => 0]), 404);
        $this->assertSame([], $this->units->overview(true));
        foreach ([2, 3] as $permission) {
            // Cached session still claims all permissions: only persisted grants count.
            $this->unitDb->table('role_permissions')->where('permission_id', $permission)->delete();
            $this->assertCount(1, $this->units->overview());
            $this->unchanged(fn () => $this->units->overview(true), 403);
            $this->unchanged(fn () => $this->units->archiveUnit($id, ['expected_version' => 1, 'retirement_confirmed' => true]), 403);
            $this->unchanged(fn () => $this->units->restoreUnit(20, ['expected_version' => 0]), 403);
            $this->unchanged(fn () => $this->units->saveUnit(null, $this->input()), 403);
            $this->unitDb->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => $permission]);
        }
        foreach (['Authorization', 'X-Device-Token'] as $header) {
            service('request')->setHeader($header, 'private-token');
            $this->unchanged(fn () => $this->units->archiveUnit($id, ['expected_version' => 1, 'retirement_confirmed' => true]), 403);
            $this->reject(fn () => $this->units->overview(true), 403);
            service('request')->removeHeader($header);
        }
        $this->unitDb->table('users')->where('id', 1)->update(['status' => 'inactive']);
        $this->unchanged(fn () => $this->units->archiveUnit($id, ['expected_version' => 1, 'retirement_confirmed' => true]), 401);
    }

    public function testArchiveAndRestoreAuditFailureRollBackAllState(): void
    {
        $id = (int) $this->units->saveUnit(null, $this->input())['unit']['id'];
        $device = $this->units->registerDevice($id, ['chip_mac' => 'aa:bb:cc:dd:ee:01', 'expected_version' => 1]);
        $this->history($id, (int) $device['device_id']);
        $this->unitDb->query("CREATE TRIGGER fail_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'test audit failure'); END");
        $this->unchanged(fn () => $this->units->archiveUnit($id, ['expected_version' => 2, 'retirement_confirmed' => true]), 503);
        $this->unitDb->query('DROP TRIGGER fail_audit');
        $this->unitDb->resetTransStatus();
        $this->units->archiveUnit($id, ['expected_version' => 2, 'retirement_confirmed' => true]);
        // Unexpected legacy bindings and pending commands must also be retired on restore.
        $this->unitDb->table('bmg_devices')->where('id', $device['device_id'])->update(['unit_id' => $id, 'status' => 'active', 'revoked_at' => null]);
        $this->unitDb->table('bmg_rotation_commands')->where('id', 1)->update(['status' => 'pending']);
        $this->unitDb->query("CREATE TRIGGER fail_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'test audit failure'); END");
        $this->unchanged(fn () => $this->units->restoreUnit($id, ['expected_version' => 3]), 503);
        $this->unitDb->query('DROP TRIGGER fail_audit');
        $this->unitDb->resetTransStatus();
        $this->assertSame(4, $this->units->restoreUnit($id, ['expected_version' => 3])['schedule']['version']);
        $this->assertRetiredDevices(1);
        $this->assertSame('cancelled', $this->row('bmg_rotation_commands', 1)['status']);
    }

    public function testHistoricalUnitsWithoutScheduleUseVersionZero(): void
    {
        $this->unitDb->table('bmg_units')->insert(['id' => 1, 'tenant_id' => 1, 'unit_code' => 'OLD', 'status' => 'offline']);
        $this->unchanged(fn () => $this->units->archiveUnit(1, ['expected_version' => 1, 'retirement_confirmed' => true]), 409);
        $this->assertSame(1, $this->units->archiveUnit(1, ['expected_version' => 0, 'retirement_confirmed' => true])['schedule_version']);
        $this->unitDb->table('bmg_rotation_schedules')->where('unit_id', 1)->delete();
        $this->unchanged(fn () => $this->units->restoreUnit(1, ['expected_version' => 1]), 409);
        $this->assertSame(1, $this->units->restoreUnit(1, ['expected_version' => '0'])['schedule']['version']);
    }

    public function testPreviouslyDownloadablePrivateBuildLosesItsBindingAfterArchiveAndRestore(): void
    {
        $id = (int) $this->units->saveUnit(null, $this->input())['unit']['id'];
        $device = $this->units->registerDevice($id, ['chip_mac' => 'aa:bb:cc:dd:ee:01', 'expected_version' => 1]);
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/bmg-crud-' . bin2hex(random_bytes(8));
        $releaseId = 'build-' . str_repeat('a', 32);
        mkdir($this->root . '/' . $releaseId, 0700, true);
        $this->root = str_replace('\\', '/', realpath($this->root));
        $binary = 'private-test-binary';
        file_put_contents($this->root . '/' . $releaseId . '/part-0.bin', $binary);
        $config = new BmgBuild();
        $config->signingKey = str_repeat('test-only-key-', 4);
        $release = ['id' => $releaseId, 'name' => 'Private test build', 'chip_family' => 'ESP32-S3', 'flash_size' => '16MB',
            'parts' => [['name' => 'Application', 'filename' => 'part-0.bin', 'address' => 65536, 'sha256' => hash('sha256', $binary)]]];
        // Signed fixture mirrors the successful builder output; no compiler execution needed.
        $payload = json_encode(['actor' => 1, 'tenant' => 1, 'unit' => $id, 'device' => (int) $device['device_id'],
            'token_hash' => hash('sha256', $device['token']), 'schedule_version' => 2,
            'source_hash' => hash('sha256', json_encode(BmgBuild::SOURCES, JSON_THROW_ON_ERROR)),
            'created' => time(), 'expires' => time() + BmgBuild::TTL, 'release' => $release], JSON_UNESCAPED_SLASHES);
        file_put_contents($this->root . '/' . $releaseId . '/manifest.json', json_encode([
            'payload' => $payload, 'signature' => hash_hmac('sha256', $payload, $config->signingKey)]));
        $builds = new BmgBuildService($this->unitDb, $config, $this->root);
        $firmware = new BmgFirmwareService($this->unitDb, new BmgFirmware(), $this->root, $builds);
        $this->assertSame($binary, $firmware->part($id, $releaseId, 0)['body']);
        $actor = (new AuthenticatedUser())->current();
        $this->units->archiveUnit($id, ['expected_version' => 2, 'retirement_confirmed' => true]);
        $this->reject(fn () => $builds->privateRelease($id, $releaseId, $actor), 404);
        $this->reject(fn () => $firmware->part($id, $releaseId, 0), 404);
        $this->units->restoreUnit($id, ['expected_version' => 3]);
        $this->reject(fn () => $firmware->part($id, $releaseId, 0), 409);
        $this->assertFileExists($this->root . '/' . $releaseId . '/part-0.bin');
    }

    private function input(): array
    {
        return ['unit_code' => 'DRUM-1', 'name' => 'Compost drum', 'status' => 'maintenance', 'drum_capacity_kg' => '125.50'];
    }

    private function reject(callable $operation, int $code): void
    {
        try { $operation(); } catch (Throwable $error) {
            $this->assertSame($code, $error->getCode(), $error->getMessage());
            if ($code === 0) { $this->assertInstanceOf(\InvalidArgumentException::class, $error); }
            return;
        }
        $this->fail('Expected rejection: ' . $code);
    }

    private function unchanged(callable $operation, int $code): void
    {
        $before = $this->snapshot();
        $this->reject($operation, $code);
        $this->assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['bmg_units', 'bmg_devices', 'bmg_rotation_schedules', 'bmg_rotation_commands', 'audit_events',
            'bmg_batches', 'bmg_batch_inputs', 'bmg_batch_outputs', 'bmg_rotation_runs'] as $table) {
            $result[$table] = $this->unitDb->table($table)->orderBy('id')->get()->getResultArray();
        }
        return $result;
    }

    private function historySnapshot(): array
    {
        return array_intersect_key($this->snapshot(), array_flip(['bmg_batches', 'bmg_batch_inputs', 'bmg_batch_outputs', 'bmg_rotation_runs']));
    }

    private function row(string $table, int $id): array
    {
        return $this->unitDb->table($table)->where('id', $id)->get()->getRowArray();
    }

    private function assertRetiredDevices(int $count): void
    {
        $devices = $this->unitDb->table('bmg_devices')->get()->getResultArray();
        $this->assertCount($count, $devices);
        foreach ($devices as $device) {
            $this->assertSame('revoked', $device['status']);
            $this->assertNull($device['unit_id']);
            $this->assertNotNull($device['revoked_at']);
            $this->assertSame($device['revoked_at'], $device['updated_at']);
        }
    }

    private function history(int $unit, int $device): void
    {
        $this->unitDb->table('bmg_batches')->insert(['id' => 1, 'tenant_id' => 1, 'unit_id' => $unit, 'status' => 'completed', 'total_input_weight_kg' => '20.00']);
        $this->unitDb->table('bmg_batch_inputs')->insert(['id' => 1, 'batch_id' => 1, 'weight_kg' => '20.00']);
        $this->unitDb->table('bmg_batch_outputs')->insert(['id' => 1, 'batch_id' => 1, 'weight_kg' => '10.00']);
        $this->unitDb->table('bmg_rotation_runs')->insert(['id' => 1, 'tenant_id' => 1, 'unit_id' => $unit, 'device_id' => $device, 'run_uid' => 'historical-run']);
        foreach (['pending', 'completed'] as $status) {
            $this->unitDb->table('bmg_rotation_commands')->insert(['tenant_id' => 1, 'unit_id' => $unit, 'device_id' => $device,
                'command' => 'run_now', 'status' => $status, 'expires_at' => '2099-01-01 00:00:00']);
        }
    }

    private function fixture(): void
    {
        // Expanded from the build/firmware private SQLite fixtures to cover unit writes and history.
        foreach ([
            'CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)',
            'CREATE TABLE users (auth_version INTEGER NOT NULL DEFAULT 1, id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, role TEXT, username TEXT, email TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY, code TEXT)',
            'CREATE TABLE role_permissions (role TEXT, permission_id INTEGER)',
            'CREATE TABLE bmg_units (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_code TEXT, name TEXT, status TEXT, drum_capacity_kg TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE bmg_devices (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, chip_mac TEXT, device_token_hash TEXT, status TEXT, revoked_at TEXT, rotation_bound_at TEXT, reported_firmware_version TEXT, rtc_valid INTEGER, applied_schedule_version INTEGER, last_seen_at TEXT, firmware_reported_at TEXT, rtc_reported_at TEXT, schedule_reported_at TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE bmg_rotation_schedules (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, enabled INTEGER, version INTEGER, times_json TEXT, duration_seconds INTEGER, pwm INTEGER, updated_by_user_id INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE bmg_rotation_runs (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER REFERENCES bmg_units(id), device_id INTEGER REFERENCES bmg_devices(id), run_uid TEXT)',
            'CREATE TABLE bmg_rotation_commands (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER REFERENCES bmg_units(id), device_id INTEGER REFERENCES bmg_devices(id), command TEXT, status TEXT, expires_at TEXT)',
            'CREATE TABLE bmg_batches (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER REFERENCES bmg_units(id), status TEXT, total_input_weight_kg TEXT)',
            'CREATE TABLE bmg_batch_inputs (id INTEGER PRIMARY KEY, batch_id INTEGER REFERENCES bmg_batches(id), weight_kg TEXT)',
            'CREATE TABLE bmg_batch_outputs (id INTEGER PRIMARY KEY, batch_id INTEGER REFERENCES bmg_batches(id), weight_kg TEXT)',
            'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, event_type TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT NOT NULL, payload_json TEXT, prev_hash TEXT NOT NULL, curr_hash TEXT NOT NULL, hash_version INTEGER NOT NULL DEFAULT 1, occurred_at TEXT NOT NULL)',
            'INSERT INTO tenants (id, is_active) VALUES (1, 1), (2, 1)',
            "INSERT INTO users (id, tenant_id, role, username, status) VALUES (1, 1, 'superadmin', 'configurator', 'active')",
            "INSERT INTO permissions VALUES (1, 'facilities.read'), (2, 'facilities.manage'), (3, 'facilities.configure')",
            "INSERT INTO role_permissions VALUES ('superadmin', 1), ('superadmin', 2), ('superadmin', 3)",
        ] as $sql) { $this->unitDb->query($sql); }
    }
}
