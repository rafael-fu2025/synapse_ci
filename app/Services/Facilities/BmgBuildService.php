<?php

declare(strict_types=1);

namespace App\Services\Facilities;

use App\Services\AuthenticatedUser;
use App\Services\PortalContext;
use CodeIgniter\Database\BaseConnection;
use Config\BmgBuild;
use Config\BmgFirmware;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Local network commissioning only. Neither compilation nor its artifacts authorize motors. */
final class BmgBuildService
{
    private BmgBuild $config;
    private string $root;
    private mixed $runner;
    private const OUTPUT_LIMIT = 1048576;
    private const JOB_LIMIT = 536870912;

    /** Runner is a test seam: (argv, cwd, timeout): {exit_code:int, stdout:string}. */
    public function __construct(private ?BaseConnection $db = null, ?BmgBuild $config = null, ?string $root = null, ?callable $runner = null)
    {
        $this->config = $config ?? config(BmgBuild::class);
        $this->root = rtrim($root ?? WRITEPATH . 'firmware/bmg', '/\\');
        $this->runner = $runner;
    }

    public function status(int $unit): array
    {
        $actor = $this->actor();
        $this->unit($unit, $actor);
        $available = false;
        try { $this->ready(); $available = true; } catch (Throwable) { /* Fixed public diagnostic only. */ }
        return ['available' => $available,
            'message' => $available ? 'Network commissioning compiler ready. Motor outputs are disabled; physical isolation is required.'
                : 'Network commissioning compiler unavailable. Ask an administrator to verify the local toolchain and build configuration.',
            'board_profile' => BmgBuild::FQBN, 'firmware_version' => BmgBuild::VERSION, 'motor_outputs_enabled' => false];
    }

