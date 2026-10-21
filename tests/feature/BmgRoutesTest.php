<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__ . '/BmgDeviceApiTest.php';

final class BmgRoutesTest extends BmgIsolatedRouteCase
{
    private function mutations(): array
    {
        return ['facilities/units/store', 'facilities/unit/' . $this->unit . '/update',
            'facilities/unit/' . $this->unit . '/schedule', 'facilities/unit/' . $this->unit . '/device',
            'facilities/unit/' . $this->unit . '/command', 'facilities/device/' . $this->device . '/revoke',
            'facilities/unit/' . $this->unit . '/batch',
            'facilities/batch/2147483647/release', 'facilities/batch/2147483647/cancel'];
    }

    public function testEveryHumanMutationRequiresCsrfAndDatabaseAuthentication(): void
    {
        $before = $this->state();
        foreach ($this->mutations() as $path) {
            $this->submit($path, [], false)->assertStatus(403);
            $this->submit($path, [], true, [])->assertStatus(401);
        }
        $this->assertSame($before, $this->state());
    }

    public function testSuspendedDatabaseUserCannotUseCachedSuperadminSession(): void
    {
        $this->bmgDb->table('users')->where('id', $this->admin['user']['id'])->update(['status' => 'suspended']);
        foreach ($this->mutations() as $path) { $this->submit($path)->assertStatus(401); }
    }

    public function testForgedSessionTenantDoesNotAuthorizeAnotherTenant(): void
    {
        $forged = $this->admin;
        $forged['tenant_id'] = $forged['user']['tenant_id'] = $this->foreignTenant;
        $this->submit('facilities/units/store', ['unit_code' => 'forged', 'name' => 'Forged', 'drum_capacity_kg' => '500.00'], true, $forged)->assertStatus(401);
        $this->assertSame(0, $this->bmgDb->table('bmg_units')->where('tenant_id', $this->foreignTenant)->countAllResults());
    }

