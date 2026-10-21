<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthenticatedUser;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

/** No migrations, resets or seeds: requires the restored native schema and guarded bootstrap. */
abstract class BmgIsolatedRouteCase extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected BaseConnection $bmgDb;
    protected int $tenant;
    protected int $foreignTenant;
    protected int $unit;
    protected int $device;
    protected string $token;
    protected array $admin;

    protected function setUp(): void
    {
        if (getenv('SYNAPSE_BMG_ISOLATED') !== '1') {
            $this->markTestSkipped('Use python writable/bmg-isolated-validation.py; no configured DB access allowed.');
        }
        parent::setUp();
        $this->bmgDb = Database::connect('tests');
        $id = $this->bmgDb->query('SELECT @@port AS port, @@datadir AS dir, DATABASE() AS name')->getRowArray();
        $this->assertSame(33307, (int) $id['port']);
        $this->assertSame('humus_bmg_isolated_test', $id['name']);
        $this->assertSame(strtolower(str_replace('\\', '/', WRITEPATH . 'isolated-inventory-db')),
            strtolower(rtrim(str_replace('\\', '/', $id['dir']), '/')));
        $this->bmgDb->transBegin();
        // The native backup predates the configure catalog migration. Fixture grants
        // are transactional and never granted to facilities_op or seeded globally.
        $configure = $this->bmgDb->table('permissions')->where('code', 'facilities.configure')->get()->getRowArray();
        if ($configure === null) {
            $this->bmgDb->table('permissions')->insert(['code' => 'facilities.configure', 'name' => 'Configure isolated drums', 'module' => 'facilities']);
            $configure = ['id' => $this->bmgDb->insertID()];
        }
        foreach (['superadmin', 'bmg_admin'] as $role) {
            if ($this->bmgDb->table('role_permissions')->where('role', $role)->where('permission_id', $configure['id'])->countAllResults() === 0) {
                $this->bmgDb->table('role_permissions')->insert(['role' => $role, 'permission_id' => $configure['id']]);
            }
        }
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        service('superglobals')->setCookieArray([]);
        $this->tenant = $this->insert('tenants', ['slug' => uniqid('bmg_'), 'name' => 'BMG route tenant', 'is_active' => 1]);
        $this->foreignTenant = $this->insert('tenants', ['slug' => uniqid('bmg_other_'), 'name' => 'Other tenant', 'is_active' => 1]);
        $user = $this->insert('users', ['tenant_id' => $this->tenant, 'username' => uniqid('bmg_admin_'), 'role' => 'superadmin', 'status' => 'active']);
        $this->admin = ['tenant_id' => $this->tenant, 'user' => ['id' => $user, 'tenant_id' => $this->tenant,
            'role' => 'superadmin', 'logged_in' => true, 'permissions' => ['facilities.read', 'facilities.manage', 'facilities.configure', 'facilities.hardware.manage']]];
        $this->unit = $this->insert('bmg_units', ['tenant_id' => $this->tenant, 'unit_code' => 'isolated-drum', 'name' => 'Isolated drum', 'status' => 'active']);
        $this->token = bin2hex(random_bytes(32));
        $this->device = $this->insert('bmg_devices', ['tenant_id' => $this->tenant, 'unit_id' => $this->unit,
            'chip_mac' => 'aa:bb:cc:dd:ee:01', 'device_token_hash' => hash('sha256', $this->token), 'status' => 'active',
            'rotation_bound_at' => '2020-01-01 00:00:00']);
    }

    protected function tearDown(): void
    {
        if (isset($this->bmgDb)) {
            $this->bmgDb->transRollback();
            AuthenticatedUser::clear();
            Services::resetSingle('security');
            Services::resetSingle('renderer');
            service('superglobals')->setCookieArray([]);
        }
        parent::tearDown();
    }

    protected function insert(string $table, array $values): int
    {
        $now = date('Y-m-d H:i:s');
        $this->bmgDb->table($table)->insert($values + ['created_at' => $now, 'updated_at' => $now]);
        return (int) $this->bmgDb->insertID();
    }

    protected function api(string $method, string $endpoint, ?array $payload = null, ?string $token = null)
    {
        $headers = ['X-Device-Token' => $token ?? $this->token, 'Accept' => 'application/json'];
        $this->withSession([])->withHeaders($headers);
        if ($method === 'get') { return $this->get('api/v1/devices/bmg/' . $endpoint); }
        return $this->withBodyFormat('json')->post('api/v1/devices/bmg/' . $endpoint, $payload ?? []);
    }

    protected function json($response, int $status = 200): array
    {
        $response->assertStatus($status);
        $response->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        return json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function submit(string $path, array $data = [], bool $csrf = true, ?array $identity = null)
    {
        $token = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $token);
        Services::resetSingle('security');
        if ($csrf) { $data[config('Security')->tokenName] = $token; }
        return $this->withBodyFormat('')->withSession($identity ?? $this->admin)
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->post($path, $data);
    }
}

