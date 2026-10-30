<?php

declare(strict_types=1);

namespace App\Services\Facilities;

use App\Libraries\TablePage;
use App\Models\AuditEventModel;
use App\Services\Audit\AuditService;
use App\Services\AuthenticatedUser;
use App\Services\PortalContext;
use App\Services\WorkflowNotifications;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Persisted rotation intent and device-reported facts only; never drives a motor.
 *
 * NEW firmware contract, NOT verified against legacy hardware: X-Device-Token
 * credentials; Manila second-resolution timestamps; occurred_at is the run START
 * time, strictly after registration and never in the future. Unknown/prebinding
 * runs are rejected rather than reassigned. Firmware must honor effective enabled,
 * forward-only direction, command expiry and durable command/run ID deduplication,
 * and report applied versions/RTC/firmware explicitly. Acknowledgements are device
 * claims, not independently verified execution. *_reported_at is server receipt
 * time for that field, not a device sampling time; omitted fields retain their age.
 * Hardware stays disabled by default pending firmware and safety verification.
 * Storage timestamps intentionally remain Asia/Manila wall time, not UTC.
 * Unit creates/edits require explicit drum_capacity_kg; no capacity is inferred.
 */
final class BmgRotationService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function overview(bool $archived = false): array
    {
        $actor = $archived ? $this->configurationActor() : $this->actor('facilities.read');
        $units = $this->db->table('bmg_units')->where('tenant_id', $actor['tenant_id'])
            ->where($archived ? 'deleted_at !=' : 'deleted_at', null)->orderBy('id')->limit(200)->get()->getResultArray();
        return array_map(fn (array $unit): array => $this->detail($unit), $units);
    }

    /** Count before clamping and hydrate only the requested directory page. */
    public function listing(array $query = [], bool $archived = false): array
    {
        $actor = $archived ? $this->configurationActor() : $this->actor('facilities.read');
        $page = TablePage::forList($query);
        $stats = $this->directoryStats((int) $actor['tenant_id'], $archived);
        $pagination = $page->metadata($stats['total'], $stats['total']);
        $units = $this->db->table('bmg_units')->where('tenant_id', $actor['tenant_id'])
            ->where($archived ? 'deleted_at !=' : 'deleted_at', null)->orderBy('id')
            ->limit($page->perPage, ($pagination['page'] - 1) * $page->perPage)->get()->getResultArray();
        return ['units' => array_map(fn (array $unit): array => $this->detail($unit), $units),
            'pagination' => $pagination, 'stats' => $stats];
    }

    public function statistics(bool $archived = false): array
    {
        $actor = $archived ? $this->configurationActor() : $this->actor('facilities.read');
        return $this->directoryStats((int) $actor['tenant_id'], $archived);
    }

    private function directoryStats(int $tenant, bool $archived): array
    {
        $scope = fn () => $this->db->table('bmg_units')->where('tenant_id', $tenant)
            ->where($archived ? 'deleted_at !=' : 'deleted_at', null);
        $stats = ['total' => 0, 'active' => 0, 'maintenance' => 0, 'bound' => 0];
        foreach ($scope()->select('status')->selectCount('id', 'unit_count')->groupBy('status')->get()->getResultArray() as $row) {
            $count = (int) $row['unit_count'];
            $stats['total'] += $count;
            if (in_array($row['status'], ['active', 'maintenance'], true)) { $stats[$row['status']] = $count; }
        }
        // Match boundDevice(): ambiguous multiple bindings are not a bound controller.
        $bindings = $this->db->table('bmg_devices')->select('unit_id')->where('tenant_id', $tenant)
            ->where('status', 'active')->where('revoked_at', null)->where('rotation_bound_at !=', null)
            ->groupBy('unit_id')->having('COUNT(*) = 1', null, false);
        $stats['bound'] = $scope()->whereIn('id', $bindings)->countAllResults();
        return $stats;
    }

    public function unit(int $id): array
    {
        $actor = $this->actor('facilities.read');
        return $this->detail($this->findUnit((int) $actor['tenant_id'], $id));
    }

    /** Create/update unit configuration; capacity is required, and edits require expected_version. */
    public function saveUnit(?int $id, array $data): array
    {
        $actor = $this->configurationActor();
        $this->keys($data, ['unit_code', 'name', 'status', 'drum_capacity_kg', 'expected_version']);
        $code = $this->text($data['unit_code'] ?? null, 32, 'unit_code');
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $code)) {
            throw new InvalidArgumentException('Invalid unit_code.');
        }
        $name = $this->text($data['name'] ?? null, 100, 'name');
        $capacity = $this->capacity($data['drum_capacity_kg'] ?? null);
        $status = $this->choice($data['status'] ?? 'offline', ['active', 'maintenance', 'offline'], 'status');
        return $this->locked((int) $actor['tenant_id'], function () use ($actor, $id, $code, $name, $status, $capacity, $data): array {
            $tenant = (int) $actor['tenant_id'];
            if ($id !== null) {
                $this->findUnit($tenant, $id);
                $this->expectVersion($tenant, $id, $data['expected_version'] ?? null);
                // BmgService takes this same tenant mutex before accepting batch input.
                if ($this->db->table('bmg_batches')->where('tenant_id', $tenant)->where('unit_id', $id)
                    ->whereIn('status', ['processing', 'awaiting_output', 'curing'])
                    ->where('total_input_weight_kg >', $capacity)->countAllResults() > 0) {
                    throw new RuntimeException('Capacity cannot be below active batch input weight.', 409);
                }
            }
            $duplicate = $this->db->table('bmg_units')->where('tenant_id', $tenant)->where('unit_code', $code);
            if ($id !== null) { $duplicate->where('id !=', $id); }
            if ($duplicate->countAllResults() > 0) { throw new RuntimeException('Unit code already exists.', 409); }
            $values = ['unit_code' => $code, 'name' => $name, 'status' => $status,
                'drum_capacity_kg' => $capacity, 'updated_at' => $this->now()];
            if ($id === null) {
                $this->db->table('bmg_units')->insert($values + ['tenant_id' => $tenant, 'created_at' => $this->now()]);
                $id = (int) $this->db->insertID();
                $this->persistSchedule($tenant, $id, (int) $actor['id'], false, [], 60, null);
            } else {
                $this->db->table('bmg_units')->where('tenant_id', $tenant)->where('id', $id)->update($values);
                if ($status !== 'active') {
                    $this->disable($tenant, $id, (int) $actor['id']);
                } else {
                    $old = $this->scheduleView($tenant, $id);
                    $this->persistSchedule($tenant, $id, (int) $actor['id'], $old['enabled'], $old['times'], $old['duration_seconds'], $old['pwm']);
                }
            }
            $this->audit($actor, 'bmg_unit', $id, 'save', $values);
            return $this->detail($this->findUnit($tenant, $id));
        });
    }

    /** Retirement acknowledges checked physical safety, independently of the server hardware gate. */
    public function archiveUnit(int $id, array $data): array
    {
        $actor = $this->configurationActor();
        $this->keys($data, ['expected_version', 'retirement_confirmed']);
        if (!in_array($data['retirement_confirmed'] ?? null, [true, '1'], true)) {
            throw new InvalidArgumentException('retirement_confirmed must be true or "1" to acknowledge checked physical safety.');
        }
        return $this->locked((int) $actor['tenant_id'], function () use ($actor, $id, $data): array {
            $fresh = $this->configurationActor();
            if ($fresh['id'] !== $actor['id'] || $fresh['tenant_id'] !== $actor['tenant_id']) {
                throw new RuntimeException('Configuration actor changed.', 403);
            }
            $tenant = (int) $fresh['tenant_id'];
            $this->findUnit($tenant, $id);
            $this->expectVersion($tenant, $id, $data['expected_version'] ?? null);
            if ($this->db->table('bmg_batches')->where('tenant_id', $tenant)->where('unit_id', $id)
                ->whereIn('status', ['processing', 'awaiting_output', 'curing'])->countAllResults() > 0) {
                throw new RuntimeException('Units with active batches cannot be archived.', 409);
            }
            $revoked = $this->retireBindings($tenant, $id, (int) $fresh['id']);
            $now = $this->now();
            $this->db->table('bmg_units')->where('tenant_id', $tenant)->where('id', $id)
                ->update(['status' => 'offline', 'deleted_at' => $now, 'updated_at' => $now]);
            $version = $this->scheduleView($tenant, $id)['version'];
            $this->audit($fresh, 'bmg_unit', $id, 'archive', ['schedule_version' => $version, 'revoked_device_count' => $revoked]);
            return ['unit_id' => $id, 'schedule_version' => $version];
        });
    }

    public function restoreUnit(int $id, array $data): array
    {
        $actor = $this->configurationActor();
        $this->keys($data, ['expected_version']);
        return $this->locked((int) $actor['tenant_id'], function () use ($actor, $id, $data): array {
            $fresh = $this->configurationActor();
            if ($fresh['id'] !== $actor['id'] || $fresh['tenant_id'] !== $actor['tenant_id']) {
                throw new RuntimeException('Configuration actor changed.', 403);
            }
            $tenant = (int) $fresh['tenant_id'];
            $unit = $this->db->table('bmg_units')->where('tenant_id', $tenant)->where('id', $id)
                ->where('deleted_at !=', null)->get()->getRowArray();
            if ($unit === null) { throw new RuntimeException('Archived unit not found.', 404); }
            $this->expectVersion($tenant, $id, $data['expected_version'] ?? null);
            if ($this->db->table('bmg_units')->where('tenant_id', $tenant)->where('unit_code', $unit['unit_code'])
                ->where('id !=', $id)->countAllResults() > 0) {
                throw new RuntimeException('Unit code already exists.', 409);
            }
            $revoked = $this->retireBindings($tenant, $id, (int) $fresh['id']);
            $this->db->table('bmg_units')->where('tenant_id', $tenant)->where('id', $id)
                ->update(['deleted_at' => null, 'status' => 'offline', 'updated_at' => $this->now()]);
            $this->audit($fresh, 'bmg_unit', $id, 'restore', [
                'schedule_version' => $this->scheduleView($tenant, $id)['version'], 'revoked_device_count' => $revoked]);
            return $this->detail($this->findUnit($tenant, $id));
        });
    }

    /** Preserve history, but never allow old intent or credentials to operate a retired unit. */
    private function retireBindings(int $tenant, int $unit, int $actor): int
    {
        $this->disable($tenant, $unit, $actor);
        $devices = $this->db->table('bmg_devices')->where('tenant_id', $tenant)->where('unit_id', $unit)->countAllResults();
        $now = $this->now();
        $this->db->table('bmg_devices')->where('tenant_id', $tenant)->where('unit_id', $unit)
            ->update(['status' => 'revoked', 'unit_id' => null, 'revoked_at' => $now, 'updated_at' => $now]);
        return $devices;
    }

    /** Full replacement: expected_version required, enabled, times (0..8 HH:MM), duration_seconds, optional pwm. */
    public function saveSchedule(int $unitId, array $data): array
    {
        $actor = $this->configurationActor();
        $this->keys($data, ['enabled', 'times', 'duration_seconds', 'pwm', 'expected_version']);
        $enabled = $this->boolean($data['enabled'] ?? false, 'enabled');
        $times = $data['times'] ?? [];
        if (!is_array($times) || !array_is_list($times) || count($times) > 8) {
            throw new InvalidArgumentException('times must be a list of up to eight Manila HH:MM times.');
        }
        foreach ($times as $time) {
            if (!is_string($time) || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $time)) {
                throw new InvalidArgumentException('Invalid schedule time; use HH:MM.');
            }
        }
        if (count(array_unique($times)) !== count($times) || ($enabled && $times === [])) {
            throw new InvalidArgumentException('Enabled schedules need distinct times.');
        }
        sort($times);
        $duration = $this->integer($data['duration_seconds'] ?? 60, 1, 600, 'duration_seconds');
        // Reject overlapping windows, including the midnight wrap.
        if (count($times) > 1) {
            $minutes = array_map(static fn (string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2), $times);
            $minutes[] = $minutes[0] + 1440;
            for ($i = 1; $i < count($minutes); $i++) {
                if (($minutes[$i] - $minutes[$i - 1]) * 60 < $duration) {
                    throw new InvalidArgumentException('Schedule rotation windows overlap.');
                }
            }
        }
        $pwm = array_key_exists('pwm', $data) && $data['pwm'] !== null
            ? $this->integer($data['pwm'], 1, 255, 'pwm') : null;
        return $this->locked((int) $actor['tenant_id'], function () use ($actor, $unitId, $enabled, $times, $duration, $pwm, $data): array {
            $tenant = (int) $actor['tenant_id'];
            $unit = $this->findUnit($tenant, $unitId);
            $this->expectVersion($tenant, $unitId, $data['expected_version'] ?? null);
            $old = $this->scheduleView($tenant, $unitId);
            // Safe shutdown is allowed without hardware authority; configuration is not.
            if ($enabled || $pwm !== null || $times !== $old['times'] || $duration !== $old['duration_seconds']) {
                $this->hardware($actor);
            }
            if ($enabled && ($unit['status'] !== 'active' || $this->boundDevice($tenant, $unitId) === null)) {
                throw new RuntimeException('Enabling requires an active unit and exactly one bound active device.', 409);
            }
            $this->persistSchedule($tenant, $unitId, (int) $actor['id'], $enabled, $times, $duration, $pwm);
            $this->cancelCommands($tenant, $unitId, (int) $actor['id']);
            $this->audit($actor, 'bmg_unit', $unitId, 'schedule', $this->scheduleView($tenant, $unitId));
            return $this->scheduleView($tenant, $unitId);
        });
    }

    /** Registration binds to this explicit unit, rotates credentials, and disables its schedule.
     * Optional device_id may rebind an unbound device or rotate one already on this unit.
     * token is returned exactly once; only its SHA-256 hash is persisted.
     */
    public function registerDevice(int $unitId, array $data): array
    {
        $actor = $this->configurationActor();
        // Provisioning is configuration, not permission to operate the motor.
        // Binding below always disables the schedule and cancels pending commands.
        $this->keys($data, ['chip_mac', 'device_id', 'expected_version']);
        $mac = strtolower($this->text($data['chip_mac'] ?? null, 17, 'chip_mac'));
        if (!preg_match('/\A(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\z/D', $mac)) {
            throw new InvalidArgumentException('chip_mac must be a MAC address.');
        }
        $id = isset($data['device_id']) ? $this->integer($data['device_id'], 1, PHP_INT_MAX, 'device_id') : null;
        return $this->locked((int) $actor['tenant_id'], function () use ($actor, $unitId, $mac, $id, $data): array {
            $tenant = (int) $actor['tenant_id'];
            $this->findUnit($tenant, $unitId);
            $this->expectVersion($tenant, $unitId, $data['expected_version'] ?? null);
            if ($id !== null) {
                $device = $this->db->table('bmg_devices')->where('tenant_id', $tenant)->where('id', $id)->get()->getRowArray();
                if ($device === null) { throw new RuntimeException('Device not found.', 404); }
                if ($device['unit_id'] !== null && (int) $device['unit_id'] !== $unitId) {
                    throw new RuntimeException('Revoke the existing binding before rebinding.', 409);
                }
            }
            $duplicate = $this->db->table('bmg_devices')->where('tenant_id', $tenant)->where('chip_mac', $mac);
            if ($id !== null) { $duplicate->where('id !=', $id); }
            if ($duplicate->countAllResults() > 0) { throw new RuntimeException('MAC already registered; supply its device_id.', 409); }
            // Revoke all previous credentials for this unit before issuing a replacement.
            $this->db->table('bmg_devices')->where('tenant_id', $tenant)->where('unit_id', $unitId)
                ->update(['status' => 'revoked', 'unit_id' => null, 'revoked_at' => $this->now(), 'updated_at' => $this->now()]);
            $token = bin2hex(random_bytes(32));
            $values = ['unit_id' => $unitId, 'chip_mac' => $mac, 'device_token_hash' => hash('sha256', $token),
                'status' => 'active', 'revoked_at' => null, 'last_seen_at' => null, 'reported_firmware_version' => null,
                'rtc_valid' => null, 'applied_schedule_version' => null, 'rotation_bound_at' => $this->now(),
                'firmware_reported_at' => null, 'rtc_reported_at' => null, 'schedule_reported_at' => null, 'updated_at' => $this->now()];
            if ($id === null) {
                $this->db->table('bmg_devices')->insert($values + ['tenant_id' => $tenant, 'created_at' => $this->now()]);
                $id = (int) $this->db->insertID();
            } else {
                $this->db->table('bmg_devices')->where('tenant_id', $tenant)->where('id', $id)->update($values);
            }
            $this->disable($tenant, $unitId, (int) $actor['id']);
            $this->audit($actor, 'bmg_device', $id, 'register', ['unit_id' => $unitId, 'chip_mac' => $mac]);
            return ['device_id' => $id, 'unit_id' => $unitId, 'token' => $token,
                'schedule_version' => $this->scheduleView($tenant, $unitId)['version']];
        });
    }

    public function revokeDevice(int $deviceId, mixed $expectedVersion = null): array
    {
        $actor = $this->configurationActor();
        return $this->locked((int) $actor['tenant_id'], function () use ($actor, $deviceId, $expectedVersion): array {
            $tenant = (int) $actor['tenant_id'];
            $device = $this->db->table('bmg_devices')->where('tenant_id', $tenant)->where('id', $deviceId)->get()->getRowArray();
            if ($device === null) { throw new RuntimeException('Device not found.', 404); }
            if ($device['unit_id'] !== null) {
                $this->findUnit($tenant, (int) $device['unit_id']);
                $this->expectVersion($tenant, (int) $device['unit_id'], $expectedVersion);
            }
            $this->db->table('bmg_devices')->where('tenant_id', $tenant)->where('id', $deviceId)
                ->update(['status' => 'revoked', 'unit_id' => null, 'revoked_at' => $this->now(), 'updated_at' => $this->now()]);
            if ($device['unit_id'] !== null) { $this->disable($tenant, (int) $device['unit_id'], (int) $actor['id']); }
            $this->audit($actor, 'bmg_device', $deviceId, 'revoke', ['unit_id' => $device['unit_id']]);
            return ['status' => 'revoked', 'device_id' => $deviceId];
        });
    }

    public function command(int $unitId, array $data): array
    {
        $actor = $this->actor('facilities.manage');
        $this->hardware($actor);
        $this->keys($data, ['command', 'duration_seconds', 'pwm', 'expires_in_seconds', 'expected_version']);
        $kind = $this->choice($data['command'] ?? null, ['run_now', 'stop'], 'command');
        $ttl = $this->integer($data['expires_in_seconds'] ?? 60, 5, 300, 'expires_in_seconds');
        $duration = $kind === 'run_now' ? $this->integer($data['duration_seconds'] ?? 60, 1, 600, 'duration_seconds') : null;
        $pwm = isset($data['pwm']) ? $this->integer($data['pwm'], 1, 255, 'pwm') : null;
        if ($kind === 'stop' && (array_key_exists('duration_seconds', $data) || array_key_exists('pwm', $data))) {
            throw new InvalidArgumentException('stop does not accept duration_seconds or pwm.');
        }
        return $this->locked((int) $actor['tenant_id'], function () use ($actor, $unitId, $kind, $ttl, $duration, $pwm, $data): array {
            $tenant = (int) $actor['tenant_id'];
            $unit = $this->findUnit($tenant, $unitId);
            $this->expectVersion($tenant, $unitId, $data['expected_version'] ?? null);
            $device = $this->boundDevice($tenant, $unitId);
            if ($device === null || ($kind === 'run_now' && $unit['status'] !== 'active')) {
                throw new RuntimeException('Command requires a bound device and an active unit for run_now.', 409);
            }
            $this->expireCommands($tenant, (int) $device['id']);
            if ($kind === 'stop') {
                // A delayed run must never be delivered after a stop request.
                $this->cancelCommands($tenant, $unitId, (int) $actor['id']);
            } elseif ($this->db->table('bmg_rotation_commands')->where('tenant_id', $tenant)->where('unit_id', $unitId)
                ->where('status', 'pending')->countAllResults() > 0) {
                throw new RuntimeException('A command is already pending.', 409);
            }
            $values = ['tenant_id' => $tenant, 'unit_id' => $unitId, 'device_id' => (int) $device['id'],
                'command' => $kind, 'duration_seconds' => $duration, 'pwm' => $pwm, 'status' => 'pending',
                'created_by_user_id' => (int) $actor['id'], 'created_at' => $this->now(),
                'expires_at' => $this->clock()->modify('+' . $ttl . ' seconds')->format('Y-m-d H:i:s')];
            $this->db->table('bmg_rotation_commands')->insert($values);
            $id = (int) $this->db->insertID();
            $this->audit($actor, 'bmg_rotation_command', $id, 'queue', $values);
            return ['command_id' => $id, 'status' => 'pending', 'expires_at' => $values['expires_at']];
        });
    }

    public function deviceSchedule(string $token): array
    {
        return $this->deviceOperation($token, function (array $device): array {
            $tenant = (int) $device['tenant_id'];
            if ($device['unit_id'] === null) {
                return $this->defaultSchedule() + ['published_enabled' => false, 'server_time' => $this->now(), 'hardware_enabled' => $this->hardwareEnabled()];
            }
            $unit = $this->findUnit($tenant, (int) $device['unit_id']);
            $schedule = $this->scheduleView($tenant, (int) $unit['id']);
            $bound = $this->boundDevice($tenant, (int) $unit['id']);
            $schedule['published_enabled'] = $schedule['enabled'];
            $schedule['enabled'] = $schedule['enabled'] && $this->hardwareEnabled() && $unit['status'] === 'active'
                && $bound !== null && (int) $bound['id'] === (int) $device['id'];
            if (!$schedule['enabled']) { $schedule['pwm'] = null; }
            return $schedule + ['hardware_enabled' => $this->hardwareEnabled(), 'server_time' => $this->now()];
        });
    }

    /** Omitted telemetry remains unknown/previously reported, never inferred from liveness. */
    public function heartbeat(string $token, array $data): array
    {
        $this->keys($data, ['firmware_version', 'rtc_valid', 'applied_schedule_version']);
        $values = [];
        if (array_key_exists('firmware_version', $data)) { $values['reported_firmware_version'] = $this->text($data['firmware_version'], 32, 'firmware_version'); }
        if (array_key_exists('rtc_valid', $data)) { $values['rtc_valid'] = $this->boolean($data['rtc_valid'], 'rtc_valid'); }
        if (array_key_exists('applied_schedule_version', $data)) {
            $values['applied_schedule_version'] = $this->integer($data['applied_schedule_version'], 0, 2147483647, 'applied_schedule_version');
        }
        return $this->deviceOperation($token, function (array $device) use ($values): array {
            foreach (['reported_firmware_version' => 'firmware_reported_at', 'rtc_valid' => 'rtc_reported_at',
                'applied_schedule_version' => 'schedule_reported_at'] as $field => $timestamp) {
                if (array_key_exists($field, $values)) { $values[$timestamp] = $this->now(); }
            }
            $version = $device['unit_id'] === null ? 0 : $this->scheduleView((int) $device['tenant_id'], (int) $device['unit_id'])['version'];
            if (isset($values['applied_schedule_version']) && $values['applied_schedule_version'] > $version) {
                throw new InvalidArgumentException('applied_schedule_version exceeds the published version.');
            }
            $this->db->table('bmg_devices')->where('tenant_id', $device['tenant_id'])->where('id', $device['id'])
                ->update($values + ['last_seen_at' => $this->now(), 'updated_at' => $this->now()]);
            return ['status' => 'ok', 'server_time' => $this->now(), 'published_schedule_version' => $version];
        });
    }

    /** Device facts are accepted even with the hardware gate off; never evidence of authorization. */
    public function ingestRun(string $token, array $data): array
    {
        $this->keys($data, ['run_uid', 'motor_state', 'duration_seconds', 'occurred_at']);
        $uid = $this->text($data['run_uid'] ?? null, 64, 'run_uid');
        if (!preg_match('/\A[A-Za-z0-9_-]+\z/D', $uid)) { throw new InvalidArgumentException('Invalid run_uid.'); }
        $state = $this->choice($data['motor_state'] ?? null, ['turned_normal', 'stopped', 'failed', 'interrupted'], 'motor_state');
        $duration = $this->integer($data['duration_seconds'] ?? null, 0, 86400, 'duration_seconds');
        $occurred = $this->text($data['occurred_at'] ?? null, 19, 'occurred_at');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $occurred, new DateTimeZone('Asia/Manila'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $occurred || $date > $this->clock()) {
            throw new InvalidArgumentException('occurred_at must be a valid Manila timestamp, not in the future.');
        }
        return $this->deviceOperation($token, function (array $device) use ($uid, $state, $duration, $occurred): array {
            if ($device['unit_id'] === null) { throw new RuntimeException('Device is not bound to a unit.', 409); }
            $this->findUnit((int) $device['tenant_id'], (int) $device['unit_id']);
            // Second-resolution clocks cannot disambiguate runs in the binding second.
            // Legacy devices have no proven cutoff and must be explicitly registered first.
            if ($device['rotation_bound_at'] === null || $occurred <= $device['rotation_bound_at']) {
                throw new RuntimeException('Run predates or overlaps the current binding; attribution rejected.', 409);
            }
            $facts = ['motor_state' => $state, 'duration_seconds' => $duration, 'occurred_at' => $occurred];
            $existing = $this->db->table('bmg_rotation_runs')->where('tenant_id', $device['tenant_id'])
                ->where('device_id', $device['id'])->where('run_uid', $uid)->get()->getRowArray();
            if ($existing !== null) {
                if ((int) $existing['unit_id'] !== (int) $device['unit_id']) {
                    throw new RuntimeException('run_uid belongs to a previous binding.', 409);
                }
                foreach ($facts as $key => $value) {
                    if ((string) $existing[$key] !== (string) $value) { throw new RuntimeException('run_uid was already used with different facts.', 409); }
                }
                return ['status' => 'acknowledged', 'run_uid' => $uid];
            }
            $this->db->table('bmg_rotation_runs')->insert($facts + ['tenant_id' => (int) $device['tenant_id'],
                'device_id' => (int) $device['id'], 'unit_id' => (int) $device['unit_id'], 'run_uid' => $uid, 'created_at' => $this->now()]);
            if (in_array($state, ['failed', 'interrupted'], true)) {
                WorkflowNotifications::record($this->db, 'bmg_run_' . $state, 'facilities', (int) $this->db->insertID(), 'reported');
            }
            return ['status' => 'created', 'run_uid' => $uid];
        });
    }

    public function pendingCommands(string $token): array
    {
        return $this->deviceOperation($token, function (array $device): array {
            $tenant = (int) $device['tenant_id'];
            $this->revalidateCommands($device);
            $this->expireCommands($tenant, (int) $device['id']);

            $rows = $this->db->table('bmg_rotation_commands')->select('id AS command_id, command, duration_seconds, pwm, expires_at')
                ->where('tenant_id', $tenant)->where('device_id', $device['id'])->where('unit_id', $device['unit_id'])
                ->where('status', 'pending')->orderBy('id')->limit(20)->get()->getResultArray();
            return ['commands' => $rows, 'server_time' => $this->now()];
        });
    }

    public function acknowledgeCommand(string $token, array $data): array
    {
        $this->keys($data, ['command_id', 'status', 'message']);
        $id = $this->integer($data['command_id'] ?? null, 1, PHP_INT_MAX, 'command_id');
        $status = $this->choice($data['status'] ?? null, ['completed', 'failed', 'rejected'], 'status');
        $message = isset($data['message']) ? $this->text($data['message'], 255, 'message') : null;
        return $this->deviceOperation($token, function (array $device) use ($id, $status, $message): array {
            $this->revalidateCommands($device);
            $this->expireCommands((int) $device['tenant_id'], (int) $device['id']);
            $row = $this->db->table('bmg_rotation_commands')->where('tenant_id', $device['tenant_id'])
                ->where('device_id', $device['id'])->where('id', $id)->get()->getRowArray();
            if ($row === null || $device['unit_id'] === null || (int) $row['unit_id'] !== (int) $device['unit_id']) {
                throw new RuntimeException('Command not found.', 404);
            }
            if ($row['status'] === 'acknowledged') {
                if ($row['ack_status'] !== $status || $row['ack_message'] !== $message) { throw new RuntimeException('Conflicting acknowledgement.', 409); }
            } elseif ($row['status'] !== 'pending') {
                throw new RuntimeException('Command is no longer pending.', 409);
            } else {
                $this->db->table('bmg_rotation_commands')->where('tenant_id', $device['tenant_id'])->where('id', $id)
                    ->update(['status' => 'acknowledged', 'ack_status' => $status, 'ack_message' => $message, 'acknowledged_at' => $this->now()]);
                                    WorkflowNotifications::record($this->db, 'bmg_command_' . $status, 'facilities', $id, 'terminal');
            }
            return ['status' => 'acknowledged', 'command_id' => $id];
        });
    }

    private function actor(string $permission): array
    {
        $request = service('request');
        if ($request->getHeaderLine('X-Device-Token') !== '' || $request->getHeaderLine('Authorization') !== '') {
            throw new RuntimeException('Human session authentication required; tokens cannot act as users.', 403);
        }
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) { throw new RuntimeException('Authentication required.', 401); }
        // In particular, cached session privileges and role names cannot grant hardware access.
        if (!PortalContext::isRolePermitted($actor['role'], PortalContext::PORTAL_BMG)
            || !in_array($permission, $actor['permissions'], true)) {
            throw new RuntimeException('Facilities permission required: ' . $permission, 403);
        }
        return $actor;
    }

    private function configurationActor(): array
    {
        $actor = $this->actor('facilities.configure');
        if (!in_array('facilities.manage', $actor['permissions'], true)) {
            throw new RuntimeException('Facilities permission required: facilities.manage', 403);
        }
        return $actor;
    }

    private function hardware(array $actor): void
    {
        if (!in_array('facilities.hardware.manage', $actor['permissions'], true)) { throw new RuntimeException('Hardware permission required.', 403); }
        if (!$this->hardwareEnabled()) { throw new RuntimeException('BMG hardware is disabled.', 409); }
    }

    private function hardwareEnabled(): bool
    {
        return in_array(env('BMG_HARDWARE_ENABLED', false), [true, 'true'], true);
    }

    private function findUnit(int $tenant, int $id): array
    {
        $unit = $this->db->table('bmg_units')->where('tenant_id', $tenant)->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if ($unit === null) { throw new RuntimeException('Unit not found.', 404); }
        return $unit;
    }

    private function boundDevice(int $tenant, int $unit): ?array
    {
        $rows = $this->db->table('bmg_devices')->where('tenant_id', $tenant)->where('unit_id', $unit)
            ->where('status', 'active')->where('revoked_at', null)->where('rotation_bound_at !=', null)->limit(2)->get()->getResultArray();
        return count($rows) === 1 ? $rows[0] : null;
    }

    private function detail(array $unit): array
    {
        $tenant = (int) $unit['tenant_id'];
        $id = (int) $unit['id'];
        $device = $this->boundDevice($tenant, $id);
        if ($device !== null) {
            $device = array_intersect_key($device, array_flip(['id', 'unit_id', 'chip_mac', 'reported_firmware_version', 'status',
                'last_seen_at', 'rtc_valid', 'applied_schedule_version', 'rotation_bound_at',
                'firmware_reported_at', 'rtc_reported_at', 'schedule_reported_at']));
            $device['firmware_version'] = $device['reported_firmware_version'];
            unset($device['reported_firmware_version']);
            $device['rtc_valid'] = $device['rtc_valid'] === null ? null : (bool) $device['rtc_valid'];
            $device['applied_schedule_version'] = $device['applied_schedule_version'] === null ? null : (int) $device['applied_schedule_version'];
        }
        return ['unit' => $unit, 'schedule' => $this->scheduleView($tenant, $id), 'device' => $device,
            'hardware_enabled' => $this->hardwareEnabled(),
            'runs' => $this->db->table('bmg_rotation_runs')->where('tenant_id', $tenant)->where('unit_id', $id)->orderBy('id', 'DESC')->limit(20)->get()->getResultArray(),
            'commands' => $this->db->table('bmg_rotation_commands')->where('tenant_id', $tenant)->where('unit_id', $id)->orderBy('id', 'DESC')->limit(20)->get()->getResultArray()];
    }

    private function defaultSchedule(): array
    {
        return ['version' => 0, 'enabled' => false, 'timezone' => 'Asia/Manila', 'times' => [],
            'duration_seconds' => 60, 'pwm' => null, 'direction' => 'forward'];
    }

    private function scheduleView(int $tenant, int $unit): array
    {
        $row = $this->db->table('bmg_rotation_schedules')->where('tenant_id', $tenant)->where('unit_id', $unit)->get()->getRowArray();
        if ($row === null) { return $this->defaultSchedule(); }
        return ['version' => (int) $row['version'], 'enabled' => (bool) $row['enabled'], 'timezone' => 'Asia/Manila',
            'times' => json_decode($row['times_json'], true, 16, JSON_THROW_ON_ERROR),
            'duration_seconds' => (int) $row['duration_seconds'], 'pwm' => $row['pwm'] === null ? null : (int) $row['pwm'], 'direction' => 'forward'];
    }

    private function persistSchedule(int $tenant, int $unit, int $actor, bool $enabled, array $times, int $duration, ?int $pwm): void
    {
        $old = $this->db->table('bmg_rotation_schedules')->where('tenant_id', $tenant)->where('unit_id', $unit)->get()->getRowArray();
        $values = ['enabled' => $enabled, 'times_json' => json_encode($times, JSON_THROW_ON_ERROR), 'duration_seconds' => $duration,
            'pwm' => $pwm, 'version' => $old === null ? 1 : (int) $old['version'] + 1,
            'updated_by_user_id' => $actor, 'updated_at' => $this->now()];
        if ($old === null) {
            $this->db->table('bmg_rotation_schedules')->insert($values + ['tenant_id' => $tenant, 'unit_id' => $unit, 'created_at' => $this->now()]);
        } else {
            $this->db->table('bmg_rotation_schedules')->where('tenant_id', $tenant)->where('id', $old['id'])->update($values);
        }
    }

    private function disable(int $tenant, int $unit, int $actor): void
    {
        $old = $this->scheduleView($tenant, $unit);
        $this->persistSchedule($tenant, $unit, $actor, false, $old['times'], $old['duration_seconds'], null);
        $this->cancelCommands($tenant, $unit, $actor);
    }

    private function cancelCommands(int $tenant, int $unit, ?int $actor = null): void
    {
        $commands = $this->db->table('bmg_rotation_commands')->select('id, tenant_id, created_by_user_id')
            ->where('tenant_id', $tenant)->where('unit_id', $unit)->where('status', 'pending')->get()->getResultArray();
        foreach ($commands as $command) {
            $this->terminalCommand($command, 'cancelled', $actor);
        }
    }

    private function terminalCommand(array $command, string $status, ?int $actor = null): void
    {
        $this->db->table('bmg_rotation_commands')->where('tenant_id', $command['tenant_id'])->where('id', $command['id'])
            ->where('status', 'pending')->update(['status' => $status]);
        if ($this->db->affectedRows() === 1) {
            WorkflowNotifications::record($this->db, 'bmg_command_' . $status, 'facilities', (int) $command['id'], 'terminal', $actor);
        }
    }

    /** Recheck persisted intent at delivery/ack time, including changes outside this service. */
    private function revalidateCommands(array $device): void
    {
        $tenant = (int) $device['tenant_id'];
        $unit = $device['unit_id'] === null ? null : $this->db->table('bmg_units')
            ->where('tenant_id', $tenant)->where('id', $device['unit_id'])->where('deleted_at', null)->get()->getRowArray();
        $bound = $unit === null ? null : $this->boundDevice($tenant, (int) $unit['id']);
        $pending = $this->db->table('bmg_rotation_commands')->where('tenant_id', $tenant)
            ->where('device_id', $device['id'])->where('status', 'pending')->get()->getResultArray();
        foreach ($pending as $command) {
            if (!$this->hardwareEnabled() || $bound === null || (int) $bound['id'] !== (int) $device['id']
                || (int) $command['unit_id'] !== (int) $unit['id']
                || $command['created_at'] < $device['rotation_bound_at']
                || ($command['command'] === 'run_now' && $unit['status'] !== 'active')) {
                $this->terminalCommand($command, 'cancelled');
            }
        }
    }

    private function expireCommands(int $tenant, int $device): void
    {
        $commands = $this->db->table('bmg_rotation_commands')->select('id, tenant_id, created_by_user_id')
            ->where('tenant_id', $tenant)->where('device_id', $device)
            ->where('status', 'pending')->where('expires_at <=', $this->now())->get()->getResultArray();
        foreach ($commands as $command) {
            $this->terminalCommand($command, 'expired');
        }
    }

    private function authenticate(string $token): array
    {
        if (strlen($token) < 16 || strlen($token) > 256 || preg_match('/\s/', $token)) { throw new RuntimeException('Unauthorized device token.', 401); }
        // Device credentials select their own tenant; never use a human session/default tenant.
        $device = $this->db->table('bmg_devices')->where('device_token_hash', hash('sha256', $token))
            ->where('status', 'active')->where('revoked_at', null)->get()->getRowArray();
        if ($device === null) { throw new RuntimeException('Unauthorized device token.', 401); }
        return $device;
    }

    private function deviceOperation(string $token, callable $operation): array
    {
        $device = $this->authenticate($token);
        return $this->locked((int) $device['tenant_id'], fn (): array => $operation($this->authenticate($token)));
    }

    /** Tenant-row mutex serializes binding, publication, ingestion, and command state changes.
     * All service writes take this lock first, avoiding unit/device lock-order inversions.
     */
    private function locked(int $tenant, callable $operation): array
    {
        if (!$this->db->transBegin()) { throw new RuntimeException('Rotation transaction unavailable.', 503); }
        try {
            $table = $this->db->protectIdentifiers($this->db->prefixTable('tenants'));
            $lock = $this->db->DBDriver === 'SQLite3' ? '' : ' FOR UPDATE';
            $row = $this->db->query('SELECT id FROM ' . $table . ' WHERE id = ? AND is_active = 1 AND deleted_at IS NULL' . $lock, [$tenant])->getRowArray();
            if ($row === null) { throw new RuntimeException('Tenant unavailable.', 401); }
            $result = $operation();
            if (!$this->db->transStatus()) { throw new RuntimeException('Rotation operation could not be persisted.', 503); }
            if (!$this->db->transCommit()) { throw new RuntimeException('Rotation transaction could not be committed.', 503); }
            return $result;
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    /** Check inside the tenant mutex, before any edit can consume the current version. */
    private function expectVersion(int $tenant, int $unit, mixed $expected): void
    {
        $version = $this->integer($expected, 0, 2147483646, 'expected_version');
        if ($version !== $this->scheduleView($tenant, $unit)['version']) {
            throw new RuntimeException('Schedule version changed; reload before retrying.', 409);
        }
    }

    private function audit(array $actor, string $entity, int $id, string $action, array $payload): void
    {
        try {
            $event = (new AuditService(new AuditEventModel($this->db)))->log(
                'bmg_rotation.' . $action, $entity, (string) $id, $action, $payload,
                (int) $actor['id'], $actor['role']
            );
            if (empty($event['id'])) { throw new RuntimeException('Audit event missing.'); }
        } catch (Throwable $error) {
            throw new RuntimeException('Rotation audit could not be persisted.', 503, $error);
        }
    }

    private function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) { throw new InvalidArgumentException('Unsupported input fields.'); }
    }

    private function text(mixed $value, int $max, string $name): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8')
                    || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException($name . ' must be nonempty text of at most ' . $max . ' bytes.');
        }
        return trim($value);
    }

    /** Exact DECIMAL(8,2), without floating point conversion or implicit rounding. */
    private function capacity(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/\A[0-9]{1,6}(?:\.[0-9]{1,2})?\z/D', (string) $value)) {
            throw new InvalidArgumentException('drum_capacity_kg must be a fixed decimal from 0.01 to 999999.99.');
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
        $capacity = (string) (int) $whole . '.' . str_pad($fraction, 2, '0');
        if ($capacity === '0.00') {
            throw new InvalidArgumentException('drum_capacity_kg must be greater than zero.');
        }
        return $capacity;
    }

    private function integer(mixed $value, int $min, int $max, string $name): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
            throw new InvalidArgumentException($name . ' must be an integer between ' . $min . ' and ' . $max . '.');
        }
        return (int) $value;
    }

    private function boolean(mixed $value, string $name): bool
    {
        if (in_array($value, [true, 1, '1'], true)) { return true; }
        if (in_array($value, [false, 0, '0'], true)) { return false; }
        throw new InvalidArgumentException($name . ' must be a boolean.');
    }

    private function choice(mixed $value, array $choices, string $name): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) { throw new InvalidArgumentException('Invalid ' . $name . '.'); }
        return $value;
    }

    private function clock(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    }

    private function now(): string
    {
        return $this->clock()->format('Y-m-d H:i:s');
    }
}