    public function testFreshPermissionsOverrideForgedCachedGrants(): void
    {
        $permission = $this->bmgDb->table('permissions')->where('code', 'facilities.manage')->get()->getRowArray();
        $this->bmgDb->table('role_permissions')->where('role', 'superadmin')->where('permission_id', $permission['id'])->delete();
        $this->submit('facilities/unit/' . $this->unit . '/batch', ['input_weight_kg' => '25'])->assertStatus(403);
        $this->assertSame(1, $this->bmgDb->table('bmg_units')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testFreshConfigurePermissionOverridesCachedSuperadminGrant(): void
    {
        $permission = $this->bmgDb->table('permissions')->where('code', 'facilities.configure')->get()->getRowArray();
        $this->bmgDb->table('role_permissions')->where('role', 'superadmin')->where('permission_id', $permission['id'])->delete();
        $before = $this->state();
        $this->submit('facilities/units/store', ['unit_code' => 'forged', 'name' => 'Forged', 'drum_capacity_kg' => '500.00'])->assertStatus(403);
        $this->submit('facilities/unit/' . $this->unit . '/update', ['unit_code' => 'changed', 'name' => 'Changed', 'drum_capacity_kg' => '500.00', 'expected_version' => '0'])->assertStatus(403);
        $this->submit('facilities/unit/' . $this->unit . '/schedule', ['enabled' => '0', 'duration_seconds' => '60', 'expected_version' => '0'])->assertStatus(403);
        $this->submit('facilities/unit/' . $this->unit . '/device', ['chip_mac' => 'aa:bb:cc:dd:ee:02', 'expected_version' => '0'])->assertStatus(403);
        $this->submit('facilities/device/' . $this->device . '/revoke', ['expected_version' => 0])->assertStatus(403);
        $this->assertSame($before, $this->state());
    }

    public function testForeignIdentifiersAndPostedTenantCannotMutateRows(): void
    {
        $foreign = $this->insert('bmg_units', ['tenant_id' => $this->foreignTenant, 'unit_code' => 'foreign', 'name' => 'Private drum', 'status' => 'active']);
        $before = $this->state();
        $this->submit('facilities/unit/' . $foreign . '/update', ['unit_code' => 'stolen', 'name' => 'Stolen', 'status' => 'active', 'drum_capacity_kg' => '500.00', 'expected_version' => '0'])->assertStatus(404);
        $this->submit('facilities/unit/' . $foreign . '/schedule', ['enabled' => '0', 'duration_seconds' => 60, 'expected_version' => 0])->assertStatus(404);
        $this->assertSame($before, $this->state());
        // The controller allowlists form fields: ignoring forged ownership is safe,
        // provided it never changes the DB-derived tenant or actor.
        $this->submit('facilities/units/store', ['unit_code' => 'forged', 'name' => 'Forged', 'drum_capacity_kg' => '500.00',
            'tenant_id' => $this->foreignTenant, 'updated_by_user_id' => 2147483647])->assertStatus(200);
        $created = $this->bmgDb->table('bmg_units')->where('unit_code', 'forged')->get()->getRowArray();
        $this->assertNotNull($created);
        $this->assertSame($this->tenant, (int) $created['tenant_id']);
        $this->assertSame($before['bmg_units'][array_key_last($before['bmg_units'])],
            $this->bmgDb->table('bmg_units')->where('id', $foreign)->get()->getRowArray());
        $schedule = $this->bmgDb->table('bmg_rotation_schedules')->where('unit_id', $created['id'])->get()->getRowArray();
        $this->assertSame((int) $this->admin['user']['id'], (int) $schedule['updated_by_user_id']);
    }

    public function testDeviceCredentialCannotActAsHumanEvenWithValidSessionAndCsrf(): void
    {
        $token = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $token);
        \Config\Services::resetSingle('security');
        $this->withSession($this->admin)->withHeaders(['Accept' => 'application/json', 'X-Device-Token' => $this->token])
            ->post('facilities/units/store', ['unit_code' => 'token-human', 'name' => 'Denied', 'drum_capacity_kg' => '500.00', config('Security')->tokenName => $token])->assertStatus(403);
    }

    public function testStoredHardwareGateCannotBeBypassedByCachedPermission(): void
    {
        // Even an explicit DB hardware grant must not override the process default-off gate.
        $permission = $this->bmgDb->table('permissions')->where('code', 'facilities.hardware.manage')->get()->getRowArray();
        if ($permission === null) {
            $this->bmgDb->table('permissions')->insert(['code' => 'facilities.hardware.manage', 'name' => 'Isolated hardware grant', 'module' => 'facilities']);
            $permission = ['id' => $this->bmgDb->insertID()];
        }
        if ($this->bmgDb->table('role_permissions')->where('role', 'superadmin')->where('permission_id', $permission['id'])->countAllResults() === 0) {
            $this->bmgDb->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => $permission['id']]);
        }
        $before = $this->state();
        $this->submit('facilities/unit/' . $this->unit . '/command', ['command' => 'run_now', 'duration_seconds' => 60, 'expected_version' => 0])->assertStatus(409);
        $this->assertSame($before, $this->state());