final class BmgDeviceApiTest extends BmgIsolatedRouteCase
{
    public function testAllDeviceRoutesRequireDeviceTokenNotHumanSession(): void
    {
        foreach ([['get', 'schedule'], ['get', 'commands'], ['post', 'heartbeat'], ['post', 'runs'], ['post', 'commands/ack']] as [$method, $endpoint]) {
            $this->json($this->api($method, $endpoint, [], ''), 401);
            $valid = match ($endpoint) {
                'runs' => ['run_uid' => 'unauthorized', 'motor_state' => 'stopped', 'duration_seconds' => 0,
                    'occurred_at' => '2020-01-02 00:00:00'],
                'commands/ack' => ['command_id' => 1, 'status' => 'completed'],
                'heartbeat' => ['rtc_valid' => false],
                default => [],
            };
            $this->json($this->api($method, $endpoint, $valid, str_repeat('f', 64)), 401);
        }
        $this->withSession($this->admin)->withHeaders(['Accept' => 'application/json'])->get('api/v1/devices/bmg/schedule')->assertStatus(401);
    }

    public function testScheduleIsDisabledExplicitManilaContractWithoutLegacyInterval(): void
    {
        $response = $this->api('get', 'schedule');
        $data = $this->json($response);
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertFalse($data['enabled']);
        $this->assertFalse($data['hardware_enabled']);
        $this->assertFalse($data['published_enabled']);
        $this->assertSame('Asia/Manila', $data['timezone']);
        $this->assertSame([], $data['times']);
        $this->assertNull($data['pwm']);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayNotHasKey('interval_hours', $data);
        $this->assertStringNotContainsString($this->token, $response->getJSON());
    }

    public function testDefaultDisabledCommandPollingIsEmpty(): void
    {
        $this->assertSame([], $this->json($this->api('get', 'commands'))['commands']);
    }

    public function testJsonAndFormHeartbeatsPreserveUnknownTelemetry(): void
    {
        $this->json($this->api('post', 'heartbeat', ['firmware_version' => '2.1.0', 'rtc_valid' => false, 'applied_schedule_version' => 0]));
        $before = $this->bmgDb->table('bmg_devices')->where('id', $this->device)->get()->getRowArray();
        $this->assertSame('2.1.0', $before['reported_firmware_version']);
        $this->assertSame(0, (int) $before['rtc_valid']);
        $this->assertNotNull($before['last_seen_at']);
        $this->withSession([])->withHeaders(['X-Device-Token' => $this->token])->withBodyFormat('')->post('api/v1/devices/bmg/heartbeat', [])->assertStatus(200);
        $after = $this->bmgDb->table('bmg_devices')->where('id', $this->device)->get()->getRowArray();
        foreach (['reported_firmware_version', 'rtc_valid', 'applied_schedule_version', 'firmware_reported_at', 'rtc_reported_at', 'schedule_reported_at'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->json($this->api('post', 'heartbeat', ['motor_ok' => true]), 400);
        $this->json($this->api('post', 'heartbeat', ['tenant_id' => $this->foreignTenant]), 400);
    }

    public function testRunFactsAreIdempotentAndDoNotCreateSensorLogs(): void
    {
        $logs = $this->bmgDb->table('bmg_process_logs')->countAllResults();
        $legacy = $this->bmgDb->table('bmg_device_runs')->countAllResults();
        $facts = ['run_uid' => 'isolated-run', 'motor_state' => 'turned_normal', 'duration_seconds' => 60,
            'occurred_at' => (new \DateTimeImmutable('-1 minute', new \DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s')];
        $this->assertSame('created', $this->json($this->api('post', 'runs', $facts), 201)['status']);
        $this->assertSame('acknowledged', $this->json($this->api('post', 'runs', $facts))['status']);
        $this->json($this->api('post', 'runs', array_replace($facts, ['duration_seconds' => 61])), 409);
        $this->assertSame(1, $this->bmgDb->table('bmg_rotation_runs')->where('device_id', $this->device)->countAllResults());
        $this->assertSame($logs, $this->bmgDb->table('bmg_process_logs')->countAllResults());
        $this->assertSame($legacy, $this->bmgDb->table('bmg_device_runs')->countAllResults());
        $this->bmgDb->table('bmg_devices')->where('id', $this->device)->update(['rotation_bound_at' => null]);
        $this->json($this->api('post', 'runs', array_replace($facts, ['run_uid' => 'unproven-binding'])), 409);
    }

    public function testRevocationAndForeignCommandAcknowledgementFailClosed(): void
    {
        $foreignUnit = $this->insert('bmg_units', ['tenant_id' => $this->foreignTenant, 'unit_code' => 'foreign', 'name' => 'Foreign drum', 'status' => 'active']);
        $foreignDevice = $this->insert('bmg_devices', ['tenant_id' => $this->foreignTenant, 'unit_id' => $foreignUnit,
            'chip_mac' => 'aa:bb:cc:dd:ee:99', 'device_token_hash' => hash('sha256', random_bytes(32)), 'status' => 'active']);
        $this->bmgDb->table('bmg_rotation_commands')->insert(['tenant_id' => $this->foreignTenant, 'unit_id' => $foreignUnit,
            'device_id' => $foreignDevice, 'command' => 'stop', 'created_by_user_id' => $this->admin['user']['id'],
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => '2099-01-01 00:00:00']);
        $command = (int) $this->bmgDb->insertID();
        $this->json($this->api('post', 'commands/ack', ['command_id' => $command, 'status' => 'completed']), 404);
        $this->assertSame('pending', $this->bmgDb->table('bmg_rotation_commands')->where('id', $command)->get()->getRowArray()['status']);
        $this->bmgDb->table('bmg_devices')->where('id', $this->device)->update(['status' => 'revoked']);
        $this->json($this->api('get', 'schedule'), 401);
    }
}
