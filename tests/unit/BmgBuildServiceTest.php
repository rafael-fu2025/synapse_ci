<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuthenticatedUser;
use App\Services\Facilities\BmgBuildService;
use App\Services\Facilities\BmgFirmwareService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\BmgBuild;
use Config\BmgFirmware;
use Config\Database;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/** Isolated SQLite, disposable private artifacts, and a fake compiler: no hardware or network. */
final class BmgBuildServiceTest extends CIUnitTestCase
{
    private BaseConnection $buildDb;
    private array $connections;
    private string $root;
    private BmgBuild $config;
    private BmgBuildService $builds;
    private BmgFirmwareService $firmware;
    private array $calls = [];
    private string $recipe = '--chip esp32s3 --port "{serial.port}" --baud 921600 --before default-reset --after hard-reset write-flash -z --flash-mode keep --flash-freq keep --flash-size keep 0x0 "@WORK@/compost.ino.bootloader.bin" 0x8000 "@WORK@/compost.ino.partitions.bin" 0xe000 "@CORE@/tools/partitions/boot_app0.bin" 0x10000 "@WORK@/compost.ino.bin"';
    private string $header = '';
    private string $failure = '';
    private mixed $duringCompile = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) { $this->markTestSkipped('Disposable SQLite fixtures require SQLite3.'); }
        $this->buildDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000, 'dateFormat' => [
                'date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s']], false);
        $property = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $property->getValue();
        $property->setValue(null, ['tests' => $this->buildDb]);
        $this->fixture();
        service('request')->removeHeader('Authorization');
        service('request')->removeHeader('X-Device-Token');
        $this->signIn();
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/bmg-build-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
        $this->root = str_replace('\\', '/', realpath($this->root));
        mkdir($this->coreRoot() . '/tools/partitions', 0700, true);
        file_put_contents($this->coreRoot() . '/tools/partitions/boot_app0.bin', 'trusted-core-boot-app0');
        $this->config = new BmgBuild();
        $this->config->enabled = true;
        // Only a verified executable is accepted even with an injected runner. PHP itself is never executed here.
        $this->config->cli = realpath(PHP_BINARY);
        $this->config->signingKey = str_repeat('test-only-independent-key-', 2);
        $this->builds = new BmgBuildService($this->buildDb, $this->config, $this->root, function (array $argv, string $cwd, int $timeout): array {
            $this->calls[] = $argv;
            if ($argv[1] === 'core') {
                return ['exit_code' => 0, 'stdout' => json_encode(['platforms' => [['id' => 'esp32:esp32', 'installed_version' => $this->failure === 'core' ? '3.3.2' : '3.3.3']]])];
            }
            if ($argv[1] === 'lib') {
                return ['exit_code' => 0, 'stdout' => json_encode(['installed_libraries' => [['library' => ['name' => 'ArduinoJson', 'version' => $this->failure === 'lib' ? '7.4.1' : '7.4.2']]]])];
            }
            if ($argv[1] === 'config') { return ['exit_code' => 0, 'stdout' => json_encode($this->root . '/arduino-data')]; }
            if (in_array('--show-properties=expanded', $argv, true)) {
                $this->assertSame(15, $timeout);
                return ['exit_code' => 0, 'stdout' => $this->compilerMetadata($cwd)];
            }
            $this->assertSame(180, $timeout);
            $this->assertSame(['compost.ino', 'commissioning_policy.h', 'device_config.h'], array_values(array_intersect(['compost.ino', 'commissioning_policy.h', 'device_config.h'], scandir($cwd . '/compost'))));
            foreach (BmgBuild::SOURCES as $file => $hash) { $this->assertSame($hash, hash_file('sha256', $cwd . '/compost/' . $file)); }
            $this->header = file_get_contents($cwd . '/compost/device_config.h');
            if ($this->duringCompile !== null) { ($this->duringCompile)(); }
            if ($this->failure === 'exception') { throw new \RuntimeException('secret compiler diagnostic ' . $this->input()['device_token']); }
            if ($this->failure === 'compile') { return ['exit_code' => 1, 'stdout' => 'secret compiler diagnostic']; }
            foreach (['compost.ino.bootloader.bin', 'compost.ino.partitions.bin', 'compost.ino.bin'] as $file) {
                file_put_contents($cwd . '/work/' . $file, 'private-test-binary');
            }
            file_put_contents($cwd . '/out/compost.ino.merged.bin', 'must-never-be-flashed');
            file_put_contents($cwd . '/work/private.o', 'intermediate with private credentials');
            return ['exit_code' => 0, 'stdout' => 'discarded compile output'];
        });
        $this->firmware = new BmgFirmwareService($this->buildDb, new BmgFirmware(), $this->root, $this->builds);
    }

    protected function tearDown(): void
    {
        if (isset($this->buildDb)) {
            AuthenticatedUser::clear();
            service('request')->removeHeader('Authorization');
            service('request')->removeHeader('X-Device-Token');
            $this->buildDb->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        }
        if (isset($this->root)) { $this->remove($this->root); }
        parent::tearDown();
    }

    public function testDefaultsAndLocalDependencyReadinessFailClosed(): void
    {
        $defaults = (new \ReflectionClass(BmgBuild::class))->getDefaultProperties();
        $this->assertFalse($defaults['enabled']);
        $this->assertSame('', $defaults['cli']);
        $this->assertSame('', $defaults['signingKey']);
        $this->assertTrue($this->builds->status(1)['available']);
        $this->assertFalse($this->builds->status(1)['motor_outputs_enabled']);
        foreach (['enabled' => false, 'cli' => 'arduino-cli', 'signingKey' => 'short', 'fqbn' => 'esp32:esp32:esp32', 'timeoutSeconds' => 181] as $key => $value) {
            $old = $this->config->$key;
            $this->config->$key = $value;
            $this->assertFalse($this->builds->status(1)['available']);
            $this->config->$key = $old;
        }
        foreach (['core', 'lib'] as $failure) {
            $this->failure = $failure;
            $this->assertFalse($this->builds->status(1)['available']);
            $this->reject(fn () => $this->builds->build(1, $this->input()), 503);
        }
        foreach ($this->calls as $call) { $this->assertNotContains('install', $call); }
    }

    public function testSuccessfulBuildIsPrivateAndRetainsOnlySignedManifestAndBinaries(): void
    {
        // No hardware permission is granted by this fixture.
        $result = $this->builds->build(1, $this->input());
        $id = $result['release']['id'];
        $this->assertTrue($result['success']);
        $this->assertMatchesRegularExpression('/\Abuild-[0-9a-f]{32}\z/', $id);
        $this->assertSame(['id', 'name', 'chip_family', 'flash_size'], array_keys($result['release']));
        $this->assertSame(['manifest.json', 'part-0.bin', 'part-1.bin', 'part-2.bin', 'part-3.bin'], array_values(array_diff(scandir($this->root . '/' . $id), ['.', '..'])));
        $this->assertSame([], $this->firmware->catalog());
        $manifest = $this->firmware->manifest(1, $id);
        $this->assertSame([0, 32768, 57344, 65536], array_column($manifest['parts'], 'address'));
        $this->assertSame('private-test-binary', $this->firmware->part(1, $id, 0)['body']);
        $this->assertSame($id, $this->builds->privateReleases(1, $this->actor())[0]['id']);
        $json = json_encode([$result, $manifest]);
        foreach (['device_token', 'wifi_password', 'wifi_ssid', 'source_hash', 'filename', $this->root, $this->input()['device_token']] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $saved = file_get_contents($this->root . '/' . $id . '/manifest.json');
        $this->assertStringNotContainsString($this->input()['device_token'], $saved);
        $this->assertSame(0, $this->buildDb->table('bmg_rotation_commands')->countAllResults());
        $this->assertSame(7, (int) $this->buildDb->table('bmg_rotation_schedules')->get()->getRowArray()['version']);
        $compile = $this->calls[3];
        $this->assertSame(BmgBuild::FQBN, $compile[3]);
        $this->assertContains('--json', $compile);
        $this->assertContains('--export-binaries', $compile);
        $this->assertStringNotContainsString($this->input()['device_token'], json_encode($this->calls));
    }

    public function testByteOctalHeaderCannotInjectSource(): void
    {
        $input = array_replace($this->input(), ['wifi_ssid' => "\";\n#include <evil>\n\\", 'wifi_password' => '"\\secret-password']);
        $this->builds->build(1, $input);
        $this->assertStringNotContainsString('#include', $this->header);
        $this->assertStringNotContainsString('secret-password', $this->header);
        $this->assertStringContainsString('\\042\\073\\012', $this->header);
        $this->assertStringContainsString('#define BMG_ALLOW_HTTP true', $this->header);
        preg_match('/WIFI_SSID\[\] = "([^"]*)";/', $this->header, $match);
        $decoded = preg_replace_callback('/\\\\([0-7]{3})/', static fn ($m) => chr(octdec($m[1])), $match[1]);
        $this->assertSame($input['wifi_ssid'], $decoded);
    }

    public function testStrictInputsRejectSourcePathsCoercionAndCredentialBounds(): void
    {
        foreach ([['source' => 'injected'], ['fqbn' => BmgBuild::FQBN], ['path' => '/tmp/evil'], ['shell' => 'true'],
            ['wifi_ssid' => ''], ['wifi_ssid' => str_repeat('é', 17)], ['wifi_password' => ''], ['wifi_password' => '1234567'],
            ['wifi_password' => str_repeat('g', 64)], ['wifi_password' => str_repeat('a', 65)], ['wifi_ssid' => "x\0y"],
            ['device_token' => str_repeat('A', 64)], ['device_token' => str_repeat('a', 63)], ['device_token' => []],
            ['allow_http' => 'true'], ['allow_http' => false], ['physical_isolation' => 'true'], ['physical_isolation' => false],
            ['expected_version' => 7.0], ['expected_version' => '7e0'], ['expected_version' => -1], ['root_ca_pem' => str_repeat('x', 16385)]] as $change) {
            $this->reject(fn () => $this->builds->build(1, array_replace($this->input(), $change)), 0);
        }
        $this->assertSame([], $this->calls);
        $this->assertTrue($this->builds->build(1, array_replace($this->input(), ['wifi_password' => str_repeat('a', 64)]))['success']);
    }

    public function testUnsafeUrlsAndMissingHttpsCaAreRejectedWithoutRequests(): void
    {
        foreach (['file:///etc/passwd', 'ftp://host', 'http://localhost', 'http://localhost:8080', 'http://127.0.0.2',
            'http://0.0.0.0', 'http://[::1]', 'http://[::]', 'http://[0:0:0:0:0:0:0:1]', 'http://[::ffff:127.0.0.1]', 'http://2130706433',
            'http://0177.0.0.1', 'http://0x7f000001', 'http://user:pass@host', 'http://host?secret=yes', 'http://host#x',
            'http://host/a/../b', 'http://host/%2e%2e', 'http://host/%252e%252e', 'http://host/a\\b', 'http://host/a b',
            'https://host', 'http://host:0', 'http://host:65536', 'http://host//b'] as $url) {
            $this->reject(fn () => $this->builds->build(1, array_replace($this->input(), ['api_base_url' => $url])), 0);
        }
        $this->assertSame([], $this->calls);
        $this->assertTrue($this->builds->build(1, array_replace($this->input(), ['api_base_url' => 'http://192.168.1.2:8080/humus/']))['success']);
        $this->assertTrue($this->builds->build(1, array_replace($this->input(), ['api_base_url' => 'http://humus.lan/deploy']))['success']);
    }

    public function testHttpsRequiresParsedCertificateAndDoesNotEnableHttp(): void
    {
        if (!extension_loaded('openssl')) { $this->markTestSkipped('OpenSSL required.'); }
        $config = $this->root . '/openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name = req_dn\n[req_dn]\n");
        $options = ['private_key_bits' => 2048, 'config' => $config];
        $key = @openssl_pkey_new($options);
        if ($key === false) { $this->markTestSkipped('Local OpenSSL key generation unavailable.'); }
        $csr = @openssl_csr_new(['commonName' => 'Disposable BMG root'], $key, $options);
        if ($csr === false) { $this->markTestSkipped('Local OpenSSL config unavailable.'); }
        $cert = openssl_csr_sign($csr, null, $key, 1, $options);
        openssl_x509_export($cert, $pem);
        $this->assertTrue($this->builds->build(1, array_replace($this->input(), ['api_base_url' => 'https://humus.example/humus', 'allow_http' => false, 'root_ca_pem' => $pem]))['success']);
        $this->assertStringContainsString('#define BMG_ROOT_CA_PEM ', $this->header);
        $this->assertStringNotContainsString('BMG_ALLOW_HTTP', $this->header);
        foreach (['garbage', "-----BEGIN CERTIFICATE-----\nYWJj\n-----END CERTIFICATE-----", $pem . $pem] as $bad) {
            $this->reject(fn () => $this->builds->build(1, array_replace($this->input(), ['api_base_url' => 'https://host', 'root_ca_pem' => $bad])), 0);
        }
    }

    public function testFreshAuthRolePermissionsTenantAndDeviceTokenIsolation(): void
    {
        foreach (['Authorization', 'X-Device-Token'] as $header) {
            service('request')->setHeader($header, 'not-human');
            $this->reject(fn () => $this->builds->status(1), 403);
            $this->reject(fn () => $this->builds->build(1, $this->input()), 403);
            service('request')->removeHeader($header);
        }
        $this->reject(fn () => $this->builds->status(2), 404);
        $this->reject(fn () => $this->builds->build(2, $this->input()), 404);
        foreach ([1, 2, 3] as $permission) {
            $this->buildDb->table('role_permissions')->where('permission_id', $permission)->delete();
            $this->signIn(); // Forged cached grants do not replace persisted authority.
            $this->reject(fn () => $this->builds->status(1), 403);
            $this->reject(fn () => $this->builds->build(1, $this->input()), 403);
            $this->buildDb->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => $permission]);
        }
        $this->reject(fn () => $this->builds->build(1, array_replace($this->input(), ['device_token' => str_repeat('b', 64)])), 409);
        $this->buildDb->table('users')->where('id', 1)->update(['status' => 'suspended']);
        $this->reject(fn () => $this->builds->status(1), 403);
        $this->assertSame([], $this->calls);
    }

    public function testAllSafetyConditionsAndPostCompileRecheck(): void
    {
        foreach ([['bmg_units', 'status', 'active', 'maintenance'], ['bmg_rotation_schedules', 'enabled', 1, 0],
            ['bmg_rotation_schedules', 'version', 8, 7], ['bmg_devices', 'status', 'revoked', 'active'],
            ['bmg_devices', 'rotation_bound_at', null, '2026-01-01 00:00:00'], ['bmg_devices', 'revoked_at', '2026-01-01', null]] as [$table, $field, $bad, $good]) {
            $this->buildDb->table($table)->where('id', 1)->update([$field => $bad]);
            $this->reject(fn () => $this->builds->build(1, $this->input()), 409);
            $this->buildDb->table($table)->where('id', 1)->update([$field => $good]);
        }
        $this->buildDb->table('bmg_rotation_commands')->insert(['tenant_id' => 1, 'unit_id' => 1, 'status' => 'pending', 'expires_at' => '2099-01-01 00:00:00']);
        $this->reject(fn () => $this->builds->build(1, $this->input()), 409);
        $this->buildDb->table('bmg_rotation_commands')->where('unit_id', 1)->delete();
        $this->duringCompile = function (): void { $this->buildDb->table('bmg_rotation_schedules')->update(['version' => 8]); };
        $this->reject(fn () => $this->builds->build(1, $this->input()), 409);
        $this->assertSame([], glob($this->root . '/build-*'));
    }

    public function testCompilerFailureMissingRecipeAndUnsafeRecipesCleanEverySecret(): void
    {
        foreach (['compile', 'exception', 'no-recipe'] as $failure) {
            $this->failure = $failure;
            $this->reject(fn () => $this->builds->build(1, $this->input()), 503);
            $this->assertSame([], glob($this->root . '/build-*'));
        }
        $this->failure = '';
        $valid = $this->recipe;
        foreach ([
            str_replace('0x10000', '0x10001', $valid),
            str_replace('0x10000', '0x0', $valid),
            str_replace('0x10000', '0x1000000', $valid),
            str_replace('compost.ino.bin', 'compost.ino.merged.bin', $valid),
            str_replace('--flash-size keep', '--flash-size keep --flash_size keep', $valid),
            $valid . ' write_flash', $valid . ' --port COM3',
            str_replace('@WORK@/compost.ino.bin', '@WORK@/../out/compost.ino.merged.bin', $valid),
            '', '0x10000 ../outside.bin', '0x10000 /outside.bin', '0x10000 a/../../outside.bin',
            '--port COM3 0x10000 app.bin', '@other-args', '0x10001 app.bin', '0x1000000 app.bin',
            '0x10000 app.bin 0x10000 app.bin', '--flash_size 8MB 0x10000 app.bin', '0x10000 app.bin"', '0x10000 https://host/file.bin'] as $recipe) {
            $this->recipe = $recipe;
            $this->reject(fn () => $this->builds->build(1, $this->input()), 503);
            $this->assertSame([], glob($this->root . '/build-*'));
        }
    }

    public function testExpandedUploadRecipeReadsSparsePathsNotMergedOrBuildOptions(): void
    {
        $job = $this->parserJob();
        mkdir($job . '/out/nested', 0700);
        rename($job . '/work/compost.ino.bin', $job . '/out/nested/compost.ino.bin');
        $this->recipe = str_replace(['@WORK@/compost.ino.bin', '0x0 ', '0x8000 ', '0xe000 ', '0x10000 '],
            ['@OUT@/nested/compost.ino.bin', '0x2000 ', '0x9000 ', '0xf000 ', '0x20000 '], $this->recipe);
        // Match actual Windows CLI metadata: quoted mixed-separator paths and an
        // unresolved serial placeholder before write-flash. Never resolve a port.
        $this->recipe = str_replace('/', '\\', $this->recipe);
        $metadata = $this->compilerMetadata($job);
        $parts = (new ReflectionMethod($this->builds, 'parts'))->invoke($this->builds, $job, $metadata, $this->coreRoot());
        $this->assertSame([8192, 36864, 61440, 131072], array_column($parts, 'address'));
        $this->assertCount(4, $parts);
        $this->assertSame('trusted-core-boot-app0', file_get_contents($job . '/' . $parts[2]['filename']));
        foreach ($parts as $part) { $this->assertStringNotContainsString('merged', file_get_contents($job . '/' . $part['filename'])); }
        $data = json_decode($metadata, true);
        foreach ($data['builder_result']['build_properties'] as &$property) {
            if (str_starts_with($property, 'tools.esptool_py.upload.pattern=')) {
                $property = 'tools.esptool_py.upload.pattern="trusted-esptool.exe" ' . str_replace(['@WORK@', '@OUT@', '@CORE@'],
                    [$job . '/work', $job . '/out', $this->coreRoot()], str_replace('write-flash', 'write_flash', $this->recipe));
            }
        }
        unset($property);
        $again = (new ReflectionMethod($this->builds, 'parts'))->invoke($this->builds, $job, json_encode($data), $this->coreRoot());
        $this->assertSame($parts, $again);
    }

    public function testBadExpandedMetadataFalsePathsAndDuplicatePartsFailClosed(): void
    {
        $parse = new ReflectionMethod($this->builds, 'parts');
        $job = $this->parserJob();
        $valid = json_decode($this->compilerMetadata($job), true);
        $bad = [];
        $change = $valid; $change['success'] = false; $bad[] = $change;
        $change = $valid; unset($change['builder_result']); $bad[] = $change;
        $change = $valid; $change['builder_result']['build_path'] = $this->root; $bad[] = $change;
        foreach (['board_platform', 'build_platform'] as $key) {
            $change = $valid; $change['builder_result'][$key]['install_dir'] = $this->root; $bad[] = $change;
            $change = $valid; $change['builder_result'][$key]['version'] = '3.3.2'; $bad[] = $change;
            $change = $valid; $change['builder_result'][$key]['id'] = 'other:esp32'; $bad[] = $change;
        }
        $change = $valid; $change['builder_result']['build_properties'][] = 'runtime.platform.path=' . $this->coreRoot(); $bad[] = $change;
        $change = $valid; $change['builder_result']['build_properties'][] = 'no-equals'; $bad[] = $change;
        $change = $valid; $change['builder_result']['build_properties'][] = []; $bad[] = $change;
        foreach (['build.fqbn' => 'esp32:esp32:esp32s3', 'build.path' => $this->root,
            'build.source.path' => $this->root, 'build.mcu' => 'esp32', 'build.flash_size' => '8MB',
            'runtime.platform.path' => $this->root] as $key => $value) {
            $change = $valid;
            foreach ($change['builder_result']['build_properties'] as &$property) {
                if (str_starts_with($property, $key . '=')) { $property = $key . '=' . $value; }
            }
            unset($property); $bad[] = $change;
        }
        foreach ($bad as $metadata) {
            $this->reject(fn () => $parse->invoke($this->builds, $job, json_encode($metadata), $this->coreRoot()), 503);
        }
        foreach (['not-json', '[]', '{"success":true}', str_repeat('x', 1048577)] as $metadata) {
            $this->reject(fn () => $parse->invoke($this->builds, $job, $metadata, $this->coreRoot()), 503);
        }
        $validRecipe = $this->recipe;
        $outside = $this->root . '/boot_app0.bin';
        copy($this->coreRoot() . '/tools/partitions/boot_app0.bin', $outside);
        foreach ([
            str_replace('@CORE@/tools/partitions/boot_app0.bin', $outside, $validRecipe),
            str_replace('@CORE@/tools/partitions/boot_app0.bin', '@CORE@/tools/partitions/../partitions/boot_app0.bin', $validRecipe),
            str_replace('@WORK@/compost.ino.bin', '@WORK@/compost.ino.bootloader.bin', $validRecipe),
            str_replace('@WORK@/compost.ino.bin', '{build.path}/compost.ino.bin', $validRecipe),
            str_replace('@WORK@/compost.ino.bin', 'https://host/compost.ino.bin', $validRecipe),
            str_replace('write-flash', 'erase-flash', $validRecipe),
            $validRecipe . '"',
        ] as $recipe) {
            $this->recipe = $recipe;
            $this->reject(fn () => $parse->invoke($this->builds, $job, $this->compilerMetadata($job), $this->coreRoot()), 503);
        }
    }

    public function testPrivateDownloadsAreBoundToOwnerUnitTokenScheduleAndCurrentSafety(): void
    {
        $id = $this->builds->build(1, $this->input())['release']['id'];
        $this->reject(fn () => $this->firmware->manifest(3, $id), 404);
        $this->signIn(2);
        $this->reject(fn () => $this->firmware->manifest(1, $id), 404);
        $this->assertSame([], $this->builds->privateReleases(1, $this->actor()));
        $this->signIn();
        foreach ([['bmg_devices', 'device_token_hash', hash('sha256', str_repeat('b', 64)), hash('sha256', str_repeat('a', 64))],
            ['bmg_rotation_schedules', 'version', 8, 7], ['bmg_units', 'status', 'active', 'maintenance']] as [$table, $field, $bad, $good]) {
            $this->buildDb->table($table)->where('id', 1)->update([$field => $bad]);
            $this->reject(fn () => $this->firmware->manifest(1, $id), 409);
            $this->reject(fn () => $this->firmware->part(1, $id, 0), 409);
            $this->buildDb->table($table)->where('id', 1)->update([$field => $good]);
        }
        file_put_contents($this->root . '/' . $id . '/part-0.bin', 'tampered-binary');
        $this->reject(fn () => $this->firmware->part(1, $id, 0), 503);
    }

    public function testSignatureExpirySourceBindingAndRetention(): void
    {
        $id = $this->builds->build(1, $this->input())['release']['id'];
        $path = $this->root . '/' . $id . '/manifest.json';
        $original = file_get_contents($path);
        file_put_contents($path, str_replace('Private network', 'Forged network', $original));
        $this->reject(fn () => $this->firmware->manifest(1, $id), 404);
        file_put_contents($path, $original);
        $this->resign($path, ['source_hash' => str_repeat('0', 64)]);
        $this->reject(fn () => $this->firmware->manifest(1, $id), 409);
        file_put_contents($path, $original);
        $this->resign($path, ['created' => time() - 1801, 'expires' => time() - 1]);
        $this->reject(fn () => $this->firmware->manifest(1, $id), 409);
        $this->builds->build(1, $this->input());
        $this->assertDirectoryDoesNotExist(dirname($path));
        $this->builds->build(1, $this->input());
        $this->reject(fn () => $this->builds->build(1, $this->input()), 409);
        $this->assertCount(2, glob($this->root . '/build-*'));
        $this->buildDb->table('bmg_devices')->where('id', 1)->update(['device_token_hash' => hash('sha256', str_repeat('b', 64))]);
        $this->reject(fn () => $this->builds->build(1, array_replace($this->input(), ['device_token' => str_repeat('b', 64)])), 409);
    }

    public function testWorkspaceFlashBlockersExplainEveryGateWithoutChangingAuthority(): void
    {
        $messages = [
            'usb' => 'Server USB flashing is disabled. Ask an administrator to review BMG_USB_FLASH_ENABLED.',
            'motor' => 'The motor hardware gate is enabled or invalid. USB flashing requires BMG_HARDWARE_ENABLED to be false.',
            'permission' => 'Your account requires the explicit facilities.hardware.manage permission before flashing.',
            'maintenance' => 'Place this unit in maintenance before flashing.',
            'enabled' => 'Disable and publish the rotation schedule before flashing.',
            'record' => 'Publish exactly one rotation schedule version for this unit before flashing.',
            'binding' => 'Bind one active device with a valid chip MAC to this unit before flashing.',
            'pending' => 'Resolve unexpired pending commands before flashing.',
        ];
        $saved = [];
        foreach (['BMG_USB_FLASH_ENABLED', 'BMG_HARDWARE_ENABLED'] as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        $gate = static function (string $key, ?string $value): void {
            if ($value === null) { unset($_ENV[$key], $_SERVER[$key]); putenv($key); }
            else { $_ENV[$key] = $_SERVER[$key] = $value; putenv($key . '=' . $value); }
        };
        $check = function (array $expected): void {
            $workspace = $this->firmware->workspace(1);
            $this->assertSame($expected, $workspace['flashBlockers']);
            $this->assertSame($expected === [], $workspace['flashEnabled']);
            $safe = json_encode($workspace['flashBlockers']);
            foreach ([$this->root, $this->input()['device_token'], 'private-malformed-mac', 'aa:bb:cc:dd:ee:01', '<script>'] as $secret) {
                $this->assertStringNotContainsString($secret, $safe);
            }
        };
        try {
            // Defaults stay closed even with an active local compiler. Cached grants
            // cannot substitute for the explicit persisted hardware permission.
            $gate('BMG_USB_FLASH_ENABLED', null);
            $gate('BMG_HARDWARE_ENABLED', null);
            $check([$messages['usb'], $messages['permission']]);
            $cached = session()->get('user');
            $cached['permissions'][] = 'facilities.hardware.manage';
            session()->set('user', $cached);
            $check([$messages['usb'], $messages['permission']]);
            $this->assertSame(0, $this->buildDb->table('role_permissions')->where('permission_id', 4)->countAllResults());
            $this->buildDb->table('permissions')->insert(['id' => 4, 'code' => 'facilities.hardware.manage']);
            $this->buildDb->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => 4]);
            $check([$messages['usb']]);
            $gate('BMG_USB_FLASH_ENABLED', 'true');
            $gate('BMG_HARDWARE_ENABLED', 'false');
            $check([]);
            foreach (['BMG_USB_FLASH_ENABLED' => ['false', 'invalid-private-value'], 'BMG_HARDWARE_ENABLED' => ['true', 'invalid-private-value']] as $key => $values) {
                foreach ($values as $value) {
                    $gate($key, $value);
                    $check([$messages[$key === 'BMG_USB_FLASH_ENABLED' ? 'usb' : 'motor']]);
                }
                $gate($key, $key === 'BMG_USB_FLASH_ENABLED' ? 'true' : 'false');
            }
            foreach ([
                ['bmg_units', 'status', 'active', 'maintenance', 'maintenance'],
                ['bmg_rotation_schedules', 'enabled', 1, 0, 'enabled'],
                ['bmg_devices', 'chip_mac', '<script>private-malformed-mac</script>', 'aa:bb:cc:dd:ee:01', 'binding'],
                ['bmg_devices', 'status', 'revoked', 'active', 'binding'],
                ['bmg_devices', 'rotation_bound_at', null, '2026-01-01 00:00:00', 'binding'],
                ['bmg_devices', 'revoked_at', '2026-01-01 00:00:00', null, 'binding'],
            ] as [$table, $field, $bad, $good, $reason]) {
                $this->buildDb->table($table)->where('id', 1)->update([$field => $bad]);
                $check([$messages[$reason]]);
                $this->buildDb->table($table)->where('id', 1)->update([$field => $good]);
                $check([]);
            }
            $schedule = $this->buildDb->table('bmg_rotation_schedules')->where('id', 1)->get()->getRowArray();
            $this->buildDb->table('bmg_rotation_schedules')->where('id', 1)->delete();
            $check([$messages['record']]);
            $this->buildDb->table('bmg_rotation_schedules')->insert($schedule);
            $this->buildDb->table('bmg_rotation_schedules')->insert(array_replace($schedule, ['id' => 2]));
            $check([$messages['record']]);
            $this->buildDb->table('bmg_rotation_schedules')->where('id', 2)->delete();
            $this->buildDb->table('bmg_rotation_commands')->insert(['id' => 1, 'tenant_id' => 1, 'unit_id' => 1, 'status' => 'pending', 'expires_at' => '2099-01-01 00:00:00']);
            $check([$messages['pending']]);
            $this->buildDb->table('bmg_rotation_commands')->where('id', 1)->update(['expires_at' => '2000-01-01 00:00:00']);
            $check([]);
            $this->buildDb->table('bmg_rotation_commands')->where('id', 1)->update(['tenant_id' => 2, 'expires_at' => '2099-01-01 00:00:00']);
            $check([]);
            $this->buildDb->table('bmg_rotation_commands')->where('id', 1)->update(['tenant_id' => 1, 'unit_id' => 3]);
            $check([]);
            $gate('BMG_USB_FLASH_ENABLED', 'false');
            $gate('BMG_HARDWARE_ENABLED', 'true');
            $this->buildDb->table('role_permissions')->where('permission_id', 4)->delete();
            $this->buildDb->table('bmg_units')->where('id', 1)->update(['status' => 'active']);
            $this->buildDb->table('bmg_rotation_schedules')->where('id', 1)->update(['enabled' => 1]);
            $this->buildDb->table('bmg_rotation_schedules')->insert(array_replace($schedule, ['id' => 2]));
            $this->buildDb->table('bmg_devices')->where('id', 1)->update(['chip_mac' => 'private-malformed-mac']);
            $this->buildDb->table('bmg_rotation_commands')->where('id', 1)->update(['unit_id' => 1]);
            $check(array_values($messages));
            $this->assertSame([], $this->calls);
        } finally {
            foreach ($saved as $key => [$env, $server, $process]) {
                if ($env === null) { unset($_ENV[$key]); } else { $_ENV[$key] = $env; }
                if ($server === null) { unset($_SERVER[$key]); } else { $_SERVER[$key] = $server; }
                putenv($process === false ? $key : $key . '=' . $process);
            }
        }
    }

    public function testPrivateWorkspaceAndFlashAuthorizationKeepExistingHardwareGates(): void
    {
        $id = $this->builds->build(1, $this->input())['release']['id'];
        $workspace = $this->firmware->workspace(1);
        $this->assertSame($id, $workspace['releases'][0]['id']);
        $this->assertFalse($workspace['flashEnabled']);
        $request = ['release_id' => $id, 'chip_family' => 'ESP32-S3', 'chip_mac' => 'aa:bb:cc:dd:ee:01',
            'isolated_confirmed' => true, 'expected_version' => 7];
        $this->reject(fn () => $this->firmware->authorize(1, $request), 403);
        $this->buildDb->table('permissions')->insert(['id' => 4, 'code' => 'facilities.hardware.manage']);
        $this->buildDb->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => 4]);
        $saved = [];
        foreach (['BMG_USB_FLASH_ENABLED' => 'true', 'BMG_HARDWARE_ENABLED' => 'false'] as $key => $value) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
        try {
            $this->assertTrue($this->firmware->authorize(1, $request)['success']);
            $this->assertSame(1, $this->buildDb->table('audit_events')->countAllResults());
            $this->buildDb->table('bmg_devices')->where('id', 1)->update(['device_token_hash' => hash('sha256', str_repeat('b', 64))]);
            $this->reject(fn () => $this->firmware->authorize(1, $request), 409);
            $this->assertSame(1, $this->buildDb->table('audit_events')->countAllResults());
        } finally {
            foreach ($saved as $key => [$env, $server, $process]) {
                if ($env === null) { unset($_ENV[$key]); } else { $_ENV[$key] = $env; }
                if ($server === null) { unset($_SERVER[$key]); } else { $_SERVER[$key] = $server; }
                putenv($process === false ? $key : $key . '=' . $process);
            }
        }
    }

    public function testPrivateBinarySymlinksAreNeverServed(): void
    {
        $id = $this->builds->build(1, $this->input())['release']['id'];
        $part = $this->root . '/' . $id . '/part-0.bin';
        rename($part, $part . '.original');
        if (!@symlink($part . '.original', $part)) { $this->markTestSkipped('Symlink creation not permitted.'); }
        $this->reject(fn () => $this->firmware->part(1, $id, 0), 503);
    }

    public function testNativeRunnerBoundsOutputAndTimeoutWithoutExposingIt(): void
    {
        $native = new BmgBuildService($this->buildDb, $this->config, $this->root);
        $run = new ReflectionMethod($native, 'run');
        file_put_contents($this->root . '/compile', "<?php echo 'bounded-property-metadata';");
        $captured = $run->invoke($native, [PHP_BINARY, 'compile', '--show-properties=expanded'], $this->root, 10);
        $discarded = $run->invoke($native, [PHP_BINARY, 'compile'], $this->root, 10);
        $this->assertSame('bounded-property-metadata', $captured['stdout']);
        $this->assertSame('', $discarded['stdout']);
        $start = microtime(true);
        $this->reject(fn () => $run->invoke($native, [PHP_BINARY, '-r', 'sleep(5);'], $this->root, 1), 503);
        $this->assertLessThan(4, microtime(true) - $start);
        $this->reject(fn () => $run->invoke($native, [PHP_BINARY, '-r', 'echo str_repeat("x", 1048577);'], $this->root, 10), 503);
    }

    private function parserJob(): string
    {
        $job = $this->root . '/parser job-' . bin2hex(random_bytes(4));
        foreach (['work', 'out', 'compost'] as $dir) { mkdir($job . '/' . $dir, 0700, true); }
        foreach (['compost.ino.bootloader.bin', 'compost.ino.partitions.bin', 'compost.ino.bin'] as $file) {
            file_put_contents($job . '/work/' . $file, 'private-test-binary');
        }
        file_put_contents($job . '/out/compost.ino.merged.bin', 'must-never-be-flashed');
        file_put_contents($job . '/work/build.options.json', '{"not":"a flash map"}');
        return $job;
    }

    private function coreRoot(): string
    {
        return $this->root . '/arduino-data/packages/esp32/hardware/esp32/3.3.3';
    }

    private function compilerMetadata(string $job): string
    {
        $platform = ['id' => 'esp32:esp32', 'version' => '3.3.3', 'install_dir' => $this->coreRoot()];
        $properties = ['build.fqbn=' . BmgBuild::FQBN, 'build.mcu=esp32s3', 'build.flash_size=16MB',
            'build.project_name=compost.ino', 'build.path=' . $job . '/work', 'build.source.path=' . $job . '/compost',
            'runtime.platform.path=' . $this->coreRoot(), 'tools.esptool_py.upload.pattern="{path}/{cmd}" {upload.pattern_args}'];
        if ($this->failure !== 'no-recipe') {
            $properties[] = 'tools.esptool_py.upload.pattern_args=' . str_replace(['@WORK@', '@OUT@', '@CORE@'], [$job . '/work', $job . '/out', $this->coreRoot()], $this->recipe);
        }
        return json_encode(['success' => true, 'compiler_out' => '', 'compiler_err' => '', 'builder_result' => [
            'build_path' => $job . '/work', 'board_platform' => $platform, 'build_platform' => $platform, 'build_properties' => $properties]]);
    }

    public function testSetupStatusAndSourceReturnOnlyAuthorizedSafeData(): void
    {
        $status = $this->controller('GET')->setupStatus(1);
        $this->assertSame(200, $status->getStatusCode(), $status->getBody());
        $json = json_decode($status->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($json['builder']['available']);
        $this->assertFalse($json['builder']['motor_outputs_enabled']);
        $this->assertSame(['last_seen_at', 'firmware_version', 'firmware_reported_at'], array_keys($json['device']));
        $this->assertStringNotContainsString($this->input()['device_token'], $status->getBody());
        $this->assertStringNotContainsString('signingKey', $status->getBody());

        $response = $this->controller('GET')->sketch(1);
        $this->assertSame(200, $response->getStatusCode());
        $json = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('compost.ino', $json['filename']);
        $this->assertSame(BmgBuild::SOURCES['compost.ino'], hash('sha256', $json['source']));
        $this->assertSame(BmgBuild::SOURCES['commissioning_policy.h'], hash('sha256', $json['support_files']['commissioning_policy.h']));
        $this->assertFalse($json['motor_outputs_enabled']);
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        AuthenticatedUser::clear();
        $this->assertSame(403, $this->controller('GET')->sketch(1)->getStatusCode());
    }

    public function testBuildControllerNormalizesFormConsentAndNeverReturnsCredentials(): void
    {
        $input = array_replace($this->input(), ['allow_http' => '1', 'physical_isolation' => '1', 'expected_version' => '7']);
        $response = $this->controller('POST', $input)->build(1);
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $json = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('csrf', $json);
        foreach (['wifi_ssid', 'wifi_password', 'device_token'] as $field) {
            $this->assertStringNotContainsString($input[$field], $response->getBody());
        }
    }

    public function testBuildControllerRejectsWrongVerbAndInvalidConsentOrSource(): void
    {
        $this->assertSame(405, $this->controller('GET')->build(1)->getStatusCode());
        $valid = array_replace($this->input(), ['allow_http' => '1', 'physical_isolation' => '1']);
        foreach ([['allow_http' => ['1']], ['physical_isolation' => 'true'], ['source' => '#include <secret>']] as $change) {
            $response = $this->controller('POST', array_replace($valid, $change))->build(1);
            $this->assertSame(422, $response->getStatusCode(), $response->getBody());
        }
        $this->assertSame([], $this->calls, 'Invalid inputs must never start the compiler.');
    }

    private function controller(string $method, array $post = []): \App\Controllers\BmgUsbController
    {
        $request = service('request');
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $request->removeHeader('Content-Length');
        $request->setGlobal('post', $post);
        $request->setBody('');
        $controller = new \App\Controllers\BmgUsbController($this->builds);
        $controller->initController($request, service('response')->setStatusCode(200), service('logger'));
        return $controller;
    }

    private function input(): array
    {
        return ['wifi_ssid' => 'private-test-wifi', 'wifi_password' => 'private-test-password', 'api_base_url' => 'http://192.168.1.5/humus',
            'device_token' => str_repeat('a', 64), 'allow_http' => true, 'expected_version' => 7, 'physical_isolation' => true];
    }

    private function actor(): array { return (new AuthenticatedUser())->current(); }

    private function signIn(int $id = 1): void
    {
        session()->set('_auth', ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]);
        session()->set(['tenant_id' => 1, 'user' => ['id' => $id, 'tenant_id' => 1, 'logged_in' => true, 'role' => 'superadmin',
            'permissions' => ['facilities.read', 'facilities.manage', 'facilities.configure']]]);
    }

    private function reject(callable $operation, int $code): void
    {
        try { $operation(); } catch (Throwable $error) {
            $this->assertSame($code, $error->getCode(), $error->getMessage());
            $this->assertStringNotContainsString($this->input()['device_token'], $error->getMessage());
            $this->assertStringNotContainsString('secret compiler diagnostic', $error->getMessage());
            $this->assertStringNotContainsString('secret compiler diagnostic', $error->getPrevious()?->getMessage() ?? '');
            return;
        }
        $this->fail('Expected rejection: ' . $code);
    }

    private function resign(string $path, array $changes): void
    {
        $envelope = json_decode(file_get_contents($path), true);
        $payload = array_replace(json_decode($envelope['payload'], true), $changes);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        file_put_contents($path, json_encode(['payload' => $json, 'signature' => hash_hmac('sha256', $json, $this->config->signingKey)]));
    }

    private function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) { unlink($path); return; }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $file) { $this->remove($file->getPathname()); }
        rmdir($path);
    }

    private function fixture(): void
    {
        foreach ([
            'CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)',
            'CREATE TABLE users (auth_version INTEGER NOT NULL DEFAULT 1, id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, role TEXT, username TEXT, email TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY, code TEXT)',
            'CREATE TABLE role_permissions (role TEXT, permission_id INTEGER)',
            'CREATE TABLE bmg_units (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE bmg_devices (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, chip_mac TEXT, device_token_hash TEXT, status TEXT, revoked_at TEXT, rotation_bound_at TEXT, reported_firmware_version TEXT, rtc_valid INTEGER, applied_schedule_version INTEGER)',
            'CREATE TABLE bmg_rotation_schedules (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, enabled INTEGER, version INTEGER, times_json TEXT, duration_seconds INTEGER, pwm INTEGER)',
            'CREATE TABLE bmg_rotation_runs (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER)',
            'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, event_type TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT NOT NULL, payload_json TEXT, prev_hash TEXT NOT NULL, curr_hash TEXT NOT NULL, hash_version INTEGER NOT NULL DEFAULT 1, occurred_at TEXT NOT NULL)',
            'CREATE TABLE bmg_rotation_commands (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, status TEXT, expires_at TEXT)',
            'INSERT INTO tenants (id, is_active) VALUES (1, 1), (2, 1)',
            "INSERT INTO users (id, tenant_id, role, username, status) VALUES (1, 1, 'superadmin', 'builder-one', 'active'), (2, 1, 'superadmin', 'builder-two', 'active')",
            "INSERT INTO permissions VALUES (1, 'facilities.read'), (2, 'facilities.manage'), (3, 'facilities.configure')",
            "INSERT INTO role_permissions VALUES ('superadmin', 1), ('superadmin', 2), ('superadmin', 3)",
            "INSERT INTO bmg_units VALUES (1, 1, 'Compost', 'maintenance', NULL), (2, 2, 'Other tenant', 'maintenance', NULL), (3, 1, 'Other unit', 'maintenance', NULL)",
            "INSERT INTO bmg_rotation_schedules VALUES (1, 1, 1, 0, 7, '[]', 60, NULL)",
        ] as $sql) { $this->buildDb->query($sql); }
        $this->buildDb->table('bmg_devices')->insert(['id' => 1, 'tenant_id' => 1, 'unit_id' => 1, 'chip_mac' => 'aa:bb:cc:dd:ee:01',
            'device_token_hash' => hash('sha256', str_repeat('a', 64)), 'status' => 'active', 'rotation_bound_at' => '2026-01-01 00:00:00']);
    }
}