        // Provisioning is configuration only, even while motor operation is gated off.
        // A new HTTP response must not retain the preceding command's JSON content type.
        \Config\Services::resetSingle('response');
        $response = $this->submit('facilities/unit/' . $this->unit . '/device', ['chip_mac' => 'aa:bb:cc:dd:ee:02', 'expected_version' => 0]);
        $this->assertSame(200, $response->response()->getStatusCode());
        $this->assertSame('text/html', strtolower(trim(explode(';', $response->response()->getHeaderLine('Content-Type'))[0])));
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('', $response->response()->getHeaderLine('Location'));
        $matched = preg_match('/<textarea\b[^>]*\bdata-bmg-token[^>]*>([a-f0-9]{64})<\/textarea>/', $response->response()->getBody(), $credential);
        $this->assertTrue($matched === 1, 'Expected a direct one-time credential response.');
        $device = $this->bmgDb->table('bmg_devices')->where('tenant_id', $this->tenant)->where('chip_mac', 'aa:bb:cc:dd:ee:02')->get()->getRowArray();
        $this->assertNotNull($device);
        $this->assertTrue(hash_equals($device['device_token_hash'], hash('sha256', $credential[1])), 'Only the issued credential hash must be stored.');
        unset($credential);
        $schedule = $this->bmgDb->table('bmg_rotation_schedules')->where('tenant_id', $this->tenant)->where('unit_id', $this->unit)->get()->getRowArray();
        $this->assertNotNull($schedule);
        $this->assertSame(0, (int) $schedule['enabled']);
        $this->assertSame($before['bmg_rotation_commands'], $this->state()['bmg_rotation_commands']);
    }

    public function testValidHumanCreateUsesDatabaseTenantAndDisabledSchedule(): void
    {
        $response = $this->submit('facilities/units/store', ['unit_code' => 'new-drum', 'name' => 'New isolated drum', 'status' => 'offline', 'drum_capacity_kg' => '500.00']);
        $this->assertContains($response->response()->getStatusCode(), [200, 201, 302, 303]);
        $unit = $this->bmgDb->table('bmg_units')->where('tenant_id', $this->tenant)->where('unit_code', 'new-drum')->get()->getRowArray();
        $this->assertNotNull($unit);
        $schedule = $this->bmgDb->table('bmg_rotation_schedules')->where('unit_id', $unit['id'])->get()->getRowArray();
        $this->assertNotNull($schedule);
        $this->assertSame(0, (int) $schedule['enabled']);
        $this->assertSame((int) $this->admin['user']['id'], (int) $schedule['updated_by_user_id']);
        $this->assertSame('500.00', $unit['drum_capacity_kg']);
        $this->submit('facilities/unit/' . $unit['id'] . '/update', ['unit_code' => 'new-drum',
            'name' => 'Updated isolated drum', 'status' => 'offline', 'drum_capacity_kg' => '250.50',
            'expected_version' => (string) $schedule['version']])->assertStatus(200);
        $this->assertSame('250.50', $this->bmgDb->table('bmg_units')->where('id', $unit['id'])->get()->getRowArray()['drum_capacity_kg']);
        $this->assertSame((int) $schedule['version'] + 1,
            (int) $this->bmgDb->table('bmg_rotation_schedules')->where('unit_id', $unit['id'])->get()->getRowArray()['version']);
    }

    public function testConfigureGrantIsForAdminsNotFacilitiesOperators(): void
    {
        $operator = $this->roleIdentity('facilities_op');
        $permission = $this->bmgDb->table('permissions')->where('code', 'facilities.configure')->get()->getRowArray();
        $this->assertSame(0, $this->bmgDb->table('role_permissions')->where('role', 'facilities_op')->where('permission_id', $permission['id'])->countAllResults());
        $admin = $this->roleIdentity('bmg_admin');
        $this->submit('facilities/units/store', ['unit_code' => 'admin-created', 'name' => 'Admin drum', 'status' => 'offline', 'drum_capacity_kg' => '500.00'], true, $admin)->assertStatus(200);
        $unit = $this->bmgDb->table('bmg_units')->where('unit_code', 'admin-created')->get()->getRowArray();
        $this->assertSame($this->tenant, (int) $unit['tenant_id']);
        $this->submit('facilities/units/store', ['unit_code' => 'op-created', 'name' => 'Denied', 'drum_capacity_kg' => '500.00'], true, $operator)->assertStatus(403);
    }

    public function testScheduleVersionIsRequiredAndStaleFormsCannotOverwrite(): void
    {
        $path = 'facilities/unit/' . $this->unit . '/schedule';
        $this->submit($path, ['enabled' => '0', 'duration_seconds' => '60'])->assertStatus(422);
        $this->submit($path, ['enabled' => '0', 'duration_seconds' => '60', 'expected_version' => '0'])->assertStatus(200);
        $published = $this->bmgDb->table('bmg_rotation_schedules')->where('unit_id', $this->unit)->get()->getRowArray();
        $this->assertSame(1, (int) $published['version']);
        $this->submit($path, ['enabled' => '0', 'duration_seconds' => '60', 'expected_version' => '0'])->assertStatus(409);
        $this->assertSame($published, $this->bmgDb->table('bmg_rotation_schedules')->where('unit_id', $this->unit)->get()->getRowArray());
        $this->submit($path, ['enabled' => '0', 'duration_seconds' => '60', 'expected_version' => '1'])->assertStatus(200);
        $this->assertSame(2, (int) $this->bmgDb->table('bmg_rotation_schedules')->where('unit_id', $this->unit)->get()->getRowArray()['version']);
    }

    public function testLegacySensorLogRouteIsNotAvailable(): void
    {
        $before = $this->state();
        try {
            $response = $this->submit('facilities/batch/2147483647/log', [
                'temperature_c' => '55', 'moisture_pct' => '50', 'oxygen_pct' => '20',
            ]);
            $response->assertStatus(404);
        } catch (\CodeIgniter\Exceptions\PageNotFoundException $error) {
            $this->assertSame(404, $error->getCode());
        }
        $this->assertSame($before, $this->state());
    }

    public function testMaintenanceClosesFacilitiesAndDeviceApiWithoutWrites(): void
    {
        $path = WRITEPATH . 'bmg-maintenance.flag';
        // Exclusive creation prevents clobbering an operator/deployment flag.
        if (file_exists($path)) { $this->markTestSkipped('Existing BMG maintenance flag belongs to another operation.'); }
        $handle = @fopen($path, 'x');
        if ($handle === false) { $this->markTestSkipped('Could not exclusively own the maintenance flag.'); }
        $marker = 'isolated-bmg-test-' . bin2hex(random_bytes(16));
        try {
            fwrite($handle, $marker);
            fclose($handle);
            $before = $this->state();
            foreach (['facilities', 'facilities/unit/' . $this->unit] as $route) {
                $result = $this->withSession($this->admin)->withHeaders(['Accept' => 'application/json'])->get($route);
                $result->assertStatus(503);
                $result->assertHeader('Retry-After', '60');
            }
            foreach ($this->mutations() as $route) { $this->submit($route)->assertStatus(503); }
            foreach ([['get', 'schedule'], ['get', 'commands'], ['post', 'heartbeat'], ['post', 'runs'], ['post', 'commands/ack']] as [$method, $endpoint]) {
                $result = $this->api($method, $endpoint, ['rtc_valid' => false]);
                $this->json($result, 503);
                $result->assertHeader('Retry-After', '60');
            }
            $this->assertSame($before, $this->state());
        } finally {
            if (is_resource($handle)) { fclose($handle); }
            clearstatcache(true, $path);
            if (is_file($path) && file_get_contents($path) === $marker) {
                $this->assertTrue(unlink($path), 'Could not remove the test-owned maintenance flag.');
            }
        }
    }

    public function testSuperadminDetailRendersFullLayoutWithoutPhpViewWarnings(): void
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
            if (($severity & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE)) === 0) { return false; }
            $warnings[] = $message . ' at ' . $file . ':' . $line;
            return true;
        });
        try {
            $response = $this->withSession($this->admin)->withHeaders(['Accept' => 'text/html'])
                ->get('facilities/unit/' . $this->unit);
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $warnings, implode("\n", $warnings));
        $response->assertStatus(200);
        $body = $response->response()->getBody();
        $this->assertStringContainsString('<!DOCTYPE html>', $body);
        $this->assertStringContainsString('<html lang="en">', $body);
        $this->assertStringContainsString('</html>', $body);
        $this->assertStringContainsString(' - Synapse Campus Health & Services</title>', $body);
        $this->assertStringContainsString('Isolated drum', $body);
        $this->assertStringContainsString('Daily rotation schedule', $body);
        $this->assertStringContainsString('name="drum_capacity_kg"', $body);
        $this->assertDoesNotMatchRegularExpression('/(?:PHP (?:Warning|Notice)|Undefined (?:variable|array key)|Trying to access array offset)/i', $body);
        $this->assertSame('superadmin', session()->get('user')['role']);
    }

    private function roleIdentity(string $role): array
    {
        $person = $this->insert('people', ['tenant_id' => $this->tenant, 'kind' => 'employee', 'mis_id' => uniqid('bmg_'),
            'first_name' => 'Isolated', 'last_name' => 'Operator']);
        $user = $this->insert('users', ['tenant_id' => $this->tenant, 'person_id' => $person,
            'username' => uniqid('bmg_'), 'role' => $role, 'status' => 'active']);
        return ['tenant_id' => $this->tenant, 'user' => ['id' => $user, 'tenant_id' => $this->tenant,
            'role' => $role, 'logged_in' => true, 'permissions' => ['facilities.read', 'facilities.manage', 'facilities.configure']]];
    }

    private function state(): array
    {
        $rows = [];
        foreach (['bmg_units', 'bmg_devices', 'bmg_rotation_schedules', 'bmg_rotation_commands', 'bmg_rotation_runs', 'bmg_batches', 'bmg_process_logs'] as $table) {
            $rows[$table] = $this->bmgDb->table($table)->orderBy('id')->get()->getResultArray();
        }
        return $rows;
    }
}