    public function build(int $unit, #[\SensitiveParameter] array $data): array
    {
        $actor = $this->actor();
        $input = $this->input($data);
        $binding = $this->binding($unit, $actor, $input['expected_version'], hash('sha256', $input['device_token']));
        $sources = $this->sources();
        $coreRoot = $this->ready();
        $this->directory($this->root);
        // Serialize retention and compilation across workers, without holding a database lock for 180s.
        $lock = $this->root . '/.build-lock';
        if (is_link($lock)) { $this->fail(); }
        $handle = @fopen($lock, 'c');
        if ($handle === false) { $this->fail(); }
        @chmod($lock, 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); throw new RuntimeException('A commissioning build is already running. Try again later.', 409); }
        $job = null;
        try {
            $this->cleanup();
            $existing = 0;
            foreach (glob($this->root . '/build-*', GLOB_ONLYDIR) ?: [] as $dir) {
                $saved = $this->manifest($dir);
                if (($saved['actor'] ?? null) === (int) $actor['id'] && ($saved['tenant'] ?? null) === (int) $actor['tenant_id']
                    && ($saved['unit'] ?? null) === $unit) { ++$existing; }
            }
            if ($existing >= 2) { throw new RuntimeException('Two private builds already exist. Wait for their 30-minute expiry.', 409); }
            $id = 'build-' . bin2hex(random_bytes(16));
            $job = $this->root . '/' . $id;
            $this->directory($job);
            foreach (['compost', 'out', 'work', 'tmp'] as $dir) { $this->directory($job . '/' . $dir); }
            foreach ($sources as $name => $bytes) { $this->write($job . '/compost/' . $name, $bytes); }
            $this->write($job . '/compost/device_config.h', $this->header($input));
            $result = $this->run([$this->executable(), 'compile', '--fqbn', BmgBuild::FQBN,
                '--output-dir', $job . '/out', '--build-path', $job . '/work', '--clean', '--export-binaries',
                $job . '/compost', '--json'], $job, $this->config->timeoutSeconds);
            if ($result['exit_code'] !== 0) { $this->fail(); }
            // This is property inspection only: never execute the upload recipe or
            // supply a serial port. Its sparse map is authoritative, not merged.bin.
            $metadata = $this->run([$this->executable(), 'compile', '--fqbn', BmgBuild::FQBN,
                '--output-dir', $job . '/out', '--build-path', $job . '/work',
                '--show-properties=expanded', $job . '/compost', '--json'], $job, 15);
            if ($metadata['exit_code'] !== 0) { $this->fail(); }
            $parts = $this->parts($job, $metadata['stdout'], $coreRoot);
            $entry = ['id' => $id, 'name' => 'Private network commissioning', 'chip_family' => 'ESP32-S3', 'flash_size' => '16MB', 'parts' => $parts];
            // Reuse the catalog's exact integrity, sector erase, overlap and flash size checks.
            $catalog = new BmgFirmware();
            $catalog->releases = [$entry];
            $public = (new BmgFirmwareService($this->database(), $catalog, $this->root))->catalog()[0];
            $fresh = $this->actor();
            if ($fresh['id'] !== $actor['id'] || $fresh['tenant_id'] !== $actor['tenant_id']
                || $this->binding($unit, $fresh, $input['expected_version'], hash('sha256', $input['device_token'])) !== $binding) {
                throw new RuntimeException('Build binding changed. Start again.', 409);
            }
            $this->sources();
            $now = time();
            $payload = ['actor' => (int) $actor['id'], 'tenant' => (int) $actor['tenant_id'], 'unit' => $unit,
                'device' => $binding['device'], 'token_hash' => $binding['token_hash'], 'schedule_version' => $binding['schedule_version'],
                'source_hash' => $this->sourceHash(), 'created' => $now, 'expires' => $now + BmgBuild::TTL, 'release' => $entry];
            // Only referenced binaries survive, not the private header, sketch, ELF, logs or compilation intermediates.
            $keep = array_column($parts, 'filename');
            foreach (new \FilesystemIterator($job, \FilesystemIterator::SKIP_DOTS) as $file) {
                if (!in_array($file->getFilename(), $keep, true)) { $this->remove($file->getPathname()); }
            }
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->write($job . '/manifest.json', json_encode(['payload' => $json, 'signature' => hash_hmac('sha256', $json, $this->config->signingKey)], JSON_THROW_ON_ERROR));
            return ['success' => true, 'release' => $public,
                'message' => 'Private network commissioning build ready for 30 minutes. Motor outputs are disabled. Compilation does not verify physical isolation, board acceptance, or flashing.'];
        } catch (Throwable $error) {
            if ($job !== null) { $this->remove($job); }
            if ($error instanceof RuntimeException && in_array($error->getCode(), [403, 404, 409], true)) { throw $error; }
            // No compiler output or exception chain: either can contain credentials or source paths.
            throw new RuntimeException('Commissioning build failed. Ask an administrator to verify the local toolchain.', 503);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    /** Only fresh, owned releases, never a global private catalog. */
    public function privateReleases(int $unit, array $actor): array
    {
        $actor = $this->freshActor($actor);
        $this->unit($unit, $actor);
        $result = [];
        foreach (glob($this->root . '/build-*', GLOB_ONLYDIR) ?: [] as $dir) {
            try { $result[] = $this->privateRelease($unit, basename($dir), $actor); }
            catch (RuntimeException $error) { if (!in_array($error->getCode(), [404, 409], true)) { throw $error; } }
        }
        return $result;
    }

    public function privateRelease(int $unit, string $id, array $actor): array
    {
        $actor = $this->freshActor($actor);
        $this->unit($unit, $actor);
        if (!preg_match('/\Abuild-[0-9a-f]{32}\z/D', $id)) { throw new RuntimeException('Private firmware not found.', 404); }
        $payload = $this->manifest($this->root . '/' . $id);
        if ($payload === null || ($payload['actor'] ?? null) !== (int) $actor['id']
            || ($payload['tenant'] ?? null) !== (int) $actor['tenant_id'] || ($payload['unit'] ?? null) !== $unit
            || ($payload['release']['id'] ?? null) !== $id) { throw new RuntimeException('Private firmware not found.', 404); }
        if (!$this->validTime($payload) || ($payload['source_hash'] ?? null) !== $this->sourceHash()) {
            throw new RuntimeException('Private firmware expired or changed. Rebuild required.', 409);
        }
        try { $this->sources(); } catch (Throwable) { throw new RuntimeException('Commissioning source changed. Rebuild required.', 409); }
        $binding = $this->binding($unit, $actor, $payload['schedule_version'] ?? null, $payload['token_hash'] ?? null);
        if ($binding['device'] !== ($payload['device'] ?? null)) { throw new RuntimeException('Device binding changed. Rebuild required.', 409); }
        return $payload['release'];
    }

    private function database(): BaseConnection { return $this->db ??= Database::connect(); }

    private function actor(): array
    {
        $request = service('request');
        $actor = (new AuthenticatedUser())->current();
        if ($request->getHeaderLine('X-Device-Token') !== '' || $request->getHeaderLine('Authorization') !== ''
            || $actor === null || !PortalContext::isRolePermitted($actor['role'], PortalContext::PORTAL_BMG)
            || array_diff(['facilities.read', 'facilities.manage', 'facilities.configure'], $actor['permissions']) !== []) {
            throw new RuntimeException('Human BMG configuration permission required.', 403);
        }
        return $actor;
    }

    private function freshActor(array $actor): array
    {
        $fresh = $this->actor();
        if (($actor['id'] ?? null) !== $fresh['id'] || ($actor['tenant_id'] ?? null) !== $fresh['tenant_id']) {
            throw new RuntimeException('Private firmware not found.', 404);
        }
        return $fresh;
    }

    private function unit(int $unit, array $actor): array
    {
        $row = $this->database()->table('bmg_units')->where('id', $unit)->where('tenant_id', $actor['tenant_id'])
            ->where('deleted_at', null)->get()->getRowArray();
        if ($row === null) { throw new RuntimeException('Unit not found.', 404); }
        return $row;
    }

    private function binding(int $unit, array $actor, mixed $version, mixed $tokenHash): array
    {
        $row = $this->unit($unit, $actor);
        $db = $this->database();
        $tenant = (int) $actor['tenant_id'];
        $schedule = $db->table('bmg_rotation_schedules')->where('tenant_id', $tenant)->where('unit_id', $unit)->get()->getRowArray();
        $devices = $db->table('bmg_devices')->where('tenant_id', $tenant)->where('unit_id', $unit)
            ->where('status', 'active')->where('revoked_at', null)->where('rotation_bound_at !=', null)->limit(2)->get()->getResultArray();
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $pending = $db->table('bmg_rotation_commands')->where('tenant_id', $tenant)->where('unit_id', $unit)
            ->where('status', 'pending')->where('expires_at >', $now)->countAllResults();
        if ($row['status'] !== 'maintenance' || $schedule === null || (bool) $schedule['enabled']
            || !is_int($version) || (int) $schedule['version'] !== $version || count($devices) !== 1 || $pending > 0
            || !is_string($tokenHash) || !preg_match('/\A[0-9a-f]{64}\z/D', $tokenHash)
            || !is_string($devices[0]['device_token_hash'] ?? null) || !hash_equals($devices[0]['device_token_hash'], $tokenHash)) {
            throw new RuntimeException('Maintenance, the current disabled schedule, matching bound device credential, and no pending commands are required.', 409);
        }
        return ['device' => (int) $devices[0]['id'], 'token_hash' => $tokenHash, 'schedule_version' => $version];
    }

    private function input(#[\SensitiveParameter] array $data): array
    {
        $allowed = ['wifi_ssid', 'wifi_password', 'api_base_url', 'device_token', 'root_ca_pem', 'allow_http', 'expected_version', 'physical_isolation'];
        if (array_diff(array_keys($data), $allowed) !== []) { throw new InvalidArgumentException('Unsupported input fields.'); }
        foreach (['wifi_ssid', 'wifi_password', 'api_base_url', 'device_token'] as $key) {
            if (!is_string($data[$key] ?? null) || str_contains($data[$key], "\0")) { throw new InvalidArgumentException('Invalid network configuration.'); }
        }
        $password = $data['wifi_password'];
        if (strlen($data['wifi_ssid']) < 1 || strlen($data['wifi_ssid']) > 32
            || !((strlen($password) >= 8 && strlen($password) <= 63) || preg_match('/\A[0-9a-fA-F]{64}\z/D', $password))
            || !preg_match('/\A[0-9a-f]{64}\z/D', $data['device_token'])
            || !is_bool($data['allow_http'] ?? null) || ($data['physical_isolation'] ?? null) !== true) {
            throw new InvalidArgumentException('Valid Wi-Fi credentials, device token, and explicit physical isolation are required.');
        }
        $version = $data['expected_version'] ?? null;
        if ((!is_int($version) && !is_string($version)) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $version)
            || (int) $version > 2147483646) { throw new InvalidArgumentException('Invalid expected_version.'); }
        $data['expected_version'] = (int) $version;
        $url = $data['api_base_url'];
        $parsed = parse_url($url);
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || !is_array($parsed)
            || !in_array($parsed['scheme'] ?? null, ['http', 'https'], true) || !isset($parsed['host'])
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parsed)) !== []
            || (isset($parsed['port']) && ($parsed['port'] < 1 || $parsed['port'] > 65535))) {
            throw new InvalidArgumentException('Invalid API base URL.');
        }
        $host = strtolower(trim($parsed['host'], '[]'));
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        $packed = $ip ? inet_pton($host) : false;
        $blockedIp = $packed !== false && (strlen($packed) === 4
            ? ord($packed[0]) === 127 || $packed === str_repeat("\0", 4)
            : $packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15) . "\1"
                || substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
                || substr($packed, 0, 12) === str_repeat("\0", 12));
        $dns = strlen($host) <= 253 && preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D', $host);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '0.0.0.0'
            || $host === '::' || $host === '::1' || str_starts_with($host, '127.')
            || str_starts_with($host, '::ffff:') || (!$ip && (!$dns || !preg_match('/[a-z]/', $host)))
            || $blockedIp || (!$ip && preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/Di', $host))
            || str_contains($host, '..')) { throw new InvalidArgumentException('Invalid API host.'); }
        $path = $parsed['path'] ?? '';
        // Do not allow encoded separators/dot segments or ambiguous re-decoding by a proxy.
        if (preg_match('/[^A-Za-z0-9_\/.~-]/', $path) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || str_contains($path, '//')) {
            throw new InvalidArgumentException('Invalid API deployment path.');
        }
        $data['api_base_url'] = rtrim($url, '/');
        $pem = $data['root_ca_pem'] ?? '';
        if (!is_string($pem) || strlen($pem) > 16384 || str_contains($pem, "\0")) { throw new InvalidArgumentException('Invalid root CA certificate.'); }
        if ($parsed['scheme'] === 'http' && $data['allow_http'] !== true) { throw new InvalidArgumentException('HTTP requires explicit trusted-network consent.'); }
        if ($parsed['scheme'] === 'https') {
            if (!function_exists('openssl_x509_read') || !preg_match('/\A\s*-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----\s*\z/D', $pem)
                || @openssl_x509_read($pem) === false) { throw new InvalidArgumentException('HTTPS requires a valid PEM root CA certificate.'); }
        }
        $data['root_ca_pem'] = $pem;
        return $data;
    }

    private function header(#[\SensitiveParameter] array $data): string
    {
        $quote = static function (string $bytes): string {
            $literal = '"';
            for ($i = 0; $i < strlen($bytes); ++$i) { $literal .= sprintf('\\%03o', ord($bytes[$i])); }
            return $literal . '"';
        };
        $header = "#pragma once\n";
        foreach (['wifi_ssid' => 'WIFI_SSID', 'wifi_password' => 'WIFI_PASS', 'api_base_url' => 'API_BASE_URL', 'device_token' => 'DEVICE_TOKEN'] as $key => $name) {
            $header .= 'static const char ' . $name . '[] = ' . $quote($data[$key]) . ";\n";
        }
        if (str_starts_with($data['api_base_url'], 'https://')) { $header .= '#define BMG_ROOT_CA_PEM ' . $quote($data['root_ca_pem']) . "\n"; }
        elseif ($data['allow_http'] === true) { $header .= "#define BMG_ALLOW_HTTP true\n"; }
        return $header;
    }

    private function executable(): string
    {
        $path = $this->config->cli;
        if (!preg_match('~\A(?:[A-Za-z]:[\\\\/]|/)~', $path) || !is_file($path) || is_link($path) || !is_executable($path)
            || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['', 'exe'], true)) { $this->fail(); }
        $real = realpath($path);
        if ($real === false || $this->normalize($real) !== $this->normalize($path)) { $this->fail(); }
        $handle = @fopen($real, 'rb');
        if ($handle === false) { $this->fail(); }
        try { $magic = fread($handle, 4); } finally { fclose($handle); }
        if (!is_string($magic) || !(str_starts_with($magic, 'MZ') || $magic === "\x7fELF"
            || in_array(bin2hex($magic), ['feedface', 'cefaedfe', 'feedfacf', 'cffaedfe', 'cafebabe', 'bebafeca'], true))) { $this->fail(); }
        return $real;
    }

    private function ready(): string
    {
        if (!$this->config->enabled || strlen($this->config->signingKey) < 32 || $this->config->fqbn !== BmgBuild::FQBN
            || $this->config->timeoutSeconds < 1 || $this->config->timeoutSeconds > 180) { $this->fail(); }
        $cli = $this->executable();
        $this->sources();
        $core = $this->run([$cli, 'core', 'list', '--json'], ROOTPATH, 15);
        $lib = $this->run([$cli, 'lib', 'list', '--json'], ROOTPATH, 15);
        $platforms = json_decode($core['stdout'], true);
        $libraries = json_decode($lib['stdout'], true);
        $hasCore = $hasLib = false;
        foreach (is_array($platforms) ? ($platforms['platforms'] ?? $platforms) : [] as $platform) {
            if (is_array($platform) && ($platform['id'] ?? null) === 'esp32:esp32' && ($platform['installed_version'] ?? null) === '3.3.3') { $hasCore = true; }
        }
        foreach (is_array($libraries) ? ($libraries['installed_libraries'] ?? $libraries['libraries'] ?? $libraries) : [] as $library) {
            $library = is_array($library) ? ($library['library'] ?? $library) : [];
            if (($library['name'] ?? null) === 'ArduinoJson' && ($library['version'] ?? null) === '7.4.2') { $hasLib = true; }
        }
        if ($core['exit_code'] !== 0 || $lib['exit_code'] !== 0 || !$hasCore || !$hasLib) { $this->fail(); }
        $data = $this->run([$cli, 'config', 'get', 'directories.data', '--json'], ROOTPATH, 15);
        $directory = json_decode($data['stdout'], true);
        if ($data['exit_code'] !== 0 || !is_string($directory) || !preg_match('~\A(?:[A-Za-z]:[\\\\/]|/)~', $directory)) { $this->fail(); }
        $root = rtrim(str_replace('\\', '/', $directory), '/') . '/packages/esp32/hardware/esp32/3.3.3';
        $this->safePath($root);
        return $root;
    }

    private function sources(): array
    {
        $files = [];
        foreach (BmgBuild::SOURCES as $name => $hash) {
            $path = ROOTPATH . 'firmware/bmg/compost/' . $name;
            $this->safePath($path);
            $bytes = @file_get_contents($path);
            if (!is_string($bytes) || !hash_equals($hash, hash('sha256', $bytes))) { $this->fail(); }
            $files[$name] = $bytes;
        }
        return $files;
    }

    private function sourceHash(): string { return hash('sha256', json_encode(BmgBuild::SOURCES, JSON_THROW_ON_ERROR)); }

    /** CLI 1.5.x JSON uses builder_result.build_properties, a list of key=value strings. */
    private function parts(string $job, string $json, string $coreRoot): array
    {
        if (strlen($json) > self::OUTPUT_LIMIT) { $this->fail(); }
        $metadata = json_decode($json, true, 32);
        if (!is_array($metadata) || json_last_error() !== JSON_ERROR_NONE) { $this->fail(); }
        $builder = $metadata['builder_result'] ?? null;
        if (($metadata['success'] ?? null) !== true || !is_array($builder)
            || !is_array($builder['build_properties'] ?? null) || !array_is_list($builder['build_properties'])
            || count($builder['build_properties']) > 4096) { $this->fail(); }
        $this->safePath($coreRoot);
        foreach (['board_platform', 'build_platform'] as $key) {
            $platform = $builder[$key] ?? null;
            if (!is_array($platform) || ($platform['id'] ?? null) !== 'esp32:esp32' || ($platform['version'] ?? null) !== '3.3.3'
                || !is_string($platform['install_dir'] ?? null)
                || $this->normalize($platform['install_dir']) !== $this->normalize($coreRoot)) { $this->fail(); }
        }
        $properties = [];
        foreach ($builder['build_properties'] as $property) {
            if (!is_string($property) || !str_contains($property, '=') || str_contains($property, "\0")) { $this->fail(); }
            [$key, $value] = explode('=', $property, 2);
            if ($key === '' || array_key_exists($key, $properties)) { $this->fail(); }
            $properties[$key] = $value;
        }
        foreach (['build.fqbn' => BmgBuild::FQBN, 'build.mcu' => 'esp32s3', 'build.flash_size' => '16MB',
            'build.project_name' => 'compost.ino'] as $key => $value) {
            if (($properties[$key] ?? null) !== $value) { $this->fail(); }
        }
        foreach (['runtime.platform.path' => $coreRoot, 'build.path' => $job . '/work', 'build.source.path' => $job . '/compost'] as $key => $path) {
            if (!isset($properties[$key]) || $this->normalize($properties[$key]) !== $this->normalize($path)) { $this->fail(); }
        }
        if (!is_string($builder['build_path'] ?? null)
            || $this->normalize($builder['build_path']) !== $this->normalize($job . '/work')) { $this->fail(); }
        $recipe = $properties['tools.esptool_py.upload.pattern'] ?? '';
        // Arduino leaves tool-local aliases unresolved even with --show-properties=expanded.
        // Resolve only this reviewed alias, never generic recursive property interpolation.
        if (str_contains($recipe, '{upload.pattern_args}')) {
            if (substr_count($recipe, '{upload.pattern_args}') !== 1 || !isset($properties['tools.esptool_py.upload.pattern_args'])) { $this->fail(); }
            $recipe = str_replace('{upload.pattern_args}', $properties['tools.esptool_py.upload.pattern_args'], $recipe);
        }
        $tokens = $this->recipeTokens($recipe);
        $operations = array_keys(array_filter($tokens, static fn (string $token): bool => in_array($token, ['write-flash', 'write_flash'], true)));
        if (count($operations) !== 1) { $this->fail(); }
        // Serial port, executable, reset options and unresolved serial placeholders
        // before the operation are irrelevant: none of this command is ever executed.
        $tokens = array_slice($tokens, $operations[0] + 1);
        $flags = ['--flash_mode' => ['keep', 'dio', 'qio', 'dout', 'qout'], '--flash_freq' => ['keep', '40m', '80m'], '--flash_size' => ['keep', '16MB']];
        $parts = $seen = $seenFlags = [];
        $total = 0;
        for ($i = 0; $i < count($tokens); ++$i) {
            $token = $tokens[$i];
            $flag = str_replace('-', '_', $token);
            if (str_starts_with($token, '--')) { $flag = '--' . ltrim($flag, '_'); }
            if (isset($flags[$flag])) {
                if (isset($seenFlags[$flag]) || !in_array($tokens[++$i] ?? '', $flags[$flag], true)) { $this->fail(); }
                $seenFlags[$flag] = true;
                continue;
            }
            if (in_array($token, ['-z', '--compress'], true)) {
                if (isset($seenFlags['compress'])) { $this->fail(); }
                $seenFlags['compress'] = true;
                continue;
            }
            if (!preg_match('/\A0x[0-9a-fA-F]{1,8}\z/D', $token) || !isset($tokens[$i + 1])) { $this->fail(); }
            $source = $this->binaryPath($tokens[++$i], $job, $coreRoot);
            $kind = basename(str_replace('\\', '/', $source));
            if (isset($seen[$kind])) { $this->fail(); }
            $seen[$kind] = true;
            $size = @filesize($source);
            if (!is_int($size) || $size < 1 || $size > 16777216 - $total) { $this->fail(); }
            $total += $size;
            $filename = 'part-' . count($parts) . '.bin';
            if (!copy($source, $job . '/' . $filename) || !chmod($job . '/' . $filename, 0600)) { $this->fail(); }
            $parts[] = ['name' => 'Part ' . count($parts), 'filename' => $filename, 'address' => (int) hexdec($token),
                'sha256' => hash_file('sha256', $job . '/' . $filename), 'size' => $size];
        }
        // Require the complete sparse recipe. Merged images (which cover NVS) and
        // arbitrary additional binaries are never a fallback, even if they exist.
        if (count($parts) !== 4) { $this->fail(); }
        return $parts;
    }

    private function recipeTokens(string $text): array
    {
        if ($text === '' || strlen($text) > 32768 || preg_match('/[\x00-\x1f\x7f]/', $text)) { $this->fail(); }
        preg_match_all('/"([^"\r\n]*)"|\x27([^\x27\r\n]*)\x27|([^\s"\x27]+)/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $tokens = [];
        $cursor = 0;
        foreach ($matches as $match) {
            if (trim(substr($text, $cursor, $match[0][1] - $cursor)) !== '') { $this->fail(); }
            $cursor = $match[0][1] + strlen($match[0][0]);
            $tokens[] = ($match[1][0] ?? '') !== '' ? $match[1][0] : (($match[2][0] ?? '') !== '' ? $match[2][0] : ($match[3][0] ?? ''));
        }
        if (trim(substr($text, $cursor)) !== '') { $this->fail(); }
        return $tokens;
    }

    private function binaryPath(string $name, string $job, string $coreRoot): string
    {
        $name = str_replace('\\', '/', $name);
        $boot = $coreRoot . '/tools/partitions/boot_app0.bin';
        if ($this->normalize($name) === $this->normalize($boot)) {
            $this->safePath($boot);
            if (!is_file($boot)) { $this->fail(); }
            return $boot;
        }
        if (!preg_match('~\A(?:[A-Za-z]:/|/)~', $name)) { $name = $job . '/work/' . $name; }
        foreach (['work', 'out'] as $dir) {
            $prefix = $job . '/' . $dir . '/';
            if (!str_starts_with($this->normalize($name), $this->normalize($prefix))) { continue; }
            $relative = substr($name, strlen($prefix));
            if (!preg_match('~\A[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*\z~D', $relative)
                || !in_array(basename($name), ['compost.ino.bootloader.bin', 'compost.ino.partitions.bin', 'compost.ino.bin'], true)) { $this->fail(); }
            $this->safePath($name);
            if (!is_file($name)) { $this->fail(); }
            return $name;
        }
        $this->fail();
    }

    private function run(array $argv, string $cwd, int $timeout): array
    {
        if ($this->runner !== null) {
            $result = ($this->runner)($argv, $cwd, $timeout);
            if (!is_array($result) || !is_int($result['exit_code'] ?? null) || !is_string($result['stdout'] ?? null)
                || strlen($result['stdout']) > self::OUTPUT_LIMIT) { $this->fail(); }
            return $result;
        }
        if (!function_exists('proc_open')) { $this->fail(); }
        $env = getenv();
        if (is_dir($cwd . '/tmp')) { foreach (['TMP', 'TEMP', 'TMPDIR'] as $key) { $env[$key] = $cwd . '/tmp'; } }
        // Windows anonymous pipes cannot reliably be made nonblocking. Never fread a
        // compiler pipe: it can bypass the deadline. Compiler output goes straight to
        // the null device; only dependency/property metadata uses a bounded file.
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $capture = ($argv[1] ?? '') !== 'compile' || in_array('--show-properties=expanded', $argv, true);
        $outputPath = $capture ? tempnam(is_dir($cwd . '/tmp') ? $cwd . '/tmp' : sys_get_temp_dir(), 'bmg-cli-') : $null;
        if ($outputPath === false || ($capture && !chmod($outputPath, 0600))) { $this->fail(); }
        $process = null;
        $output = '';
        $start = microtime(true);
        $nextDiskCheck = $start + 1;
        $exit = -1;
        try {
            $process = @proc_open($argv, [0 => ['file', $null, 'r'], 1 => ['file', $outputPath, 'w'], 2 => ['file', $null, 'w']],
                $pipes, $cwd, $env, ['bypass_shell' => true, 'create_process_group' => true]);
            if (!is_resource($process)) { $this->fail(); }
            do {
                if ($capture) {
                    clearstatcache(true, $outputPath);
                    if (filesize($outputPath) > self::OUTPUT_LIMIT) { $this->fail(); }
                }
                if (microtime(true) - $start >= $timeout) { $this->fail(); }
                $state = proc_get_status($process);
                if (!$state['running']) { $exit = $state['exitcode']; break; }
                if (!$capture && microtime(true) >= $nextDiskCheck) {
                    $size = 0;
                    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cwd, \FilesystemIterator::SKIP_DOTS)) as $file) {
                        if ($file->isLink() || ($size += $file->getSize()) > self::JOB_LIMIT) { $this->fail(); }
                    }
                    $nextDiskCheck = microtime(true) + 1;
                }
                usleep(10000);
            } while (true);
            if ($capture) {
                $output = file_get_contents($outputPath, false, null, 0, self::OUTPUT_LIMIT + 1);
                if (!is_string($output) || strlen($output) > self::OUTPUT_LIMIT) { $this->fail(); }
            }
        } finally {
            if (is_resource($process)) {
                $state = proc_get_status($process);
                if ($state['running']) { $this->terminate($process, (int) $state['pid']); }
                proc_close($process);
            }
            if ($capture) { @unlink($outputPath); }
        }
        return ['exit_code' => $exit, 'stdout' => $output];
    }

    private function terminate(mixed $process, int $pid): void
    {
        // The Windows compiler spawns child processes. Terminate the tree before
        // deleting its credential-bearing intermediates, not just arduino-cli.exe.
        if (PHP_OS_FAMILY === 'Windows') {
            $system = getenv('SystemRoot');
            $taskkill = is_string($system) ? realpath($system . '/System32/taskkill.exe') : false;
            if ($taskkill !== false && $pid > 0) {
                $killer = @proc_open([$taskkill, '/PID', (string) $pid, '/T', '/F'],
                    [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null, null, ['bypass_shell' => true]);
                if (is_resource($killer)) {
                    $deadline = microtime(true) + 3;
                    while (proc_get_status($killer)['running'] && microtime(true) < $deadline) { usleep(10000); }
                    if (proc_get_status($killer)['running']) { proc_terminate($killer, 9); }
                    proc_close($killer);
                }
            }
        }
        @proc_terminate($process, 9);
    }

    private function manifest(string $dir): ?array
    {
        try {
            if (strlen($this->config->signingKey) < 32) { return null; }
            $path = $dir . '/manifest.json';
            $this->safePath($path);
            if (!is_file($path) || filesize($path) > 65536) { return null; }
            $envelope = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (!is_string($envelope['payload'] ?? null) || !is_string($envelope['signature'] ?? null)
                || !hash_equals(hash_hmac('sha256', $envelope['payload'], $this->config->signingKey), $envelope['signature'])) { return null; }
            $payload = json_decode($envelope['payload'], true, 32, JSON_THROW_ON_ERROR);
            return is_array($payload) ? $payload : null;
        } catch (Throwable) { return null; }
    }

    private function validTime(array $payload): bool
    {
        return is_int($payload['created'] ?? null) && is_int($payload['expires'] ?? null)
            && $payload['created'] <= time() && $payload['expires'] === $payload['created'] + BmgBuild::TTL && $payload['expires'] > time();
    }

    private function cleanup(): void
    {
        foreach (glob($this->root . '/build-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!preg_match('/\Abuild-[0-9a-f]{32}\z/D', basename($dir))) { continue; }
            $payload = $this->manifest($dir);
            if ($payload === null || !$this->validTime($payload)) { $this->remove($dir); }
        }
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private function safePath(string $path): void
    {
        for ($cursor = $path; ; $cursor = $parent) {
            if (is_link($cursor)) { $this->fail(); }
            $parent = dirname($cursor);
            if ($parent === $cursor || $parent === '.') { break; }
        }
        $real = realpath($path);
        if ($real === false || $this->normalize($real) !== $this->normalize($path)) { $this->fail(); }
    }

    private function directory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true)) { $this->fail(); }
        $this->safePath($path);
        if (!chmod($path, 0700)) { $this->fail(); }
    }

    private function write(string $path, #[\SensitiveParameter] string $bytes): void
    {
        if (file_exists($path) || is_link($path)) { $this->fail(); }
        $handle = @fopen($path, 'xb');
        if ($handle === false) { $this->fail(); }
        try {
            if (!chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes)) { $this->fail(); }
        } finally { fclose($handle); }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) { if (!@unlink($path)) { $this->fail(); } return; }
        if (!is_dir($path)) { return; }
        $this->safePath($path);
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $file) { $this->remove($file->getPathname()); }
        if (!@rmdir($path)) { $this->fail(); }
    }

    private function fail(): never { throw new RuntimeException('Local commissioning compiler unavailable or build integrity check failed.', 503); }
}
