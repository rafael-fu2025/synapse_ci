<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Api\BmgDeviceApiController;
use App\Database\Migrations\CreateBmgRotationOperations;
use App\Services\AuthenticatedUser;
use App\Services\Audit\AuditService;
use App\Models\AuditEventModel;
use Config\Services;
use App\Services\Facilities\BmgRotationService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use InvalidArgumentException;
use ReflectionProperty;
use Throwable;

/** Entirely disposable in-memory fixtures: never connects to or migrates configured MySQL. */
final class BmgRotationWorkflowTest extends CIUnitTestCase
{
    private BaseConnection $rotationDb;
    private BmgRotationService $rotation;
    private array $connections;
    private mixed $hardwareEnv;
    private mixed $hardwareServer;
    private string|false $hardwareProcess;
    private int $unitId;
    private string $token;
    private int $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) { $this->markTestSkipped('SQLite3 is required for isolated rotation fixtures.'); }
        $this->hardwareEnv = $_ENV['BMG_HARDWARE_ENABLED'] ?? null;
        $this->hardwareServer = $_SERVER['BMG_HARDWARE_ENABLED'] ?? null;
        $this->hardwareProcess = getenv('BMG_HARDWARE_ENABLED');
        $this->gate(false);
        service('request')->removeHeader('X-Device-Token');
        service('request')->removeHeader('Authorization');
        Services::injectMock('throttler', new \CodeIgniter\Throttle\Throttler(new \CodeIgniter\Cache\Handlers\DummyHandler(config('Cache'))));
        $this->rotationDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000, 'dateFormat' => [
                'date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s']], false);
        $property = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $property->getValue();
        $property->setValue(null, ['tests' => $this->rotationDb]);
        $this->fixture();
        require_once APPPATH . 'Database/Migrations/2026-10-17-000005_CreateBmgRotationOperations.php';
        (new CreateBmgRotationOperations(Database::forge($this->rotationDb)))->up();
        $this->rotation = new BmgRotationService($this->rotationDb);
        $this->signIn();
        $this->unitId = (int) $this->rotation->saveUnit(null, ['unit_code' => 'drum-test', 'name' => 'Test drum', 'status' => 'active', 'drum_capacity_kg' => '80.00'])['unit']['id'];
        $this->gate(true);
        $device = $this->register($this->unitId, ['chip_mac' => 'aa:bb:cc:dd:ee:01']);
        $this->gate(false);
        $this->token = $device['token'];
        $this->deviceId = $device['device_id'];
        // Simulate a binding established yesterday; no sleeps or future device facts.
        $this->rotationDb->table('bmg_devices')->where('id', $this->deviceId)
            ->update(['rotation_bound_at' => $this->yesterday()]);
    }

    protected function tearDown(): void
    {
        if (isset($this->rotationDb)) {
            AuthenticatedUser::clear();
            service('request')->removeHeader('X-Device-Token');
            service('request')->removeHeader('Authorization');
            Services::resetSingle('throttler');
            $this->rotationDb->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
            foreach (['_ENV' => $this->hardwareEnv, '_SERVER' => $this->hardwareServer] as $global => $value) {
                if ($value === null) { unset($GLOBALS[$global]['BMG_HARDWARE_ENABLED']); }
                else { $GLOBALS[$global]['BMG_HARDWARE_ENABLED'] = $value; }
            }
            putenv($this->hardwareProcess === false ? 'BMG_HARDWARE_ENABLED' : 'BMG_HARDWARE_ENABLED=' . $this->hardwareProcess);
        }
        parent::tearDown();
    }

    public function testDisabledDefaultsAndHashOnlyCredentials(): void
    {
        $schedule = $this->rotation->deviceSchedule($this->token);
        $this->assertFalse($schedule['enabled']);
        $this->assertSame('Asia/Manila', $schedule['timezone']);
        $this->assertSame([], $schedule['times']);
        $stored = $this->rotationDb->table('bmg_devices')->where('id', $this->deviceId)->get()->getRowArray();
        $this->assertSame(hash('sha256', $this->token), $stored['device_token_hash']);
        $this->assertStringNotContainsString($this->token, json_encode($this->rotation->overview()));
        $this->assertNull($this->rotation->unit($this->unitId)['device']['firmware_version']);
    }

    public function testHardwareGateAndSeparateDatabasePermission(): void
    {
        $this->reject(fn () => $this->saveSchedule($this->unitId, $this->schedule()), 409);
        $this->reject(fn () => $this->command($this->unitId, ['command' => 'stop']), 409);
        $this->reject(fn () => $this->saveSchedule($this->unitId, ['pwm' => 100]), 409);
        $this->gate(true);
        $this->rotationDb->table('role_permissions')->where('permission_id', 3)->delete();
        $this->reject(fn () => $this->saveSchedule($this->unitId, $this->schedule()), 403);
        $this->reject(fn () => $this->command($this->unitId, ['command' => 'run_now']), 403);
        $this->assertFalse($this->saveSchedule($this->unitId, [])['enabled']);
    }

    public function testForgedSessionCannotSupplyPermissionOrTenant(): void
    {
        $this->rotationDb->table('role_permissions')->where('permission_id', 2)->delete();
        $this->signIn(); // forged cached permissions do not restore the DB grant
        $this->reject(fn () => $this->rotation->saveUnit(null, ['unit_code' => 'forged', 'name' => 'Forged']), 403);
        $this->rotationDb->table('users')->where('id', 1)->update(['status' => 'suspended']);
        $this->reject(fn () => $this->rotation->overview(), 401);
    }

    public function testTenantIsolationForAllHumanIdentifiersAndDeviceSessionIndependence(): void
    {
        $this->rotationDb->table('bmg_units')->insert(['id' => 900, 'tenant_id' => 2, 'unit_code' => 'foreign', 'name' => 'Foreign', 'status' => 'active', 'drum_capacity_kg' => '80.00']);
        $this->rotationDb->table('bmg_devices')->insert(['id' => 900, 'tenant_id' => 2, 'unit_id' => 900, 'chip_mac' => 'aa:bb:cc:dd:ee:99',
            'device_token_hash' => hash('sha256', str_repeat('f', 64)), 'status' => 'active']);
        $this->reject(fn () => $this->rotation->unit(900), 404);
        $this->reject(fn () => $this->saveSchedule(900, []), 404);
        $this->reject(fn () => $this->rotation->revokeDevice(900, 0), 404);
        $this->gate(true);
        $this->reject(fn () => $this->register(900, ['chip_mac' => 'aa:bb:cc:dd:ee:03']), 404);
        $this->assertCount(1, $this->rotation->overview());
        session()->set('tenant_id', 2);
        $this->assertFalse($this->rotation->deviceSchedule($this->token)['enabled']);
    }

    public function testPublishedAndAppliedVersionsRemainIndependent(): void
    {
        $this->gate(true);
        $published = $this->saveSchedule($this->unitId, $this->schedule() + ['pwm' => 120]);
        $this->assertTrue($published['enabled']);
        $this->rotation->heartbeat($this->token, ['firmware_version' => '2.1.0', 'rtc_valid' => false, 'applied_schedule_version' => 0]);
        $view = $this->rotation->unit($this->unitId);
        $this->assertSame(0, $view['device']['applied_schedule_version']);
        $this->assertFalse($view['device']['rtc_valid']);
        $this->assertSame('2.1.0', $view['device']['firmware_version']);
        $this->assertGreaterThan(0, $view['schedule']['version']);
        $this->rotation->heartbeat($this->token, []);
        $this->assertSame(0, $this->rotation->unit($this->unitId)['device']['applied_schedule_version']);
        $this->rotation->heartbeat($this->token, ['applied_schedule_version' => $published['version']]);
        $this->assertSame($published['version'], $this->rotation->unit($this->unitId)['device']['applied_schedule_version']);
        $this->invalid(fn () => $this->rotation->heartbeat($this->token, ['applied_schedule_version' => $published['version'] + 1]));
        $this->gate(false);
        $effective = $this->rotation->deviceSchedule($this->token);
        $this->assertFalse($effective['enabled']);
        $this->assertTrue($effective['published_enabled']);
        $this->assertNull($effective['pwm']);
    }

    public function testStrictScheduleValidation(): void
    {
        foreach ([['times' => array_fill(0, 9, '08:00')], ['times' => ['24:00']], ['times' => ['8:00']],
            ['times' => ['08:00', '08:00']], ['times' => ['23:59', '00:00'], 'duration_seconds' => 120],
            ['duration_seconds' => 0], ['duration_seconds' => 601], ['duration_seconds' => 1.5],
            ['enabled' => 'false'], ['tenant_id' => 2], ['pwm' => 256], ['times' => '08:00']] as $bad) {
            $this->invalid(fn () => $this->saveSchedule($this->unitId, $bad));
        }
        $this->invalid(fn () => $this->rotation->heartbeat($this->token, ['motor_ok' => true]));
        $this->invalid(fn () => $this->rotation->saveUnit(null, ['unit_code' => 'x', 'name' => 'X', 'actor_id' => 1]));
    }

    public function testUnboundDeviceCannotEnableOrIngestAndRevocationInvalidatesToken(): void
    {
        $this->rotationDb->table('bmg_devices')->where('id', $this->deviceId)->update(['unit_id' => null]);
        $this->gate(true);
        $this->assertFalse($this->rotation->deviceSchedule($this->token)['enabled']);
        $this->reject(fn () => $this->saveSchedule($this->unitId, $this->schedule()), 409);
        $this->reject(fn () => $this->rotation->ingestRun($this->token, $this->runPayload()), 409);
        $this->assertSame([], $this->rotation->pendingCommands($this->token)['commands']);
        $this->rotation->revokeDevice($this->deviceId, $this->version($this->unitId));
        $this->reject(fn () => $this->rotation->heartbeat($this->token, []), 401);
    }

    public function testCredentialRotationDisablesScheduleAndCancelsCommands(): void
    {
        $this->gate(true);
        $this->saveSchedule($this->unitId, $this->schedule());
        $command = $this->command($this->unitId, ['command' => 'run_now']);
        $replacement = $this->register($this->unitId, ['device_id' => $this->deviceId, 'chip_mac' => 'aa:bb:cc:dd:ee:01']);
        $this->reject(fn () => $this->rotation->deviceSchedule($this->token), 401);
        $this->assertFalse($this->rotation->deviceSchedule($replacement['token'])['enabled']);
        $this->assertSame('cancelled', $this->rotationDb->table('bmg_rotation_commands')->where('id', $command['command_id'])->get()->getRowArray()['status']);
        $this->assertNull($this->rotation->unit($this->unitId)['device']['applied_schedule_version']);
    }

    public function testRunIdempotencyIsDeviceSpecificAndConflictsAreRejected(): void
    {
        $this->assertSame('created', $this->rotation->ingestRun($this->token, $this->runPayload())['status']);
        $this->assertSame('acknowledged', $this->rotation->ingestRun($this->token, $this->runPayload())['status']);
        $different = $this->runPayload();
        $different['duration_seconds'] = 59;
        $this->reject(fn () => $this->rotation->ingestRun($this->token, $different), 409);
        $this->gate(true);
        $otherUnit = $this->rotation->saveUnit(null, ['unit_code' => 'second', 'name' => 'Second', 'status' => 'active', 'drum_capacity_kg' => '80.00']);
        $other = $this->register((int) $otherUnit['unit']['id'], ['chip_mac' => 'aa:bb:cc:dd:ee:02']);
        $this->rotationDb->table('bmg_devices')->where('id', $other['device_id'])->update(['rotation_bound_at' => $this->yesterday()]);
        $this->assertSame('created', $this->rotation->ingestRun($other['token'], $this->runPayload())['status']);
        $this->assertSame(2, $this->rotationDb->table('bmg_rotation_runs')->countAllResults());
        $this->assertSame(0, $this->rotationDb->table('bmg_device_runs')->countAllResults());
    }

    public function testRunValidationDoesNotInventFacts(): void
    {
        foreach ([[], ['duration_seconds' => -1], ['occurred_at' => '2026-02-30 10:00:00'], ['motor_state' => 'okay'],
            ['occurred_at' => '2099-01-01 00:00:00'], ['duration_seconds' => true], ['run_uid' => '../x']] as $bad) {
            $this->invalid(fn () => $this->rotation->ingestRun($this->token, $bad === [] ? [] : array_replace($this->runPayload(), $bad)));
        }
    }

    public function testCommandAcknowledgementIsScopedAndIdempotent(): void
    {
        $this->gate(true);
        $command = $this->command($this->unitId, ['command' => 'run_now', 'duration_seconds' => 30]);
        $this->assertCount(1, $this->rotation->pendingCommands($this->token)['commands']);
        $this->reject(fn () => $this->command($this->unitId, ['command' => 'run_now']), 409);
        $otherUnit = $this->rotation->saveUnit(null, ['unit_code' => 'second', 'name' => 'Second', 'status' => 'active', 'drum_capacity_kg' => '80.00']);
        $other = $this->register((int) $otherUnit['unit']['id'], ['chip_mac' => 'aa:bb:cc:dd:ee:02']);
        $ack = ['command_id' => $command['command_id'], 'status' => 'completed'];
        $this->reject(fn () => $this->rotation->acknowledgeCommand($other['token'], $ack), 404);
        $this->assertSame('acknowledged', $this->rotation->acknowledgeCommand($this->token, $ack)['status']);
        $this->assertSame('acknowledged', $this->rotation->acknowledgeCommand($this->token, $ack)['status']);
        $ack['status'] = 'failed';
        $this->reject(fn () => $this->rotation->acknowledgeCommand($this->token, $ack), 409);
        $this->assertSame([], $this->rotation->pendingCommands($this->token)['commands']);
    }

    public function testStopCancelsDelayedRunAndCommandsExpireOrCancelWithGate(): void
    {
        $this->gate(true);
        $run = $this->command($this->unitId, ['command' => 'run_now']);
        $stop = $this->command($this->unitId, ['command' => 'stop']);
        $pending = $this->rotation->pendingCommands($this->token)['commands'];
        $this->assertCount(1, $pending);
        $this->assertSame('stop', $pending[0]['command']);
        $this->reject(fn () => $this->rotation->acknowledgeCommand($this->token, ['command_id' => $run['command_id'], 'status' => 'completed']), 409);
        $this->rotationDb->table('bmg_rotation_commands')->where('id', $stop['command_id'])->update(['expires_at' => '2000-01-01 00:00:00']);
        $this->assertSame([], $this->rotation->pendingCommands($this->token)['commands']);
        $this->reject(fn () => $this->rotation->acknowledgeCommand($this->token, ['command_id' => $stop['command_id'], 'status' => 'completed']), 409);
        $this->command($this->unitId, ['command' => 'run_now']);
        $this->gate(false);
        $this->assertSame([], $this->rotation->pendingCommands($this->token)['commands']);
        $this->gate(true);
        $this->assertSame([], $this->rotation->pendingCommands($this->token)['commands']);
    }

    public function testApiRejectsMalformedJsonAndDoesNotExposeDatabaseExceptions(): void
    {
        $request = service('request');
        $request->setHeader('X-Device-Token', $this->token);
        $request->setHeader('Content-Type', 'application/json');
        $request->setBody('{bad json');
        $controller = new BmgDeviceApiController();
        $controller->initController($request, service('response'), service('logger'));
        $this->assertSame(400, $controller->heartbeat()->getStatusCode());
        $request->setBody('[]');
        $this->assertSame(400, $controller->heartbeat()->getStatusCode());
        $request->setBody(str_repeat('x', 8193));
        $this->assertSame(413, $controller->heartbeat()->getStatusCode());
        $request->removeHeader('X-Device-Token');
        $this->assertSame(401, $controller->schedule()->getStatusCode());
    }

    public function testApiUsesPersistedScheduleAndCreatedThenReplayStatus(): void
    {
        $request = service('request');
        $request->setHeader('X-Device-Token', $this->token);
        $request->setHeader('Content-Type', 'application/json');
        $controller = new BmgDeviceApiController();
        $controller->initController($request, service('response'), service('logger'));
        $response = $controller->schedule();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertFalse(json_decode($response->getBody(), true)['enabled']);
        $request->setBody(json_encode($this->runPayload()));
        $this->assertSame(201, $controller->runs()->getStatusCode());
        $this->assertSame(200, $controller->runs()->getStatusCode());
        $this->rotationDb->table('tenants')->where('id', 1)->update(['is_active' => 0]);
        $this->assertSame(401, $controller->schedule()->getStatusCode());
    }

    public function testApiHidesStorageFailureDetails(): void
    {
        $request = service('request');
        $request->setHeader('X-Device-Token', $this->token);
        $controller = new BmgDeviceApiController();
        $controller->initController($request, service('response'), service('logger'));
        $this->rotationDb->query('DROP TABLE bmg_rotation_schedules');
        $response = $controller->schedule();
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['error' => 'Rotation service unavailable.'], json_decode($response->getBody(), true));
    }

    public function testLegacyHistorySurvivesMigrationRollback(): void
    {
        $this->rotationDb->table('bmg_device_runs')->insert(['run_uid' => 'legacy-run']);
        (new CreateBmgRotationOperations(Database::forge($this->rotationDb)))->down();
        $this->assertSame('legacy-run', $this->rotationDb->table('bmg_device_runs')->get()->getRowArray()['run_uid']);
        $this->assertTrue($this->rotationDb->tableExists('bmg_process_logs'));
        $this->assertFalse($this->rotationDb->fieldExists('rtc_valid', 'bmg_devices'));
    }

    public function testScheduleEditsRequireAndConsumeExpectedVersion(): void
    {
        $this->gate(true);
        $this->invalid(fn () => $this->rotation->saveSchedule($this->unitId, $this->schedule()));
        $version = $this->version($this->unitId);
        $data = $this->schedule() + ['expected_version' => $version];
        $saved = $this->rotation->saveSchedule($this->unitId, $data);
        $this->assertSame($version + 1, $saved['version']);
        $this->reject(fn () => $this->rotation->saveSchedule($this->unitId, $data), 409);
        $this->assertSame($saved, $this->rotation->unit($this->unitId)['schedule']);
        $this->invalid(fn () => $this->rotation->saveUnit($this->unitId, ['unit_code' => 'edit', 'name' => 'Edit', 'drum_capacity_kg' => '80.00']));
        $this->invalid(fn () => $this->rotation->registerDevice($this->unitId, ['chip_mac' => 'aa:bb:cc:dd:ee:04']));
        $this->invalid(fn () => $this->rotation->command($this->unitId, ['command' => 'stop']));
        $this->invalid(fn () => $this->rotation->revokeDevice($this->deviceId));
        $this->reject(fn () => $this->rotation->revokeDevice($this->deviceId, $version), 409);
    }

    public function testHardwareConfigurationIsGatedEvenWhenScheduleIsDisabled(): void
    {
        $this->reject(fn () => $this->saveSchedule($this->unitId, ['times' => ['09:00']]), 409);
        $this->reject(fn () => $this->saveSchedule($this->unitId, ['duration_seconds' => 20]), 409);
        $this->gate(true);
        $this->rotationDb->table('role_permissions')->where('permission_id', 3)->delete();
        $this->reject(fn () => $this->saveSchedule($this->unitId, ['times' => ['09:00']]), 403);
        $this->assertFalse($this->saveSchedule($this->unitId, [])['enabled']);
    }

    public function testRegistrationWorksWithoutHardwareAuthorityAndDoesNotEnableOperation(): void
    {
        $this->gate(false);
        $this->rotationDb->table('role_permissions')->where('permission_id', 3)->delete();
        $replacement = $this->register($this->unitId, ['chip_mac' => 'aa:bb:cc:dd:ee:04']);
        $this->assertNotEmpty($replacement['token']);
        $this->assertFalse($this->rotation->deviceSchedule($replacement['token'])['enabled']);
        $this->assertSame(0, $this->rotationDb->table('bmg_rotation_commands')->countAllResults());
        $this->reject(fn () => $this->rotation->deviceSchedule($this->token), 401);
        $this->reject(fn () => $this->command($this->unitId, ['command' => 'run_now']), 403);
        $this->reject(fn () => $this->saveSchedule($this->unitId, $this->schedule()), 403);
        $this->rotationDb->table('role_permissions')->where('permission_id', 4)->delete();
        $this->reject(fn () => $this->register($this->unitId, ['chip_mac' => 'aa:bb:cc:dd:ee:05']), 403);
    }

    public function testSafeDisableRetainsConfigurationWithoutHardwareAuthority(): void
    {
        $this->gate(true);
        $published = $this->saveSchedule($this->unitId, $this->schedule() + ['pwm' => 100]);
        $this->gate(false);
        $this->rotationDb->table('role_permissions')->where('permission_id', 3)->delete();
        $disabled = $this->rotation->saveSchedule($this->unitId, ['enabled' => false,
            'times' => $published['times'], 'duration_seconds' => $published['duration_seconds'],
            'expected_version' => $published['version']]);
        $this->assertFalse($disabled['enabled']);
        $this->assertNull($disabled['pwm']);
        $this->assertSame($published['version'] + 1, $disabled['version']);
    }

    public function testUnitEditConsumesVersionAndRejectsStaleMetadata(): void
    {
        $version = $this->version($this->unitId);
        $data = ['unit_code' => 'drum-test', 'name' => 'Renamed', 'status' => 'maintenance', 'drum_capacity_kg' => '80.00', 'expected_version' => $version];
        $saved = $this->rotation->saveUnit($this->unitId, $data);
        $this->assertSame($version + 1, $saved['schedule']['version']);
        $this->reject(fn () => $this->rotation->saveUnit($this->unitId, $data), 409);
        $this->assertSame('Renamed', $this->rotation->unit($this->unitId)['unit']['name']);
    }

    public function testNumericEnvironmentValueDoesNotEnableHardware(): void
    {
        $_ENV['BMG_HARDWARE_ENABLED'] = $_SERVER['BMG_HARDWARE_ENABLED'] = '1';
        putenv('BMG_HARDWARE_ENABLED=1');
        $this->assertFalse($this->rotation->deviceSchedule($this->token)['hardware_enabled']);
        $this->reject(fn () => $this->command($this->unitId, ['command' => 'stop']), 409);
    }

    public function testQueuedOldRunsCannotBeAttributedToANewBinding(): void
    {
        $payload = $this->runPayload();
        $this->rotation->ingestRun($this->token, $payload);
        $this->rotation->revokeDevice($this->deviceId, $this->version($this->unitId));
        $this->gate(true);
        $unit = $this->rotation->saveUnit(null, ['unit_code' => 'rebound', 'name' => 'Rebound', 'status' => 'active', 'drum_capacity_kg' => '80.00']);
        $replacement = $this->register((int) $unit['unit']['id'], ['device_id' => $this->deviceId, 'chip_mac' => 'aa:bb:cc:dd:ee:01']);
        $this->reject(fn () => $this->rotation->ingestRun($replacement['token'], $payload), 409);
        $payload['run_uid'] = 'queued-before-rebind';
        $this->reject(fn () => $this->rotation->ingestRun($replacement['token'], $payload), 409);
        $this->assertSame($this->unitId, (int) $this->rotationDb->table('bmg_rotation_runs')->get()->getRowArray()['unit_id']);
        $this->assertSame(1, $this->rotationDb->table('bmg_rotation_runs')->countAllResults());
    }

    public function testUnknownBindingAmbiguousBoundaryAndFutureRunsAreRejected(): void
    {
        $payload = $this->runPayload();
        $payload['occurred_at'] = $this->yesterday();
        $this->reject(fn () => $this->rotation->ingestRun($this->token, $payload), 409);
        $payload['occurred_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->modify('+1 minute')->format('Y-m-d H:i:s');
        $this->invalid(fn () => $this->rotation->ingestRun($this->token, $payload));
        $this->rotationDb->table('bmg_devices')->where('id', $this->deviceId)->update(['rotation_bound_at' => null]);
        $this->reject(fn () => $this->rotation->ingestRun($this->token, $this->runPayload()), 409);
        $this->assertSame(0, $this->rotationDb->table('bmg_rotation_runs')->countAllResults());
    }

    public function testPendingCommandsRecheckMaintenanceDeletionAndBinding(): void
    {
        $this->gate(true);
        $command = $this->command($this->unitId, ['command' => 'run_now']);
        $this->rotationDb->table('bmg_units')->where('id', $this->unitId)->update(['status' => 'maintenance']);
        $this->assertSame([], $this->rotation->pendingCommands($this->token)['commands']);
        $this->assertSame('cancelled', $this->rotationDb->table('bmg_rotation_commands')->where('id', $command['command_id'])->get()->getRowArray()['status']);
        $this->rotationDb->table('bmg_units')->where('id', $this->unitId)->update(['status' => 'active']);
        $this->command($this->unitId, ['command' => 'run_now']);
        $this->rotationDb->table('bmg_devices')->insert(['tenant_id' => 1, 'unit_id' => $this->unitId,
            'chip_mac' => 'aa:bb:cc:dd:ee:05', 'device_token_hash' => hash('sha256', 'other-device'),
            'status' => 'active', 'rotation_bound_at' => $this->yesterday()]);
        $this->assertSame([], $this->rotation->pendingCommands($this->token)['commands']);
        $this->rotationDb->table('bmg_devices')->where('chip_mac', 'aa:bb:cc:dd:ee:05')->delete();
        $this->command($this->unitId, ['command' => 'stop']);
        $this->rotationDb->table('bmg_units')->where('id', $this->unitId)->update(['deleted_at' => $this->yesterday()]);
        $this->assertSame([], $this->rotation->pendingCommands($this->token)['commands']);
        $this->assertSame(0, $this->rotationDb->table('bmg_rotation_commands')->where('status', 'pending')->countAllResults());
    }

    public function testDeviceCredentialsCannotImpersonateAnExistingHumanSession(): void
    {
        $this->gate(true);
        foreach (['X-Device-Token' => $this->token, 'Authorization' => 'Bearer operator-token'] as $header => $value) {
            service('request')->setHeader($header, $value);
            $this->reject(fn () => $this->command($this->unitId, ['command' => 'stop']), 403);
            $this->reject(fn () => $this->rotation->saveUnit(null, ['unit_code' => 'forged', 'name' => 'Forged']), 403);
            $this->assertFalse($this->rotation->deviceSchedule($this->token)['enabled']);
            service('request')->removeHeader($header);
        }
        $this->assertSame(0, $this->rotationDb->table('bmg_rotation_commands')->countAllResults());
    }

    public function testHumanChangesAreAuditedAtomicallyWithoutCredentials(): void
    {
        $this->gate(true);
        $this->saveSchedule($this->unitId, $this->schedule());
        $this->command($this->unitId, ['command' => 'stop']);
        $this->rotation->revokeDevice($this->deviceId, $this->version($this->unitId));
        $events = $this->rotationDb->table('audit_events')->get()->getResultArray();
        $this->assertCount(5, $events);
        foreach ($events as $event) {
            $this->assertSame(1, (int) $event['tenant_id']);
            $this->assertSame(1, (int) $event['actor_user_id']);
        }
        $json = json_encode($events);
        $this->assertStringNotContainsString($this->token, $json);
        $this->assertStringNotContainsString(hash('sha256', $this->token), $json);
        $this->assertTrue((new AuditService(new AuditEventModel($this->rotationDb)))->verifyChain(1)['valid']);
        $version = $this->version($this->unitId);
        $this->rotationDb->query('DROP TABLE audit_events');
        $this->reject(fn () => $this->rotation->saveUnit($this->unitId,
            ['unit_code' => 'changed', 'name' => 'Changed', 'drum_capacity_kg' => '80.00', 'expected_version' => $version]), 503);
        $this->assertSame($version, $this->version($this->unitId));
        $this->assertSame('drum-test', $this->rotationDb->table('bmg_units')->where('id', $this->unitId)->get()->getRowArray()['unit_code']);
    }

    public function testOmittedTelemetryDoesNotRefreshItsReportTime(): void
    {
        $this->rotation->heartbeat($this->token, ['rtc_valid' => false]);
        $device = $this->rotation->unit($this->unitId)['device'];
        $this->assertNotNull($device['rtc_reported_at']);
        $this->assertNull($device['firmware_reported_at']);
        $this->assertNull($device['schedule_reported_at']);
        $this->rotationDb->table('bmg_devices')->where('id', $this->deviceId)->update(['rtc_reported_at' => $this->yesterday()]);
        $this->rotation->heartbeat($this->token, []);
        $this->assertSame($this->yesterday(), $this->rotation->unit($this->unitId)['device']['rtc_reported_at']);
    }

    public function testApiRateLimitReturnsRetryAfterBeforeAnyDeviceOperation(): void
    {
        $throttler = $this->createMock(\CodeIgniter\Throttle\Throttler::class);
        $throttler->expects($this->once())->method('check')->willReturn(false);
        Services::injectMock('throttler', $throttler);
        $request = service('request');
        $request->setHeader('X-Device-Token', $this->token);
        $controller = new BmgDeviceApiController();
        $controller->initController($request, service('response'), service('logger'));
        $response = $controller->heartbeat();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->getHeaderLine('Retry-After'));
        $this->assertNull($this->rotationDb->table('bmg_devices')->where('id', $this->deviceId)->get()->getRowArray()['last_seen_at']);
    }

    public function testOrdinaryOperatorsCannotConfigureEvenWithHardwareGrant(): void
    {
        $this->gate(true);
        $this->rotationDb->table('users')->where('id', 1)->update(['role' => 'facilities_op', 'person_id' => 1]);
        $version = $this->version($this->unitId);
        $this->assertCount(1, $this->rotation->overview());
        foreach ($this->configurationOperations() as $operation) { $this->reject($operation, 403); }
        $this->assertSame($version, $this->version($this->unitId));
        // Operational commands retain their own explicit hardware permission, not configure.
        $this->assertSame('pending', $this->command($this->unitId, ['command' => 'stop'])['status']);
    }

    public function testConfigurationRequiresBothDatabaseGrantsIncludingForSuperadmin(): void
    {
        $this->gate(true);
        $this->rotationDb->table('role_permissions')->where('role', 'superadmin')->where('permission_id', 4)->delete();
        foreach ($this->configurationOperations() as $operation) { $this->reject($operation, 403); }
        $this->rotationDb->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => 4]);
        $this->rotationDb->table('role_permissions')->where('role', 'superadmin')->where('permission_id', 2)->delete();
        foreach ($this->configurationOperations() as $operation) { $this->reject($operation, 403); }
    }

    public function testNonBmgRoleCannotUseFacilitiesGrants(): void
    {
        $this->gate(true);
        $this->rotationDb->table('users')->where('id', 1)->update(['role' => 'clinic_admin', 'person_id' => 1]);
        $this->reject(fn () => $this->rotation->overview(), 403);
        $this->reject(fn () => $this->rotation->unit($this->unitId), 403);
        foreach ($this->configurationOperations() as $operation) { $this->reject($operation, 403); }
        $this->reject(fn () => $this->command($this->unitId, ['command' => 'stop']), 403);
        // Device authority remains independent of the human role/session.
        $this->assertFalse($this->rotation->deviceSchedule($this->token)['enabled']);
    }

    public function testCapacityIsRequiredOnCreateAndEditWithNoImplicitDefault(): void
    {
        $unit = ['unit_code' => 'no-capacity', 'name' => 'No capacity', 'status' => 'active'];
        $this->invalid(fn () => $this->rotation->saveUnit(null, $unit));
        $this->invalid(fn () => $this->rotation->saveUnit($this->unitId, $unit + ['expected_version' => $this->version($this->unitId)]));
        $this->assertSame(1, $this->rotationDb->table('bmg_units')->countAllResults());
        $this->assertSame(80.0, (float) $this->rotation->unit($this->unitId)['unit']['drum_capacity_kg']);
    }

    public function testCapacityAcceptsOnlyExactDecimal8Scale2Range(): void
    {
        $version = $this->version($this->unitId);
        $unit = ['unit_code' => 'drum-test', 'name' => 'Test drum', 'status' => 'active', 'expected_version' => $version];
        foreach ([null, '', '0', '0.00', '-1', '1000000', '999999.999', '0.001',
            '.01', '1.', '1e2', ' 20', '20 ', '1,000.00', true, false, 0.01, 20.0, [], 'NaN', 'INF', "1\n"] as $bad) {
            $this->invalid(fn () => $this->rotation->saveUnit($this->unitId, $unit + ['drum_capacity_kg' => $bad]));
        }
        $this->assertSame($version, $this->version($this->unitId));
        foreach (['0.01' => '0.01', '999999.99' => '999999.99', '42.5' => '42.50', '000042' => '42.00', 7 => '7.00'] as $input => $normalized) {
            $unit['expected_version'] = $this->version($this->unitId);
            $this->rotation->saveUnit($this->unitId, $unit + ['drum_capacity_kg' => $input]);
            $event = $this->rotationDb->table('audit_events')->orderBy('id', 'DESC')->get()->getRowArray();
            $this->assertSame($normalized, json_decode($event['payload_json'], true)['drum_capacity_kg']);
        }
    }

    public function testCapacityCannotFallBelowActiveBatchInputAndFailuresAreAtomic(): void
    {
        $unit = ['unit_code' => 'drum-test', 'name' => 'Test drum', 'status' => 'active'];
        foreach (['processing', 'awaiting_output', 'curing'] as $status) {
            $this->rotationDb->table('bmg_batches')->insert(['tenant_id' => 1, 'unit_id' => $this->unitId,
                'status' => $status, 'total_input_weight_kg' => '60.25']);
            $version = $this->version($this->unitId);
            $events = $this->rotationDb->table('audit_events')->countAllResults();
            $this->reject(fn () => $this->rotation->saveUnit($this->unitId,
                $unit + ['drum_capacity_kg' => '60.24', 'expected_version' => $version]), 409);
            $this->assertSame($version, $this->version($this->unitId));
            $this->assertSame($events, $this->rotationDb->table('audit_events')->countAllResults());
            $this->assertSame(80.0, (float) $this->rotation->unit($this->unitId)['unit']['drum_capacity_kg']);
            $this->rotationDb->table('bmg_batches')->where('tenant_id', 1)->delete();
        }
        $this->rotationDb->table('bmg_batches')->insert(['tenant_id' => 1, 'unit_id' => $this->unitId,
            'status' => 'processing', 'total_input_weight_kg' => '60.25']);
        $saved = $this->rotation->saveUnit($this->unitId, $unit + ['drum_capacity_kg' => '60.25', 'expected_version' => $this->version($this->unitId)]);
        $this->assertSame(60.25, (float) $saved['unit']['drum_capacity_kg']);
    }

    public function testCapacityChecksIgnoreOtherTenantsUnitsAndInactiveBatches(): void
    {
        foreach (['idle', 'released', 'voided'] as $status) {
            $this->rotationDb->table('bmg_batches')->insert(['tenant_id' => 1, 'unit_id' => $this->unitId,
                'status' => $status, 'total_input_weight_kg' => '80.00']);
        }
        $this->rotationDb->table('bmg_batches')->insert(['tenant_id' => 2, 'unit_id' => $this->unitId,
            'status' => 'processing', 'total_input_weight_kg' => '80.00']);
        $this->rotationDb->table('bmg_batches')->insert(['tenant_id' => 1, 'unit_id' => 900,
            'status' => 'processing', 'total_input_weight_kg' => '80.00']);
        $saved = $this->rotation->saveUnit($this->unitId, ['unit_code' => 'drum-test', 'name' => 'Test drum',
            'status' => 'active', 'drum_capacity_kg' => '0.01', 'expected_version' => $this->version($this->unitId)]);
        $this->assertSame(0.01, (float) $saved['unit']['drum_capacity_kg']);
    }

    public function testActiveUnitEditsBumpVersionAndPreserveScheduleWhileDisablingStillWorks(): void
    {
        $this->gate(true);
        $published = $this->saveSchedule($this->unitId, $this->schedule() + ['pwm' => 100]);
        $this->gate(false);
        $data = ['unit_code' => 'drum-test', 'name' => 'Renamed active', 'status' => 'active',
            'drum_capacity_kg' => '90.25', 'expected_version' => $published['version']];
        $saved = $this->rotation->saveUnit($this->unitId, $data);
        $this->assertSame($published['version'] + 1, $saved['schedule']['version']);
        $this->assertSame(array_replace($published, ['version' => $published['version'] + 1]), $saved['schedule']);
        $this->reject(fn () => $this->rotation->saveUnit($this->unitId, $data), 409);
        foreach (['maintenance', 'offline'] as $status) {
            $data['status'] = $status;
            $data['expected_version'] = $this->version($this->unitId);
            $saved = $this->rotation->saveUnit($this->unitId, $data);
            $this->assertSame($data['expected_version'] + 1, $saved['schedule']['version']);
            $this->assertFalse($saved['schedule']['enabled']);
            $this->assertNull($saved['schedule']['pwm']);
        }
    }

    private function configurationOperations(): array
    {
        return [
            fn () => $this->rotation->saveUnit(null, ['unit_code' => 'not-permitted', 'name' => 'Not permitted', 'drum_capacity_kg' => '80.00']),
            fn () => $this->rotation->saveUnit($this->unitId, ['unit_code' => 'drum-test', 'name' => 'Not permitted',
                'drum_capacity_kg' => '80.00', 'expected_version' => $this->version($this->unitId)]),
            fn () => $this->saveSchedule($this->unitId, []),
            fn () => $this->register($this->unitId, ['chip_mac' => 'aa:bb:cc:dd:ee:01', 'device_id' => $this->deviceId]),
            fn () => $this->rotation->revokeDevice($this->deviceId, $this->version($this->unitId)),
        ];
    }

    private function schedule(): array
    {
        return ['enabled' => true, 'times' => ['08:00', '16:00'], 'duration_seconds' => 60];
    }

    private function runPayload(): array
    {
        return ['run_uid' => 'flash-run-1', 'motor_state' => 'turned_normal', 'duration_seconds' => 60, 'occurred_at' => (new \DateTimeImmutable($this->yesterday(), new \DateTimeZone('Asia/Manila')))->modify('+1 minute')->format('Y-m-d H:i:s')];
    }

    private function yesterday(): string
    {
        return (new \DateTimeImmutable('yesterday', new \DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    }

    private function version(int $unit): int
    {
        $row = $this->rotationDb->table('bmg_rotation_schedules')->where('tenant_id', 1)->where('unit_id', $unit)->get()->getRowArray();
        return (int) ($row['version'] ?? 0);
    }

    private function saveSchedule(int $unit, array $data): array
    {
        return $this->rotation->saveSchedule($unit, $data + ['expected_version' => $this->version($unit)]);
    }

    private function register(int $unit, array $data): array
    {
        return $this->rotation->registerDevice($unit, $data + ['expected_version' => $this->version($unit)]);
    }

    private function command(int $unit, array $data): array
    {
        return $this->rotation->command($unit, $data + ['expected_version' => $this->version($unit)]);
    }

    private function gate(bool $enabled): void
    {
        $_ENV['BMG_HARDWARE_ENABLED'] = $_SERVER['BMG_HARDWARE_ENABLED'] = $enabled ? 'true' : 'false';
        putenv('BMG_HARDWARE_ENABLED=' . ($enabled ? 'true' : 'false'));
    }

    private function signIn(): void
    {
        session()->set('_auth', ['started_at' => time(), 'last_seen_at' => time(),
            'version' => 1, 'session_id' => session_id()]);
        session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'tenant_id' => 1, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['facilities.read', 'facilities.manage', 'facilities.configure', 'facilities.hardware.manage']]]);
    }

    private function reject(callable $operation, int $code): void
    {
        try { $operation(); } catch (Throwable $error) {
            $this->assertSame($code, $error->getCode(), $error->getMessage());
            return;
        }
        $this->fail('Expected rejection with code ' . $code);
    }

    private function invalid(callable $operation): void
    {
        try { $operation(); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); return; }
        $this->fail('Expected validation rejection.');
    }

    private function fixture(): void
    {
        $statements = [
            'CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)',
            'CREATE TABLE users (auth_version INTEGER NOT NULL DEFAULT 1, id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, role TEXT, username TEXT, email TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE people (id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, deleted_at TEXT)',
            'CREATE TABLE employee_profiles (person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY, code TEXT)',
            'CREATE TABLE role_permissions (role TEXT, permission_id INTEGER)',
            'CREATE TABLE bmg_units (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, unit_code TEXT, name TEXT, status TEXT CHECK (status IN (\'active\', \'maintenance\', \'offline\')), drum_capacity_kg DECIMAL(8,2) NOT NULL, created_at TEXT, updated_at TEXT, deleted_at TEXT, UNIQUE(tenant_id, unit_code))',
            'CREATE TABLE bmg_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, unit_id INTEGER, status TEXT, total_input_weight_kg DECIMAL(10,2) NOT NULL)',
            "CREATE TABLE bmg_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, unit_id INTEGER, chip_mac TEXT, device_token_hash TEXT, firmware_version TEXT NOT NULL DEFAULT 'v1.0.0', status TEXT CHECK (status IN ('active', 'revoked')), last_seen_at TEXT, created_at TEXT, updated_at TEXT)",
            'CREATE UNIQUE INDEX rotation_fixture_token ON bmg_devices(device_token_hash)',
            'CREATE UNIQUE INDEX rotation_fixture_mac ON bmg_devices(tenant_id, chip_mac)',
            'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, event_type TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT NOT NULL, payload_json TEXT, prev_hash TEXT NOT NULL, curr_hash TEXT NOT NULL, hash_version INTEGER NOT NULL DEFAULT 1, occurred_at TEXT NOT NULL)',
            'CREATE TABLE bmg_device_runs (id INTEGER PRIMARY KEY, run_uid TEXT)',
            'CREATE TABLE bmg_process_logs (id INTEGER PRIMARY KEY)',
            'INSERT INTO tenants (id, is_active) VALUES (1, 1), (2, 1)',
            "INSERT INTO users (id, tenant_id, role, username, status) VALUES (1, 1, 'superadmin', 'rotation-admin', 'active')",
            "INSERT INTO people (id, tenant_id, first_name, last_name) VALUES (1, 1, 'Test', 'Operator')",
            "INSERT INTO permissions (id, code) VALUES (1, 'facilities.read'), (2, 'facilities.manage'), (3, 'facilities.hardware.manage'), (4, 'facilities.configure')",
            "INSERT INTO role_permissions (role, permission_id) VALUES ('superadmin', 1), ('superadmin', 2), ('superadmin', 3), ('superadmin', 4),
                ('facilities_op', 1), ('facilities_op', 2), ('facilities_op', 3),
                ('clinic_admin', 1), ('clinic_admin', 2), ('clinic_admin', 3), ('clinic_admin', 4)",
        ];
        foreach ($statements as $sql) { $this->rotationDb->query($sql); }
    }
}
