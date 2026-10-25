<?php

declare(strict_types=1);

namespace App\Services\Facilities;

use App\Models\AuditEventModel;
use App\Services\Audit\AuditService;
use App\Services\AuthenticatedUser;
use App\Services\PortalContext;
use CodeIgniter\Database\BaseConnection;
use Config\BmgFirmware;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class BmgFirmwareService
{
    private const FLASH_BYTES = 16777216;
    private const SECTOR_BYTES = 4096;
    private BmgFirmware $config;
    private string $root;

    /** Root injection is for isolated tests; production always uses the private writable catalog. */
    public function __construct(private ?BaseConnection $db = null, ?BmgFirmware $config = null, ?string $root = null, private ?BmgBuildService $builds = null)
    {
        $this->config = $config ?? config(BmgFirmware::class);
        $this->root = rtrim($root ?? WRITEPATH . 'firmware/bmg', '/\\');
    }

    public function workspace(int $id): array
    {
        $actor = $this->actor();
        $detail = (new BmgRotationService($this->database()))->unit($id);
        $releases = $this->catalog();
        foreach ($this->builds()->privateReleases($id, $actor) as $entry) {
            $manifest = $this->validated($entry)['manifest'];
            $releases[] = array_intersect_key($manifest, array_flip(['id', 'name', 'chip_family', 'flash_size']));
        }
        $flashBlockers = $this->workspaceFlashBlockers($actor, $detail);
        return ['detail' => $detail, 'releases' => $releases,
            'flashEnabled' => $flashBlockers === [], 'flashBlockers' => $flashBlockers,
            'title' => 'BMG USB Workspace', 'pageTitle' => 'BMG USB Workspace'];
    }

    /** Public metadata only, with actual file integrity checked; an empty catalog is normal. */
    public function catalog(): array
    {
        $entries = $this->entries();
        $result = [];
        foreach ($entries as $entry) {
            $manifest = $this->validated($entry)['manifest'];
            $result[] = array_intersect_key($manifest, array_flip(['id', 'name', 'chip_family', 'flash_size']));
        }
        return $result;
    }

    public function manifest(int $id, string $releaseId): array
    {
        $this->accessUnit($id);
        return $this->withUrls($id, $this->validated($this->release($id, $releaseId))['manifest']);
    }

    /** Returns bounded, already hashed bytes, not a path to be reopened by the controller. */
    public function part(int $id, string $releaseId, int $index): array
    {
        $this->accessUnit($id);
        $release = $this->validated($this->release($id, $releaseId));
        if ($index < 0 || !isset($release['manifest']['parts'][$index])) {
            throw new RuntimeException('Firmware part not found.', 404);
        }
        return ['name' => $releaseId . '-' . $index . '.bin', 'size' => $release['manifest']['parts'][$index]['size'],
            'body' => $release['bodies'][$index]];
    }

    public function authorize(int $id, array $data): array
    {
        $actor = $this->actor(true);
        $this->keys($data, ['release_id', 'chip_family', 'chip_mac', 'isolated_confirmed', 'expected_version']);
        if (!is_string($data['release_id'] ?? null) || ($data['chip_family'] ?? null) !== 'ESP32-S3'
            || !is_string($data['chip_mac'] ?? null)
            || !preg_match('/\A(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\z/Di', $data['chip_mac'])
            || !in_array($data['isolated_confirmed'] ?? null, [true, 1, '1', 'true'], true)) {
            throw new InvalidArgumentException('A supported chip, detected MAC, release, and explicit isolation confirmation are required.');
        }
        $expected = $data['expected_version'] ?? null;
        if ((!is_int($expected) && !is_string($expected))
            || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $expected)
            || (int) $expected > 2147483646) {
            throw new InvalidArgumentException('Invalid expected_version.');
        }
        $manifest = $this->withUrls($id, $this->validated($this->release($id, $data['release_id']))['manifest']);
        $db = $this->database();
        if (!$db->transBegin()) { throw new RuntimeException('USB authorization unavailable.', 503); }
        try {
            // Same tenant mutex as rotation configuration and command publication.
            $table = $db->protectIdentifiers($db->prefixTable('tenants'));
            $lock = $db->DBDriver === 'SQLite3' ? '' : ' FOR UPDATE';
            $tenant = (int) $actor['tenant_id'];
            $row = $db->query('SELECT id FROM ' . $table . ' WHERE id = ? AND is_active = 1 AND deleted_at IS NULL' . $lock, [$tenant])->getRowArray();
            $fresh = $this->actor(true);
            if ($row === null || $fresh['id'] !== $actor['id'] || $fresh['tenant_id'] !== $actor['tenant_id']) {
                throw new RuntimeException('USB access denied.', 403);
            }
            if (!$this->flashEnabled()) { throw new RuntimeException('USB flashing is disabled or hardware operation is enabled.', 409); }
            $unit = $db->table('bmg_units')->where('tenant_id', $tenant)->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
            if ($unit === null) { throw new RuntimeException('Unit not found.', 404); }
            $schedule = $db->table('bmg_rotation_schedules')->where('tenant_id', $tenant)->where('unit_id', $id)->get()->getRowArray();
            if ($unit['status'] !== 'maintenance' || $schedule === null || (bool) $schedule['enabled']
                || (int) $schedule['version'] !== (int) $expected) {
                throw new RuntimeException('Maintenance, a disabled published schedule, and its current version are required.', 409);
            }
            $devices = $db->table('bmg_devices')->where('tenant_id', $tenant)->where('unit_id', $id)
                ->where('status', 'active')->where('revoked_at', null)->where('rotation_bound_at !=', null)->limit(2)->get()->getResultArray();
            if (count($devices) !== 1 || !hash_equals(strtolower($devices[0]['chip_mac']), strtolower($data['chip_mac']))) {
                throw new RuntimeException('Detected chip does not match the current device binding.', 409);
            }
            if ($this->hasPendingCommands($tenant, $id)) {
                throw new RuntimeException('Unexpired pending commands must be resolved first.', 409);
            }
            // Private binding/expiry must still be current under the rotation tenant mutex.
            if (str_starts_with($data['release_id'], 'build-')) { $this->release($id, $data['release_id']); }
            // Hash the immutable release metadata, not deployment-specific URLs or any credentials.
            $approved = $manifest;
            foreach ($approved['parts'] as &$part) { unset($part['url']); }
            unset($part);
            $event = (new AuditService(new AuditEventModel($db)))->log('bmg_usb.flash_requested', 'bmg_unit', (string) $id,
                'flash_requested', ['unit_id' => $id, 'device_id' => (int) $devices[0]['id'], 'release_id' => $manifest['id'],
                    'release_sha256' => hash('sha256', json_encode($approved, JSON_THROW_ON_ERROR)),
                    'schedule_version' => (int) $expected], (int) $fresh['id'], $fresh['role']);
            if (empty($event['id']) || !$db->transStatus() || !$db->transCommit()) {
                throw new RuntimeException('USB authorization could not be recorded.', 503);
            }
            return ['success' => true, 'manifest' => $manifest,
                'message' => 'Flash request authorized only. This does not verify board acceptance, physical isolation, or a successful flash.'];
        } catch (Throwable $error) {
            $db->transRollback();
            if ($error instanceof RuntimeException && in_array($error->getCode(), [403, 404, 409, 503], true)) { throw $error; }
            throw new RuntimeException('USB authorization unavailable.', 503, $error);
        }
    }

    private function database(): BaseConnection { return $this->db ??= Database::connect(); }

    private function actor(bool $hardware = false): array
    {
        $request = service('request');
        if ($request->getHeaderLine('X-Device-Token') !== '' || $request->getHeaderLine('Authorization') !== '') {
            throw new RuntimeException('Human session authentication required.', 403);
        }
        $actor = (new AuthenticatedUser())->current();
        $required = ['facilities.read', 'facilities.configure', 'facilities.manage'];
        if ($hardware) { $required[] = 'facilities.hardware.manage'; }
        if ($actor === null || !PortalContext::isRolePermitted($actor['role'], PortalContext::PORTAL_BMG)
            || array_diff($required, $actor['permissions']) !== []) {
            throw new RuntimeException('USB workspace permission required.', 403);
        }
        return $actor;
    }

    private function accessUnit(int $id): void
    {
        $actor = $this->actor();
        if ($this->database()->table('bmg_units')->where('tenant_id', $actor['tenant_id'])->where('id', $id)
            ->where('deleted_at', null)->countAllResults() !== 1) { throw new RuntimeException('Unit not found.', 404); }
    }

    /** UI readiness only: detection, confirmation, version, and fresh authority are rechecked on POST. */
    private function workspaceFlashBlockers(array $actor, array $detail): array
    {
        $blockers = [];
        if (!in_array(env('BMG_USB_FLASH_ENABLED', false), [true, 'true'], true)) {
            $blockers[] = 'Server USB flashing is disabled. Ask an administrator to review BMG_USB_FLASH_ENABLED.';
        }
        if (!in_array(env('BMG_HARDWARE_ENABLED', false), [false, 'false'], true)) {
            $blockers[] = 'The motor hardware gate is enabled or invalid. USB flashing requires BMG_HARDWARE_ENABLED to be false.';
        }
        if (!in_array('facilities.hardware.manage', $actor['permissions'], true)) {
            $blockers[] = 'Your account requires the explicit facilities.hardware.manage permission before flashing.';
        }
        if ($detail['unit']['status'] !== 'maintenance') {
            $blockers[] = 'Place this unit in maintenance before flashing.';
        }
        if ($detail['schedule']['enabled']) {
            $blockers[] = 'Disable and publish the rotation schedule before flashing.';
        }
        $tenant = (int) $actor['tenant_id'];
        $id = (int) $detail['unit']['id'];
        if ($this->database()->table('bmg_rotation_schedules')->where('tenant_id', $tenant)->where('unit_id', $id)->countAllResults() !== 1) {
            $blockers[] = 'Publish exactly one rotation schedule version for this unit before flashing.';
        }
        if (!is_string($detail['device']['chip_mac'] ?? null)
            || !preg_match('/\A(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\z/Di', $detail['device']['chip_mac'])) {
            $blockers[] = 'Bind one active device with a valid chip MAC to this unit before flashing.';
        }
        if ($this->hasPendingCommands($tenant, $id)) {
            $blockers[] = 'Resolve unexpired pending commands before flashing.';
        }
        return $blockers;
    }

    private function hasPendingCommands(int $tenant, int $id): bool
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        return $this->database()->table('bmg_rotation_commands')->where('tenant_id', $tenant)->where('unit_id', $id)
            ->where('status', 'pending')->where('expires_at >', $now)->countAllResults() > 0;
    }

    private function flashEnabled(): bool
    {
        return in_array(env('BMG_USB_FLASH_ENABLED', false), [true, 'true'], true)
            && in_array(env('BMG_HARDWARE_ENABLED', false), [false, 'false'], true);
    }

    private function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) { throw new InvalidArgumentException('Unsupported input fields.'); }
    }

    private function slug(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 80 && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $value) === 1;
    }

    private function label(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9 ._()-]{0,99}\z/D', $value) === 1;
    }

    private function entries(): array
    {
        if (!array_is_list($this->config->releases) || count($this->config->releases) > 100) { $this->unavailable(); }
        $seen = [];
        foreach ($this->config->releases as $entry) {
            if (!is_array($entry) || !$this->slug($entry['id'] ?? null) || isset($seen[$entry['id']])) { $this->unavailable(); }
            $seen[$entry['id']] = true;
        }
        return $this->config->releases;
    }

    private function builds(): BmgBuildService
    {
        return $this->builds ??= new BmgBuildService($this->database(), null, $this->root);
    }

    private function release(int $unit, string $id): array
    {
        if (str_starts_with($id, 'build-')) { return $this->builds()->privateRelease($unit, $id, $this->actor()); }
        if (!$this->slug($id)) { throw new RuntimeException('Firmware release not found.', 404); }
        foreach ($this->entries() as $entry) { if ($entry['id'] === $id) { return $entry; } }
        throw new RuntimeException('Firmware release not found.', 404);
    }

    private function validated(array $entry): array
    {
        try {
            $this->keys($entry, ['id', 'name', 'chip_family', 'flash_size', 'parts']);
            if (!$this->slug($entry['id'] ?? null) || !$this->label($entry['name'] ?? null)
                || ($entry['chip_family'] ?? null) !== 'ESP32-S3' || ($entry['flash_size'] ?? null) !== '16MB'
                || !is_array($entry['parts'] ?? null) || !array_is_list($entry['parts'])
                || count($entry['parts']) < 1 || count($entry['parts']) > 32) { $this->unavailable(); }
            $manifest = array_intersect_key($entry, array_flip(['id', 'name', 'chip_family', 'flash_size']));
            $manifest['parts'] = [];
            $bodies = $ranges = $seen = [];
            $total = 0;
            foreach ($entry['parts'] as $part) {
                if (!is_array($part)) { $this->unavailable(); }
                $this->keys($part, ['name', 'filename', 'address', 'sha256', 'size']);
                $filename = $part['filename'] ?? null;
                if (!$this->label($part['name'] ?? null) || !is_string($filename) || strlen($filename) > 240
                    || !preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*\z/D', $filename)
                    || !is_int($part['address'] ?? null) || $part['address'] < 0 || $part['address'] >= self::FLASH_BYTES
                                        || $part['address'] % self::SECTOR_BYTES !== 0
                    || !is_string($part['sha256'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D', $part['sha256'])
                    || isset($seen['file:' . strtolower($filename)]) || isset($seen['name:' . strtolower($part['name'])])) { $this->unavailable(); }
                $seen['file:' . strtolower($filename)] = $seen['name:' . strtolower($part['name'])] = true;
                $path = $this->root . '/' . $entry['id'] . '/' . $filename;
                // Refuse symlinks at every level, including the configured catalog root.
                for ($cursor = $path; ; $cursor = $parent) {
                    if (is_link($cursor)) { $this->unavailable(); }
                    $parent = dirname($cursor);
                    if ($parent === $cursor || $parent === '.') { break; }
                }
                $root = realpath($this->root);
                $base = realpath($this->root . '/' . $entry['id']);
                $real = realpath($path);
                if ($root === false || $base === false || $real === false || !is_file($path)) { $this->unavailable(); }
                $normalize = static fn (string $value): string => PHP_OS_FAMILY === 'Windows'
                    ? strtolower(str_replace('\\', '/', $value)) : str_replace('\\', '/', $value);
                // Exact canonical paths also reject junctions and Windows filename aliases.
                if ($normalize($base) !== $normalize($root . '/' . $entry['id'])
                    || $normalize($real) !== $normalize($base . '/' . $filename)
                    || isset($seen['real:' . $normalize($real)])) { $this->unavailable(); }
                $seen['real:' . $normalize($real)] = true;
                $handle = @fopen($path, 'rb');
                if ($handle === false) { $this->unavailable(); }
                try {
                    $stat = fstat($handle);
                    if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] < 1
                        || $stat['size'] > self::FLASH_BYTES - $total) { $this->unavailable(); }
                    $size = $stat['size'];
                    if ((array_key_exists('size', $part) && (!is_int($part['size']) || $part['size'] !== $size))
                        || $part['address'] > self::FLASH_BYTES - $size) { $this->unavailable(); }
                    $bytes = stream_get_contents($handle, $size + 1);
                    if (!is_string($bytes) || strlen($bytes) !== $size || !hash_equals($part['sha256'], hash('sha256', $bytes))) { $this->unavailable(); }
                } finally { fclose($handle); }
                // esptool writeFlash erases whole sectors, including the final partial sector.
                $eraseSize = intdiv($size + self::SECTOR_BYTES - 1, self::SECTOR_BYTES) * self::SECTOR_BYTES;
                if ($part['address'] > self::FLASH_BYTES - $eraseSize) { $this->unavailable(); }
                $eraseEnd = $part['address'] + $eraseSize;
                foreach ($ranges as [$start, $end]) {
                    if ($part['address'] < $end && $eraseEnd > $start) { $this->unavailable(); }
                }
                $ranges[] = [$part['address'], $eraseEnd];
                $total += $size;
                $bodies[] = $bytes;
                $manifest['parts'][] = ['name' => $part['name'], 'address' => $part['address'], 'size' => $size, 'sha256' => $part['sha256']];
            }
            return ['manifest' => $manifest, 'bodies' => $bodies];
        } catch (Throwable $error) {
            throw new RuntimeException('Approved firmware is unavailable or failed integrity validation.', 503, $error);
        }
    }

    private function withUrls(int $id, array $manifest): array
    {
        foreach ($manifest['parts'] as $index => &$part) {
            $part['url'] = site_url('facilities/unit/' . $id . '/usb/releases/' . $manifest['id'] . '/parts/' . $index);
        }
        unset($part);
        return $manifest;
    }

    private function unavailable(): never
    {
        throw new RuntimeException('Approved firmware is unavailable or failed integrity validation.', 503);
    }
}
