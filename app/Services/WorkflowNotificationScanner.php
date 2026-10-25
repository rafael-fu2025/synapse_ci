<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\WorkflowAlerts;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Finite current-tenant sweep. No delivery, command issuance, or domain-state mutation.
 * batchLimit bounds memory/query size, not total rows per invocation. Every source is
 * scanned through its captured ID ceiling; runtime grows with existing tenant data.
 * The command's --limit bounds dispatch only. There is no persistent scan cursor.
 *
 * Parent contract: WorkflowNotifications::record(db, kind, unit, subjectId, occurrence, null)
 * participates in our transaction, stores only identifiers, and dedupes its SHA-256 event_key.
 * Subject IDs belong to appointments, guidance_followups, clinic_medicines,
 * clinic_medicine_batches, bmg_devices, and bmg_rotation_commands respectively.
 * The parent owns the corresponding subject_kind mapping.
 * Episode occurrences are exactly episode_# (recoveries reuse the opening episode number).
 * Other occurrences: v{version}_{starts_at with T}; due_date; expiration_date; expires_at with T.
 * All state timestamps are UTC; existing scheduling/BMG source timestamps are Manila wall time.
 */
final class WorkflowNotificationScanner
{
    private BaseConnection $db;
    private WorkflowAlerts $config;
    private Closure $record;
    private DateTimeImmutable $utc;
    private DateTimeImmutable $local;
    private int $tenant;
    private array $counts = ['scanned' => 0, 'recorded' => 0, 'failed' => 0];

    public function __construct(
        ?BaseConnection $db = null,
        ?WorkflowAlerts $config = null,
        private ?DateTimeImmutable $now = null,
        ?Closure $record = null,
    ) {
        $this->db = $db ?? Database::connect();
        $this->config = $config ?? config(WorkflowAlerts::class);
        $this->record = $record ?? static function (BaseConnection $db, string $kind, string $unit, int $id, string $occurrence): void {
            WorkflowNotifications::record($db, $kind, $unit, $id, $occurrence, null);
        };
    }

