<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__ . '/BmgDeviceApiTest.php';

final class BmgUsbRoutesTest extends BmgIsolatedRouteCase
{
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setGate('BMG_USB_FLASH_ENABLED', 'false');
        $this->setGate('BMG_HARDWARE_ENABLED', 'false');
        // Use the real empty deployment catalog, never install firmware or inject a service.
        $this->assertSame([], config(\Config\BmgFirmware::class)->releases);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $saved) {
            putenv($saved['process'] === false ? $key : $key . '=' . $saved['process']);
            foreach (['_ENV', '_SERVER'] as $source) {
                if ($saved[$source] === null) { unset($GLOBALS[$source][$key]); }
                else { $GLOBALS[$source][$key] = $saved[$source]; }
            }
        }
        parent::tearDown();
    }

    public function testWorkspaceRendersFullLayoutAndIdleControlsWithoutImplicitWrites(): void
    {
        $before = $this->state();
        $warnings = [];
        set_error_handler(static function (int $severity) use (&$warnings): bool {
            if (($severity & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE)) === 0) { return false; }
            $warnings[] = $severity;
            return true;
        });
        try {
            $response = $this->read($this->base(), $this->admin, 'text/html');
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $warnings, 'USB layout must render without PHP warnings.');
        $this->assertUsbResponse($response, 200);
        $this->privateResponse($response);
        $body = $response->response()->getBody();
        foreach (['<!DOCTYPE html>', '<html lang="en">', '</html>',
            ' - Synapse Campus Health & Services</title>', 'Isolated drum', 'data-bmg-usb',
            'data-usb-select', 'data-usb-release', 'data-usb-clear', 'data-usb-log',
            'data-usb-isolated', 'No approved firmware available',
            'Nothing opens or resets automatically.', 'Monitoring never starts automatically.',
            'No installation started.', 'No command entry or serial transmit controls.',
            'bmg-usb.js', 'bmg-usb.css', 'name="' . csrf_token() . '"',
            'data-esptool-url="' . esc(base_url('assets/vendor/esptool-js/bundle.js'), 'attr') . '"',
            'data-flash-enabled="0"'] as $fragment) {
            $this->assertTrue(str_contains($body, $fragment), 'Missing USB layout contract: ' . $fragment);
        }
        foreach (['identify', 'disconnect', 'flash', 'monitor', 'stop-monitor'] as $control) {
            $this->assertTrue(preg_match('/<button\b[^>]*data-usb-' . $control . '\b[^>]*\bdisabled\b[^>]*>/', $body) === 1,
                'USB operation must initially be disabled: ' . $control);
        }
        $this->assertTrue(preg_match('/<input\b[^>]*data-usb-isolated[^>]*\bchecked\b/', $body) === 0,
            'Physical isolation must never be pre-confirmed.');
        $this->assertTrue(preg_match('/<option\b[^>]*value="[^"]+"/', $body) === 0,
            'An empty catalog must not offer an installable release.');
        $this->assertSafe($response);
        $this->assertUsbResponse($this->read($this->base()), 200);
        $this->assertSame($before, $this->state(), 'GET must not create a schedule, binding, command, or audit event.');
    }

    public function testAllUsbReadsAndAuthorizationRequireDatabaseAuthenticationAndCsrf(): void
    {
        $before = $this->state();
        foreach ($this->reads() as $path) {
            $response = $this->read($path, []);
            $this->assertUsbResponse($response, 401);
            $this->assertSafe($response);
        }
        $this->assertUsbResponse($this->submit($this->base() . '/authorize-flash', $this->payload(), false), 403);
        $this->assertUsbResponse($this->submit($this->base() . '/authorize-flash', $this->payload(), true, []), 401);
        $this->assertSame($before, $this->state());
    }

    public function testSuspendedDatabaseUserCannotReuseCachedSuperadminSession(): void
    {
        $this->bmgDb->table('users')->where('id', $this->admin['user']['id'])->update(['status' => 'suspended']);
        $before = $this->state();
        foreach ($this->reads() as $path) { $this->assertUsbResponse($this->read($path), 401); }
        $this->assertUsbResponse($this->submit($this->base() . '/authorize-flash', $this->payload()), 401);
        $this->assertSame($before, $this->state());
    }

    public function testRevokedDatabaseConfigureGrantOverridesCachedSuperadminPermissions(): void
    {
        // Prime a genuine request before revocation; the cached session still claims configure.
        $this->assertUsbResponse($this->read($this->base()), 200);
        $permission = $this->bmgDb->table('permissions')->where('code', 'facilities.configure')->get()->getRowArray();
        $this->assertNotNull($permission);
        $this->bmgDb->table('role_permissions')->where('role', 'superadmin')->where('permission_id', $permission['id'])->delete();
        $before = $this->state();
        foreach ($this->reads() as $path) { $this->assertUsbResponse($this->read($path), 403); }
        $this->assertUsbResponse($this->submit($this->base() . '/authorize-flash', $this->payload()), 403);
        $this->assertSame($before, $this->state());
    }

    public function testFacilitiesOperatorCannotUseForgedCachedConfigurePermission(): void
    {
        $person = $this->insert('people', ['tenant_id' => $this->tenant, 'kind' => 'employee',
            'mis_id' => uniqid('usb_op_'), 'first_name' => 'Isolated', 'last_name' => 'Operator']);
        $user = $this->insert('users', ['tenant_id' => $this->tenant, 'person_id' => $person,
            'username' => uniqid('usb_op_'), 'role' => 'facilities_op', 'status' => 'active']);
        $operator = $this->admin;
        $operator['user']['id'] = $user;
        $operator['user']['role'] = 'facilities_op';
        $permission = $this->bmgDb->table('permissions')->where('code', 'facilities.configure')->get()->getRowArray();
        $this->assertSame(0, $this->bmgDb->table('role_permissions')->where('role', 'facilities_op')
            ->where('permission_id', $permission['id'])->countAllResults());
        $before = $this->state();
        foreach ($this->reads() as $path) { $this->assertUsbResponse($this->read($path, $operator), 403); }
        $this->assertUsbResponse($this->submit($this->base() . '/authorize-flash', $this->payload(), true, $operator), 403);
        $this->assertSame($before, $this->state());
    }

    public function testForeignUnitAndForgedSessionTenantCannotDiscloseWorkspaceOrFirmware(): void
    {
        $foreign = $this->insert('bmg_units', ['tenant_id' => $this->foreignTenant,
            'unit_code' => 'usb-private', 'name' => 'Private USB drum', 'status' => 'active']);
        $before = $this->state();
        foreach ($this->reads($foreign) as $path) {
            $response = $this->read($path);
            $this->assertUsbResponse($response, 404);
            $this->assertTrue(!str_contains($response->response()->getBody(), 'Private USB drum'));
            $this->assertSafe($response);
        }
        $forged = $this->admin;
        $forged['tenant_id'] = $forged['user']['tenant_id'] = $this->foreignTenant;
        foreach ($this->reads($foreign) as $path) { $this->assertUsbResponse($this->read($path, $forged), 401); }
        $this->assertUsbResponse($this->submit($this->base($foreign) . '/authorize-flash', $this->payload(), true, $forged), 401);
        $this->assertSame($before, $this->state());
    }

    public function testEmptyCatalogManifestAndProtectedBinaryFailClosedWithoutWrites(): void
    {
        $before = $this->state();
        foreach (array_slice($this->reads(), 1) as $path) {
            $response = $this->read($path);
            $this->assertUsbResponse($response, 404);
            $this->privateResponse($response);
            $this->assertSafe($response);
            $this->assertTrue(!str_contains($response->response()->getHeaderLine('Content-Type'), 'application/octet-stream'));
        }
        $this->assertSame($before, $this->state());
    }

    public function testTraversalNeverDisclosesPrivateFilesOrCreatesRows(): void
    {
        $before = $this->state();
        foreach (['../.env', '%2e%2e%2f.env', '%252e%252e%252f.env', '..%5c.env', 'release%00'] as $slug) {
            foreach (['manifest', 'parts/0'] as $endpoint) {
                try {
                    $response = $this->read($this->base() . '/releases/' . $slug . '/' . $endpoint);
                    $this->assertUsbResponse($response, 404);
                    $this->assertSafe($response);
                } catch (\CodeIgniter\Exceptions\PageNotFoundException $error) {
                    $this->assertSame(404, $error->getCode());
                } catch (\CodeIgniter\HTTP\Exceptions\BadRequestException $error) {
                    // Invalid encoded separators/NULs are rejected before route matching.
                    $this->assertSame(400, $error->getCode());
                }
            }
        }
        $this->assertSame($before, $this->state());
    }

    public function testDeviceCredentialsNeverAuthorizeProtectedFirmwareEvenWithHumanSession(): void
    {
        $before = $this->state();
        foreach (['X-Device-Token' => $this->token, 'Authorization' => 'Bearer ' . $this->token] as $header => $value) {
            foreach ($this->reads() as $path) {
                $response = $this->withSession($this->admin)->withHeaders(['Accept' => 'application/json', $header => $value])->get($path);
                $this->assertUsbResponse($response, 403);
                $this->assertSafe($response);
            }
        }
        $csrf = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $csrf);
        \Config\Services::resetSingle('security');
        $response = $this->withBodyFormat('')->withSession($this->admin)
            ->withHeaders(['Accept' => 'application/json', 'X-Device-Token' => $this->token])
            ->post($this->base() . '/authorize-flash', $this->payload() + [config('Security')->tokenName => $csrf]);
        $this->assertUsbResponse($response, 403);
        $this->assertSafe($response);
        $this->assertSame($before, $this->state());
    }

    public function testFlashAuthorizationRequiresFreshHardwareGrantAndNeverAuditsRejectedRequests(): void
    {
        $this->hardwareGrant(false);
        $before = $this->state();
        $this->assertUsbResponse($this->submit($this->base() . '/authorize-flash', $this->payload()), 403);
        $this->assertSame($before, $this->state());

        $this->hardwareGrant(true);
        $before = $this->state();
        foreach ([array_replace($this->payload(), ['isolated_confirmed' => '0']),
            $this->payload() + ['tenant_id' => $this->foreignTenant]] as $invalid) {
            $response = $this->submit($this->base() . '/authorize-flash', $invalid);
            $this->assertUsbResponse($response, 422);
            $this->assertSafe($response);
        }
        $this->assertSame($before, $this->state());
        // The empty approved catalog rejects before the later maintenance/flash gate.
        // No firmware files or synthetic production catalog are created by this route suite.
        foreach ([['false', 'false'], ['true', 'true'], ['true', 'false']] as [$flash, $hardware]) {
            $this->setGate('BMG_USB_FLASH_ENABLED', $flash);
            $this->setGate('BMG_HARDWARE_ENABLED', $hardware);
            $before = $this->state();
            $response = $this->submit($this->base() . '/authorize-flash', $this->payload());
            $this->assertUsbResponse($response, 404);
            $this->privateResponse($response);
            $this->assertSafe($response);
            if ($flash === 'false' || $hardware === 'true') {
                $workspace = $this->read($this->base());
                $this->assertUsbResponse($workspace, 200);
                $this->assertTrue(str_contains($workspace->response()->getBody(), 'data-flash-enabled="0"'));
            }
            $this->assertSame($before, $this->state(), 'Rejected requests must not write audits or operational rows.');
        }
    }

    public function call(string $method, string $path, ?array $params = null)
    {
        // FeatureTestTrait reuses the response service across simulated HTTP requests.
        \Config\Services::resetSingle('response');
        return parent::call($method, $path, $params);
    }

    private function base(?int $unit = null): string
    {
        return 'facilities/unit/' . ($unit ?? $this->unit) . '/usb';
    }

    private function reads(?int $unit = null): array
    {
        $base = $this->base($unit);
        return [$base, $base . '/releases/not-approved/manifest', $base . '/releases/not-approved/parts/0'];
    }

    private function payload(): array
    {
        return ['release_id' => 'not-approved', 'chip_family' => 'ESP32-S3',
            'chip_mac' => 'aa:bb:cc:dd:ee:01', 'isolated_confirmed' => '1', 'expected_version' => '0'];
    }

    private function read(string $path, ?array $identity = null, string $accept = 'application/json')
    {
        return $this->withSession($identity ?? $this->admin)->withHeaders(['Accept' => $accept])->get($path);
    }

    private function assertUsbResponse($response, int $expected): void
    {
        // TestResponse failure dumps can contain credentials; compare status only.
        $this->assertSame($expected, $response->response()->getStatusCode());
        $this->assertTrue(str_contains($response->response()->getHeaderLine('Cache-Control'), 'no-store'),
            'Every returned USB response, including filter denials, must prevent caching.');
        $type = strtolower(trim(explode(';', $response->response()->getHeaderLine('Content-Type'))[0]));
        $this->assertSame($expected === 200 ? 'text/html' : 'application/json', $type,
            'Workspace success is HTML; rejected JSON requests must never return firmware bytes or HTML.');
    }

    private function privateResponse($response): void
    {
        $this->assertTrue(str_contains($response->response()->getHeaderLine('Cache-Control'), 'no-store'));
        $this->assertSame('nosniff', $response->response()->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->response()->getHeaderLine('Referrer-Policy'));
    }

    private function assertSafe($response): void
    {
        $body = $response->response()->getBody();
        foreach ([$this->token, hash('sha256', $this->token), WRITEPATH, ROOTPATH, '<?php',
            'device_token_hash', 'database.default.password', 'DB_PASSWORD', 'Stack trace:'] as $secret) {
            $this->assertFalse(str_contains($body, $secret), 'USB response disclosed private data.');
        }
    }

    private function hardwareGrant(bool $enabled): void
    {
        $permission = $this->bmgDb->table('permissions')->where('code', 'facilities.hardware.manage')->get()->getRowArray();
        if ($permission === null) {
            $this->bmgDb->table('permissions')->insert(['code' => 'facilities.hardware.manage',
                'name' => 'Isolated USB hardware grant', 'module' => 'facilities']);
            $permission = ['id' => $this->bmgDb->insertID()];
        }
        $this->bmgDb->table('role_permissions')->where('role', 'superadmin')->where('permission_id', $permission['id'])->delete();
        if ($enabled) {
            $this->bmgDb->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => $permission['id']]);
        }
    }

    private function setGate(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->savedEnvironment)) {
            $this->savedEnvironment[$key] = ['process' => getenv($key),
                '_ENV' => $_ENV[$key] ?? null, '_SERVER' => $_SERVER[$key] ?? null];
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    private function state(): array
    {
        $state = [];
        foreach ($this->bmgDb->listTables() as $table) {
            if (!str_starts_with($table, 'bmg_') && $table !== 'audit_events') { continue; }
            $rows = array_map(static fn (array $row): string => hash('sha256', serialize($row)),
                $this->bmgDb->table($table)->get()->getResultArray());
            sort($rows);
            $state[$table] = $rows;
        }
        ksort($state);
        return $state;
    }
}
