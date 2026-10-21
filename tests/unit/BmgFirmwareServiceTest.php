<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\BmgUsbController;
use App\Services\AuthenticatedUser;
use App\Services\Facilities\BmgFirmwareService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\BmgFirmware;
use Config\Database;
use ReflectionProperty;
use Throwable;

/** Disposable files and SQLite only; no configured database, firmware, or hardware access. */
final class BmgFirmwareServiceTest extends CIUnitTestCase
{
    private BaseConnection $usbDb;
    private array $connections;
    private array $environment = [];
    private string $root;
    private BmgFirmware $catalog;
    private BmgFirmwareService $firmware;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) { $this->markTestSkipped('SQLite3 is required for disposable fixtures.'); }
        foreach (['BMG_USB_FLASH_ENABLED', 'BMG_HARDWARE_ENABLED'] as $key) {
            $this->environment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        $this->gate('BMG_USB_FLASH_ENABLED', 'true');
        $this->gate('BMG_HARDWARE_ENABLED', 'false');
        $this->usbDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000, 'dateFormat' => [
                'date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s']], false);
        $property = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $property->getValue();
        $property->setValue(null, ['tests' => $this->usbDb]);
        $this->fixture();
        service('request')->removeHeader('Authorization');
        service('request')->removeHeader('X-Device-Token');
        $this->signIn();
        $this->root = sys_get_temp_dir() . '/synapse-bmg-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/approved-v1', 0700, true);
        file_put_contents($this->root . '/approved-v1/app.bin', 'disposable-test-bytes');
        $this->catalog = new BmgFirmware();
        $this->catalog->releases = [['id' => 'approved-v1', 'name' => 'Approved release', 'chip_family' => 'ESP32-S3',
            'flash_size' => '16MB', 'parts' => [['name' => 'Application', 'filename' => 'app.bin', 'address' => 65536,
                'sha256' => hash('sha256', 'disposable-test-bytes')]]]];
        $this->firmware = new BmgFirmwareService($this->usbDb, $this->catalog, $this->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->usbDb)) {
            AuthenticatedUser::clear();
            service('request')->removeHeader('Authorization');
            service('request')->removeHeader('X-Device-Token');
            $this->usbDb->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
            foreach ($this->environment as $key => [$env, $server, $process]) {
                if ($env === null) { unset($_ENV[$key]); } else { $_ENV[$key] = $env; }
                if ($server === null) { unset($_SERVER[$key]); } else { $_SERVER[$key] = $server; }
                putenv($process === false ? $key : $key . '=' . $process);
            }
        }
        if (isset($this->root)) {
            foreach (glob($this->root . '/approved-v1/*') ?: [] as $path) { unlink($path); }
            rmdir($this->root . '/approved-v1');
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testEmptyCatalogAndDefaultDisabledWorkspaceAreNormal(): void
    {
        $this->catalog->releases = [];
        $this->gate('BMG_USB_FLASH_ENABLED', 'false');
        $workspace = $this->firmware->workspace(1);
        $this->assertSame([], $workspace['releases']);
        $this->assertFalse($workspace['flashEnabled']);
        $this->assertSame(1, (int) $workspace['detail']['unit']['id']);
        $this->assertSame([], (new BmgFirmware())->releases);
    }

    public function testSanitizedManifestAndBoundedDownloadRehashEveryTime(): void
    {
        $manifest = $this->firmware->manifest(1, 'approved-v1');
        $this->assertSame(65536, $manifest['parts'][0]['address']);
        $this->assertSame(strlen('disposable-test-bytes'), $manifest['parts'][0]['size']);
        $this->assertStringEndsWith('/facilities/unit/1/usb/releases/approved-v1/parts/0', $manifest['parts'][0]['url']);
        $this->assertStringNotContainsString('filename', json_encode($manifest));
        $this->assertStringNotContainsString($this->root, json_encode($manifest));
        $this->assertSame('disposable-test-bytes', $this->firmware->part(1, 'approved-v1', 0)['body']);
        $this->reject(fn () => $this->firmware->part(1, 'approved-v1', 1), 404);
        file_put_contents($this->root . '/approved-v1/app.bin', 'modified-test-bytes!!');
        $this->reject(fn () => $this->firmware->part(1, 'approved-v1', 0), 503);
    }

    public function testStrictCatalogRejectsUnsafeMetadataAndPaths(): void
    {
        $original = $this->catalog->releases;
        foreach (['../app.bin', '/app.bin', 'a/../../app.bin', 'a\\app.bin', 'https://host/file', 'a:stream', "app.bin\0", './app.bin'] as $filename) {
            $this->catalog->releases = $original;
            $this->catalog->releases[0]['parts'][0]['filename'] = $filename;
            $this->reject(fn () => $this->firmware->catalog(), 503);
        }
        foreach (['0x10000', 1.0, -1, 65537, 16777216, true, null] as $address) {
            $this->catalog->releases = $original;
            $this->catalog->releases[0]['parts'][0]['address'] = $address;
            $this->reject(fn () => $this->firmware->catalog(), 503);
        }
        foreach ([['chip_family', 'ESP32'], ['flash_size', '8MB'], ['unexpected', 'value'], ['name', '<script>']] as [$key, $value]) {
            $this->catalog->releases = $original;
            $this->catalog->releases[0][$key] = $value;
            $this->reject(fn () => $this->firmware->catalog(), 503);
        }
        foreach ([['size', 1], ['size', '21'], ['sha256', str_repeat('A', 64)], ['secret', 'no']] as [$key, $value]) {
            $this->catalog->releases = $original;
            $this->catalog->releases[0]['parts'][0][$key] = $value;
            $this->reject(fn () => $this->firmware->catalog(), 503);
        }
        $this->catalog->releases = [$original[0], $original[0]];
        $this->reject(fn () => $this->firmware->catalog(), 503);
    }

    public function testOverlapDuplicatesBoundsAndNonregularFilesFailClosed(): void
    {
        $part = $this->catalog->releases[0]['parts'][0];
        file_put_contents($this->root . '/approved-v1/second.bin', 'disposable-test-bytes');
        foreach ([0, 10] as $offset) {
            $this->catalog->releases[0]['parts'] = [$part, array_replace($part,
                ['name' => 'Second', 'filename' => 'second.bin', 'address' => 65536 + $offset])];
            $this->reject(fn () => $this->firmware->catalog(), 503);
        }
        $this->catalog->releases[0]['parts'] = [$part, $part];
        $this->reject(fn () => $this->firmware->catalog(), 503);
        $this->catalog->releases[0]['parts'] = [array_replace($part, ['address' => 16777215])];
        $this->reject(fn () => $this->firmware->catalog(), 503);
        $this->catalog->releases[0]['parts'] = [array_replace($part, ['filename' => 'missing.bin'])];
        $this->reject(fn () => $this->firmware->catalog(), 503);
        $this->catalog->releases[0]['parts'] = [array_replace($part, ['filename' => 'folder'])];
        mkdir($this->root . '/approved-v1/folder');
        try { $this->reject(fn () => $this->firmware->catalog(), 503); }
        finally { rmdir($this->root . '/approved-v1/folder'); }
    }

    public function testSectorEraseRangesAndFlashBoundary(): void
    {
        $original = $this->catalog->releases[0]['parts'][0];
        file_put_contents($this->root . '/approved-v1/second.bin', 'second');
        $second = ['name' => 'Second', 'filename' => 'second.bin', 'address' => 69632, 'sha256' => hash('sha256', 'second')];
        foreach ([1, 4095, 4096, 4097] as $size) {
            $bytes = str_repeat('x', $size);
            file_put_contents($this->root . '/approved-v1/app.bin', $bytes);
            clearstatcache();
            $first = array_replace($original, ['sha256' => hash('sha256', $bytes)]);
            $this->catalog->releases[0]['parts'] = [$first, $second];
            if ($size > 4096) {
                $this->reject(fn () => $this->firmware->catalog(), 503);
                // Even byte-disjoint parts cannot start inside the erased sector.
                $this->catalog->releases[0]['parts'][1]['address'] = 65536 + $size;
                $this->reject(fn () => $this->firmware->catalog(), 503);
                $this->catalog->releases[0]['parts'][1]['address'] = 73728;
            }
            $this->assertCount(1, $this->firmware->catalog());
            // Ordering of catalog parts does not affect overlap validation.
            $this->catalog->releases[0]['parts'] = array_reverse($this->catalog->releases[0]['parts']);
            $this->assertCount(1, $this->firmware->catalog());
            $this->catalog->releases[0]['parts'] = [array_replace($first, ['address' => 16773120])];
            if ($size > 4096) { $this->reject(fn () => $this->firmware->catalog(), 503); }
            else { $this->assertCount(1, $this->firmware->catalog()); }
        }
    }

    public function testWorkspaceReadinessDoesNotRequireHardwareGrantForAccess(): void
    {
        $this->assertTrue($this->firmware->workspace(1)['flashEnabled']);
        $this->usbDb->table('role_permissions')->where('permission_id', 3)->delete();
        $this->assertFalse($this->firmware->workspace(1)['flashEnabled']);
        $this->assertSame('approved-v1', $this->firmware->manifest(1, 'approved-v1')['id']);
        $this->reject(fn () => $this->firmware->authorize(1, $this->input()), 403);
        $this->usbDb->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => 3]);
        foreach ([['bmg_units', 'status', 'active', 'maintenance'], ['bmg_rotation_schedules', 'enabled', 1, 0],
            ['bmg_devices', 'status', 'revoked', 'active'], ['bmg_devices', 'chip_mac', 'invalid', 'aa:bb:cc:dd:ee:01']] as [$table, $key, $bad, $good]) {
            $this->usbDb->table($table)->where('id', 1)->update([$key => $bad]);
            $this->assertFalse($this->firmware->workspace(1)['flashEnabled']);
            $this->usbDb->table($table)->where('id', 1)->update([$key => $good]);
        }
        $this->usbDb->table('bmg_rotation_commands')->insert(['tenant_id' => 1, 'unit_id' => 1, 'status' => 'pending', 'expires_at' => '2099-01-01 00:00:00']);
        $this->assertFalse($this->firmware->workspace(1)['flashEnabled']);
        $this->usbDb->table('bmg_rotation_commands')->update(['expires_at' => '2000-01-01 00:00:00']);
        $this->assertTrue($this->firmware->workspace(1)['flashEnabled']);
        $this->usbDb->table('bmg_rotation_schedules')->where('id', 1)->delete();
        $this->assertFalse($this->firmware->workspace(1)['flashEnabled']);
    }

    public function testSymlinkIsRejectedWhereSupported(): void
    {
        $link = $this->root . '/approved-v1/linked.bin';
        if (!@symlink($this->root . '/approved-v1/app.bin', $link)) { $this->markTestSkipped('Symlink creation not permitted.'); }
        $this->catalog->releases[0]['parts'][0]['filename'] = 'linked.bin';
        $this->reject(fn () => $this->firmware->catalog(), 503);
    }

    public function testTotalBytesAreBoundedBeforeReadingOversizedFile(): void
    {
        $handle = fopen($this->root . '/approved-v1/app.bin', 'wb');
        ftruncate($handle, 16777217);
        fclose($handle);
        $this->reject(fn () => $this->firmware->catalog(), 503);
    }

    public function testFreshGrantsTenantRolesAndTokenIsolation(): void
    {
        foreach (['Authorization', 'X-Device-Token'] as $header) {
            service('request')->setHeader($header, 'secret-test-only');
            $this->reject(fn () => $this->firmware->workspace(1), 403);
            $this->reject(fn () => $this->firmware->manifest(1, 'approved-v1'), 403);
            $this->reject(fn () => $this->firmware->part(1, 'approved-v1', 0), 403);
            $this->reject(fn () => $this->firmware->authorize(1, $this->input()), 403);
            service('request')->removeHeader($header);
        }
        $this->reject(fn () => $this->firmware->manifest(2, 'approved-v1'), 404);
        $this->reject(fn () => $this->firmware->authorize(2, $this->input()), 404);
        $this->reject(fn () => $this->firmware->manifest(1, '../approved-v1'), 404);
        foreach ([1, 2, 4] as $permission) {
            $this->usbDb->table('role_permissions')->where('permission_id', $permission)->delete();
            $this->signIn(); // Cached forged grants cannot replace persisted grants.
            $this->reject(fn () => $this->firmware->workspace(1), 403);
            $this->reject(fn () => $this->firmware->manifest(1, 'approved-v1'), 403);
            $this->reject(fn () => $this->firmware->part(1, 'approved-v1', 0), 403);
            $this->reject(fn () => $this->firmware->authorize(1, $this->input()), 403);
            $this->usbDb->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => $permission]);
        }
        $this->usbDb->table('users')->where('id', 1)->update(['status' => 'suspended']);
        $this->reject(fn () => $this->firmware->manifest(1, 'approved-v1'), 403);
    }

    public function testAuthorizationRecordsRequestOnlyWithoutSecretsOrStateChanges(): void
    {
        $result = $this->firmware->authorize(1, $this->input());
        $this->assertTrue($result['success']);
        $this->assertSame('approved-v1', $result['manifest']['id']);
        $event = $this->usbDb->table('audit_events')->get()->getRowArray();
        $this->assertSame('bmg_usb.flash_requested', $event['event_type']);
        $this->assertSame(1, (int) $event['actor_user_id']);
        $payload = json_decode($event['payload_json'], true);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $payload['release_sha256']);
        $this->assertSame(1, $payload['device_id']);
        $this->assertStringNotContainsString('chip_mac', $event['payload_json']);
        $this->assertStringNotContainsString('token', $event['payload_json']);
        $this->assertSame(0, $this->usbDb->table('bmg_rotation_commands')->countAllResults());
        $this->assertSame(7, (int) $this->usbDb->table('bmg_rotation_schedules')->get()->getRowArray()['version']);
    }

    public function testEveryFlashGateAndSafetyConditionIsRequired(): void
    {
        foreach (['BMG_USB_FLASH_ENABLED' => 'false', 'BMG_HARDWARE_ENABLED' => 'true'] as $key => $value) {
            $this->gate($key, $value);
            $this->reject(fn () => $this->firmware->authorize(1, $this->input()), 409);
            $this->gate($key, $key === 'BMG_USB_FLASH_ENABLED' ? 'true' : 'false');
        }
        $this->usbDb->table('role_permissions')->where('permission_id', 3)->delete();
        $this->reject(fn () => $this->firmware->authorize(1, $this->input()), 403);
        $this->usbDb->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => 3]);
        $this->reject(fn () => $this->firmware->authorize(1, array_replace($this->input(), ['expected_version' => 6])), 409);
        $this->reject(fn () => $this->firmware->authorize(1, array_replace($this->input(), ['chip_mac' => 'aa:bb:cc:dd:ee:02'])), 409);
        foreach ([['bmg_units', 'status', 'active', 'maintenance'], ['bmg_rotation_schedules', 'enabled', 1, 0],
            ['bmg_devices', 'status', 'revoked', 'active'], ['bmg_devices', 'rotation_bound_at', null, '2026-01-01 00:00:00']] as [$table, $field, $bad, $good]) {
            $this->usbDb->table($table)->where('id', 1)->update([$field => $bad]);
            $this->reject(fn () => $this->firmware->authorize(1, $this->input()), 409);
            $this->usbDb->table($table)->where('id', 1)->update([$field => $good]);
        }
        $this->usbDb->table('bmg_rotation_commands')->insert(['tenant_id' => 1, 'unit_id' => 1, 'status' => 'pending', 'expires_at' => '2099-01-01 00:00:00']);
        $this->reject(fn () => $this->firmware->authorize(1, $this->input()), 409);
        $this->assertSame(0, $this->usbDb->table('audit_events')->countAllResults());
        $this->usbDb->table('bmg_rotation_commands')->update(['expires_at' => '2000-01-01 00:00:00']);
        $this->assertTrue($this->firmware->authorize(1, $this->input())['success']);
    }

    public function testStrictAuthorizationInputAndAuditFailure(): void
    {
        foreach ([['isolated_confirmed' => false], ['isolated_confirmed' => 'yes'], ['chip_family' => 'ESP32'],
            ['chip_mac' => 'invalid'], ['expected_version' => 7.0], ['expected_version' => '7e0'], ['source' => 'code']] as $change) {
            $this->reject(fn () => $this->firmware->authorize(1, array_replace($this->input(), $change)), 0);
        }
        $this->usbDb->query('DROP TABLE audit_events');
        $this->reject(fn () => $this->firmware->authorize(1, $this->input()), 503);
    }

    public function testControllerUsesNoStoreBinaryAndSafeJsonContracts(): void
    {
        $controller = new BmgUsbController();
        (new ReflectionProperty($controller, 'firmware'))->setValue($controller, $this->firmware);
        $controller->initController(service('request'), service('response'), service('logger'));
        $response = $controller->part(1, 'approved-v1', 0);
        $this->assertSame('disposable-test-bytes', $response->getBody());
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('application/octet-stream', $response->getHeaderLine('Content-Type'));
        $response = $controller->manifest(1, '../private');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertArrayHasKey('csrf', json_decode($response->getBody(), true));
        $this->assertStringNotContainsString($this->root, $response->getBody());
    }

    public function testControllerAcceptsFormAndJsonWithCsrfResponse(): void
    {
        $request = service('request');
        $controller = new BmgUsbController();
        (new ReflectionProperty($controller, 'firmware'))->setValue($controller, $this->firmware);
        $controller->initController($request, service('response'), service('logger'));
        service('response')->setStatusCode(200);
        $request->setMethod('POST');
        try {
            $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
            $request->setGlobal('post', array_replace($this->input(), ['expected_version' => '7', 'isolated_confirmed' => '1'])
                + [csrf_token() => csrf_hash()]);
            $response = $controller->authorizeFlash(1);
            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertSame(csrf_token(), $body['csrf']['name']);
            $this->assertSame(csrf_hash(), $body['csrf']['hash']);
            $request->setHeader('Content-Type', 'application/json');
            $request->setBody(json_encode($this->input()));
            $response = $controller->authorizeFlash(1);
            $this->assertTrue(json_decode($response->getBody(), true)['success']);
            $request->setBody('{invalid');
            $response = $controller->authorizeFlash(1);
            $this->assertSame(422, $response->getStatusCode());
            $this->assertFalse(json_decode($response->getBody(), true)['success']);
        } finally {
            $request->setMethod('GET');
            $request->setBody('');
            $request->setGlobal('post', []);
            $request->removeHeader('Content-Type');
        }
    }

    public function testControllerBoundsJsonBeforeDecoding(): void
    {
        $request = service('request');
        $controller = new BmgUsbController();
        (new ReflectionProperty($controller, 'firmware'))->setValue($controller, $this->firmware);
        $controller->initController($request, service('response'), service('logger'));
        $request->setHeader('Content-Type', 'application/json');
        try {
            $json = json_encode($this->input());
            foreach ([['', str_pad($json, 8193)], ['8193', $json], ['9999999999999999999999', $json],
                ['invalid', $json], ['1', str_pad($json, 8193)]] as [$length, $body]) {
                $request->setHeader('Content-Length', $length);
                $request->setBody($body);
                $response = $controller->authorizeFlash(1);
                $this->assertSame(422, $response->getStatusCode());
                $this->assertFalse(json_decode($response->getBody(), true)['success']);
            }
            $this->assertSame(0, $this->usbDb->table('audit_events')->countAllResults());
            foreach (['', '8192'] as $length) {
                service('response')->setStatusCode(200);
                $request->setHeader('Content-Length', $length);
                $request->setBody(str_pad($json, 8192));
                $response = $controller->authorizeFlash(1);
                $this->assertSame(200, $response->getStatusCode());
                $this->assertTrue(json_decode($response->getBody(), true)['success']);
            }
        } finally {
            $request->setBody('');
            $request->removeHeader('Content-Type');
            $request->removeHeader('Content-Length');
        }
    }

    private function input(): array
    {
        return ['release_id' => 'approved-v1', 'chip_family' => 'ESP32-S3', 'chip_mac' => 'AA:BB:CC:DD:EE:01',
            'isolated_confirmed' => true, 'expected_version' => 7];
    }

    private function gate(string $key, string $value): void
    {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
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
        $this->fail('Expected rejection: ' . $code);
    }

    private function fixture(): void
    {
        foreach ([
            'CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)',
            'CREATE TABLE users (auth_version INTEGER NOT NULL DEFAULT 1, id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, role TEXT, username TEXT, email TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY, code TEXT)',
            'CREATE TABLE role_permissions (role TEXT, permission_id INTEGER)',
            'CREATE TABLE bmg_units (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE bmg_devices (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, chip_mac TEXT, status TEXT, revoked_at TEXT, rotation_bound_at TEXT, reported_firmware_version TEXT, rtc_valid INTEGER, applied_schedule_version INTEGER)',
            'CREATE TABLE bmg_rotation_schedules (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, enabled INTEGER, version INTEGER, times_json TEXT, duration_seconds INTEGER, pwm INTEGER)',
            'CREATE TABLE bmg_rotation_commands (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, status TEXT, expires_at TEXT)',
            'CREATE TABLE bmg_rotation_runs (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER)',
            'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, event_type TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT NOT NULL, payload_json TEXT, prev_hash TEXT NOT NULL, curr_hash TEXT NOT NULL, hash_version INTEGER NOT NULL DEFAULT 1, occurred_at TEXT NOT NULL)',
            'INSERT INTO tenants (id, is_active) VALUES (1, 1), (2, 1)',
            "INSERT INTO users (id, tenant_id, role, username, status) VALUES (1, 1, 'superadmin', 'usb-admin', 'active')",
            "INSERT INTO permissions VALUES (1, 'facilities.read'), (2, 'facilities.manage'), (3, 'facilities.hardware.manage'), (4, 'facilities.configure')",
            "INSERT INTO role_permissions VALUES ('superadmin', 1), ('superadmin', 2), ('superadmin', 3), ('superadmin', 4)",
            "INSERT INTO bmg_units VALUES (1, 1, 'Drum', 'maintenance', NULL), (2, 2, 'Other tenant', 'maintenance', NULL)",
            "INSERT INTO bmg_devices (id, tenant_id, unit_id, chip_mac, status, revoked_at, rotation_bound_at) VALUES (1, 1, 1, 'aa:bb:cc:dd:ee:01', 'active', NULL, '2026-01-01 00:00:00')",
            "INSERT INTO bmg_rotation_schedules VALUES (1, 1, 1, 0, 7, '[]', 60, NULL)",
        ] as $sql) { $this->usbDb->query($sql); }
    }
}