    /** recorded counts successful record calls, not newly inserted (the helper returns void). */
    public function scan(): array
    {
        $this->counts = ['scanned' => 0, 'recorded' => 0, 'failed' => 0];
        if (!$this->config->enabled) return $this->counts;
        if ($this->db->transDepth !== 0) throw new RuntimeException('Scanner requires its own transactions.');
        foreach (['batchLimit', 'appointmentLeadHours', 'deviceOfflineMinutes', 'deviceFirstContactGraceMinutes', 'rtcReportMaxAgeMinutes'] as $key) {
            if ($this->config->$key < 1) throw new InvalidArgumentException('Invalid workflow alert configuration.');
        }
        if ($this->config->batchLimit > 1000 || $this->config->nearExpiryDays < 0) {
            throw new InvalidArgumentException('Invalid workflow alert configuration.');
        }
        $this->utc = ($this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $this->local = $this->utc->setTimezone(new DateTimeZone('Asia/Manila'));
        $this->tenant = CurrentTenant::id();
        if (!$this->eligible()) return $this->counts;

        $sources = [
            'appointments' => ['appointment_reminder'],
            'guidance_followups' => ['guidance_followup_due', 'guidance_followup_overdue'],
            'clinic_medicines' => ['inventory_low_stock'],
            'clinic_medicine_batches' => ['inventory_near_expiry', 'inventory_expired'],
            'bmg_devices' => ['bmg_device_offline', 'bmg_device_recovered', 'bmg_rtc_invalid', 'bmg_rtc_recovered'],
            'bmg_rotation_commands' => ['bmg_command_expired'],
        ];
        foreach ($sources as $table => $kinds) {
            if (array_filter($kinds, fn ($kind) => $this->enabled($kind)) === []) continue;
            // Capture a ceiling so concurrent inserts cannot turn this invocation into an endless loop.
            $ceiling = (int) ($this->db->table($table)->selectMax('id')->where('tenant_id', $this->tenant)->get()->getRow('id') ?? 0);
            $cursor = 0;
            do {
                $rows = $this->db->table($table)->select('id')->where('tenant_id', $this->tenant)
                    ->where('id >', $cursor)->where('id <=', $ceiling)->orderBy('id')->limit($this->config->batchLimit)->get()->getResultArray();
                foreach ($rows as $row) {
                    $cursor = (int) $row['id'];
                    $this->inspect($table, $cursor);
                }
            } while (count($rows) === $this->config->batchLimit && $cursor < $ceiling);
        }
        return $this->counts;
    }

    private function enabled(string $kind): bool
    {
        return ($this->config->kinds[$kind] ?? false) === true;
    }

    private function eligible(bool $lock = false): bool
    {
        $sql = 'SELECT id FROM ' . $this->table('tenants') . ' WHERE id=? AND is_active=1 AND deleted_at IS NULL';
        return $this->db->query($sql . $this->lock($lock), [$this->tenant])->getRowArray() !== null;
    }

    private function table(string $table): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($table));
    }

    private function lock(bool $lock = true): string
    {
        return $lock && $this->db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : '';
    }

    private function inspect(string $table, int $id): void
    {
        $recorded = $this->counts['recorded'];
        if (!$this->db->transBegin()) throw new RuntimeException('Unable to start alert transaction.');
        try {
            // Same tenant lock order as domain writers; serializes episode creation across scanners.
            if ($this->eligible(true)) {
                $row = $this->db->query('SELECT * FROM ' . $this->table($table) . ' WHERE tenant_id=? AND id=?' . $this->lock(), [$this->tenant, $id])->getRowArray();
                if ($row !== null) {
                    $this->counts['scanned']++;
                    match ($table) {
                        'appointments' => $this->appointment($row),
                        'guidance_followups' => $this->followup($row),
                        'clinic_medicines' => $this->medicine($row),
                        'clinic_medicine_batches' => $this->batch($row),
                        'bmg_devices' => $this->device($row),
                        'bmg_rotation_commands' => $this->command($row),
                    };
                }
            }
            if (!$this->db->transStatus() || !$this->db->transCommit()) throw new RuntimeException('Unable to commit alert transaction.');
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            $this->counts['recorded'] = $recorded;
            $this->counts['failed']++;
        }
    }

    private function emit(string $kind, string $unit, int $id, string $occurrence): void
    {
        if (!$this->enabled($kind)) return;
        ($this->record)($this->db, $kind, $unit, $id, $occurrence);
        $this->counts['recorded']++;
    }

    private function appointment(array $row): void
    {
        $start = $row['starts_at'];
        if (!$this->patientExists((int) $row['person_id'])) return;
        if ($row['deleted_at'] === null && in_array($row['status'], ['confirmed', 'scheduled'], true)
            && $start >= $this->local->format('Y-m-d H:i:s')
            && $start <= $this->local->modify('+' . $this->config->appointmentLeadHours . ' hours')->format('Y-m-d H:i:s')) {
            $this->emit('appointment_reminder', $row['unit'], (int) $row['id'], 'v' . $row['version'] . '_' . str_replace(' ', 'T', $start));
        }
    }

    private function followup(array $row): void
    {
        if ($row['status'] !== 'pending' || $row['due_date'] > $this->local->format('Y-m-d')) return;
        $case = $this->db->table('counselling_cases')->select('person_id')->where('tenant_id', $this->tenant)
            ->where('id', $row['case_id'])->where('deleted_at', null)->where('status !=', 'closed')->get()->getRowArray();
        if ($case === null || !$this->patientExists((int) $case['person_id'])) return;
        $kind = $row['due_date'] === $this->local->format('Y-m-d') ? 'guidance_followup_due' : 'guidance_followup_overdue';
        $this->emit($kind, 'guidance', (int) $row['id'], $row['due_date']);
    }

    private function patientExists(int $id): bool
    {
        return $this->db->table('people')->where('tenant_id', $this->tenant)->where('id', $id)
            ->where('deleted_at', null)->countAllResults() === 1;
    }

    private function medicine(array $row): void
    {
        $stock = $this->db->table('clinic_medicine_batches')->selectSum('remaining_quantity', 'stock')
            ->where('tenant_id', $this->tenant)->where('medicine_id', $row['id'])->where('status', 'active')
            ->where('expiration_date >=', $this->local->format('Y-m-d'))->get()->getRow('stock');
        $this->episode('inventory_low_stock', 'clinic', (int) $row['id'], (int) $row['is_active'] === 1
            && (int) $stock <= (int) $row['minimum_stock_level']);
    }

    private function batch(array $row): void
    {
        $medicine = $this->db->table('clinic_medicines')->where('tenant_id', $this->tenant)->where('id', $row['medicine_id'])->get()->getRowArray();
        if ((int) ($medicine['is_active'] ?? 0) !== 1 || $row['status'] !== 'active' || (int) $row['remaining_quantity'] <= 0) return;
        $date = $row['expiration_date'];
        if ($date < $this->local->format('Y-m-d')) {
            $this->emit('inventory_expired', 'clinic', (int) $row['id'], $date);
        } elseif ($date <= $this->local->modify('+' . $this->config->nearExpiryDays . ' days')->format('Y-m-d')) {
            $this->emit('inventory_near_expiry', 'clinic', (int) $row['id'], $date);
        }
    }

    private function device(array $row): void
    {
        $unit = $this->db->table('bmg_units')->where('tenant_id', $this->tenant)->where('id', $row['unit_id'])->get()->getRowArray();
        $boundCount = $this->db->table('bmg_devices')->where('tenant_id', $this->tenant)->where('unit_id', $row['unit_id'])
            ->where('status', 'active')->where('revoked_at', null)->where('rotation_bound_at !=', null)->countAllResults();
        $eligible = $row['status'] === 'active' && $row['revoked_at'] === null && $row['rotation_bound_at'] !== null
            && ($unit['status'] ?? null) === 'active' && ($unit['deleted_at'] ?? null) === null && $boundCount === 1;
        $origin = max($row['created_at'], $row['rotation_bound_at'] ?? $row['created_at']);
        // A cached contact from a previous binding is not first contact for this binding.
        $seen = $row['last_seen_at'] !== null && $row['last_seen_at'] >= $origin ? $row['last_seen_at'] : null;
        $offline = $eligible && ($seen === null
            ? $origin < $this->local->modify('-' . $this->config->deviceFirstContactGraceMinutes . ' minutes')->format('Y-m-d H:i:s')
            : $seen < $this->local->modify('-' . $this->config->deviceOfflineMinutes . ' minutes')->format('Y-m-d H:i:s'));
        $this->episode('bmg_device_offline', 'facilities', (int) $row['id'], $offline, 'bmg_device_recovered',
            $eligible && $seen !== null && $seen <= $this->local->format('Y-m-d H:i:s') ? $this->sourceUtc($seen) : null,
            $row['rotation_bound_at'] === null ? null : $this->sourceUtc($row['rotation_bound_at']));
        // NULL, future, pre-binding, and aged RTC values are unknown, not invalid or recovered.
        $report = $row['rtc_reported_at'];
        $rtcKnown = $eligible && $report !== null && $report >= $origin
            && $report >= $this->local->modify('-' . $this->config->rtcReportMaxAgeMinutes . ' minutes')->format('Y-m-d H:i:s')
            && $seen !== null && $report <= $seen && $seen <= $this->local->format('Y-m-d H:i:s');
        $this->episode('bmg_rtc_invalid', 'facilities', (int) $row['id'], $rtcKnown && $row['rtc_valid'] !== null && (int) $row['rtc_valid'] === 0,
            'bmg_rtc_recovered', $rtcKnown && $row['rtc_valid'] !== null && (int) $row['rtc_valid'] === 1
                ? $this->sourceUtc($report) : null,
            $row['rotation_bound_at'] === null ? null : $this->sourceUtc($row['rotation_bound_at']),
            $rtcKnown ? $this->sourceUtc($report) : null);
    }

    private function command(array $row): void
    {
        if ($row['status'] === 'pending' && $row['expires_at'] < $this->local->format('Y-m-d H:i:s')) {
            $this->emit('bmg_command_expired', 'facilities', (int) $row['id'], str_replace(' ', 'T', $row['expires_at']));
        }
    }

    private function sourceUtc(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function episode(string $kind, string $unit, int $id, bool $active, ?string $recovery = null, ?string $contact = null, ?string $bound = null, ?string $activationReport = null): void
    {
        // Do not silently open an episode when its opening event is disabled.
        if (!$this->enabled($kind)) return;
        $key = ['tenant_id' => $this->tenant, 'event_kind' => $kind, 'subject_id' => $id];
        $state = $this->db->table('workflow_alert_states')->where($key)->get()->getRowArray();
        $timestamp = $this->utc->format('Y-m-d H:i:s');
        $wasActive = (bool) ($state['active'] ?? false);
        if ($wasActive && $bound !== null && $bound > $state['started_at']) {
            // Rebinding is not evidence that the previous binding recovered.
            if (!$this->db->table('workflow_alert_states')->where($key)->update(['active' => 0, 'resolved_at' => $timestamp])) {
                throw new RuntimeException('Unable to retire rebound alert episode.');
            }
            $wasActive = false;
        }
        if ($active && !$wasActive) {
            if ($activationReport !== null && $state !== null && $state['resolved_at'] !== null
                && $activationReport <= $state['resolved_at']) return;
            $number = (int) ($state['episode_number'] ?? 0) + 1;
            $values = ['active' => 1, 'episode_number' => $number, 'started_at' => $timestamp, 'resolved_at' => null];
            $ok = $state === null ? $this->db->table('workflow_alert_states')->insert($key + $values)
                : $this->db->table('workflow_alert_states')->where($key)->update($values);
            if (!$ok) throw new RuntimeException('Unable to persist alert episode.');
            $this->emit($kind, $unit, $id, 'episode_' . $number);
        } elseif (!$active && $wasActive) {
            if ($recovery !== null && $contact !== null && $contact > $state['started_at'] && $contact <= $timestamp) {
                $this->emit($recovery, $unit, $id, 'episode_' . $state['episode_number']);
            } elseif ($recovery !== null) {
                // Unknown or stale reports must not close an episode and manufacture a rearm.
                return;
            }
            if (!$this->db->table('workflow_alert_states')->where($key)->update(['active' => 0, 'resolved_at' => $timestamp])) {
                throw new RuntimeException('Unable to resolve alert episode.');
            }
        }
    }
}
