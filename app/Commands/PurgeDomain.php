<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class PurgeDomain extends BaseCommand
{
    /**
     * FK-safe, leaf-first purge order per domain unit. A table is listed only
     * after every table that RESTRICT-deletes it (notifications block
     * appointments/referrals, dispensations block batches and the ledger,
     * stock_movements block inventory_operations, rotation runs block
     * devices/units). Master data — people, student/employee/health profiles,
     * clinic_medicines, survey_question_bank*, mis_* reference rows, users,
     * tenants, audit_events — is intentionally absent and can never be
     * purged here.
     *
     * An entry may carry a raw predicate for the self-referencing link of
     * the inventory ledger (e.g. reversal rows); those tables are purged in
     * two passes — referencing rows first — because InnoDB checks
     * self-referential RESTRICT keys immediately.
     */
    private const TABLE_GROUPS = [
        'notifications' => ['notifications', 'workflow_alert_states', 'workflow_events'],
        'clinic' => [
            'clinic_dispensations',
            'clinic_vitals',
            'clinic_treatments',
            ['stock_movements', 'original_movement_id IS NOT NULL'],
            ['inventory_operations', 'reversal_of IS NOT NULL'],
            'clinic_encounters',
            'clinic_medicine_batches',
        ],
        'guidance' => [
            'counselling_notes',
            'counselling_sessions',
            'guidance_followups',
            'counselling_cases',
            'referrals',
        ],
        'surveys' => ['survey_responses', 'surveys'],
        'scheduling' => ['queue_entries', 'appointments', 'daily_ticket_counters'],
        'bmg' => [
            'bmg_rotation_runs',
            'bmg_rotation_commands',
            'bmg_rotation_schedules',
            'bmg_device_runs',
            'bmg_devices',
            'bmg_process_logs',
            'bmg_batches',
            'bmg_units',
        ],
    ];

    protected $group = 'Synapse';
    protected $name = 'synapse:purge-domain';
    protected $description = 'Purge operational records for clinic, guidance, bmg and adjacent units in FK-safe order; MIS students/employees and master data are preserved.';
    protected $usage = 'synapse:purge-domain <units> [--tenant <positive-id>] [--force]';
    protected $arguments = [
        'units' => 'Comma-separated units: ' . 'notifications,clinic,guidance,surveys,scheduling,bmg',
    ];
    protected $options = [
        '--tenant' => 'Optional positive tenant ID; omit to purge every tenant.',
        '--force' => 'Execute the purge; without it the command is a read-only dry-run.',
    ];

    public function run(array $params)
    {
        try {
            if (! defined('ENVIRONMENT') || ENVIRONMENT !== 'development') {
                throw new DomainException('Domain purge requires the development environment.');
            }
            $options = CLI::getOptions();
            foreach (array_intersect_key($options, $params) as $key => $value) {
                if ($value !== $params[$key]) {
                    throw new InvalidArgumentException('Conflicting option definitions.');
                }
            }
            $options = array_replace($options, $params);

            $units = null;
            $tenant = null;
            $force = false;
            foreach ($options as $key => $value) {
                if (is_int($key)) {
                    if ($units !== null) {
                        throw new InvalidArgumentException('Pass exactly one units argument.');
                    }
                    $units = $value;
                    continue;
                }
                if ($key === 'tenant') {
                    if (! is_string($value) || ! preg_match('/\A[1-9][0-9]*\z/D', $value)) {
                        throw new InvalidArgumentException('--tenant requires a positive integer ID.');
                    }
                    $tenant = (int) $value;
                    continue;
                }
                if ($key === 'force') {
                    if ($value !== null) {
                        throw new InvalidArgumentException('--force takes no value.');
                    }
                    $force = true;
                    continue;
                }
                throw new InvalidArgumentException('Unknown option --' . $key . '.');
            }

            $tables = $this->resolveTables($units);
            $db = db_connect();
            $report = [];
            if ($force) {
                $this->assertNoBlockingTriggers($db, array_column($tables, 0));
                $db->transBegin();
                try {
                    foreach ($tables as [$table, $selfColumn]) {
                        $report[$table] = $this->purgeTable($db, $table, $selfColumn, $tenant);
                    }
                    $db->transCommit();
                } catch (Throwable $e) {
                    $db->transRollback();
                    throw $e;
                }
                CLI::write('Purged ' . count($report) . ' tables' . ($tenant !== null ? ' for tenant ' . $tenant : ' for all tenants') . '; transaction committed.', 'green');
            } else {
                foreach ($tables as [$table, $selfColumn]) {
                    $report[$table] = $this->countTable($db, $table, $tenant);
                }
                CLI::write('Dry-run report (nothing deleted); re-run with --force to purge.', 'yellow');
            }
            foreach ($report as $table => $count) {
                CLI::write($table . '=' . $count);
            }
            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::write('ERROR: ' . $e->getMessage(), 'red');
            return EXIT_ERROR;
        }
    }

    /**
     * Normalize the units argument into the flat FK-safe delete order from
     * TABLE_GROUPS, regardless of the order the units were requested in.
     *
     * @return list<array{string, string|null}>
     */
    private function resolveTables(mixed $units): array
    {
        if (! is_string($units) || trim($units) === '') {
            throw new InvalidArgumentException('Pass the units argument, e.g. "clinic,guidance,bmg".');
        }
        $selected = [];
        foreach (explode(',', $units) as $unit) {
            $unit = strtolower(trim($unit));
            if ($unit === '') {
                throw new InvalidArgumentException('Empty unit name in "' . $units . '".');
            }
            if (! isset(self::TABLE_GROUPS[$unit])) {
                throw new InvalidArgumentException('Unknown unit "' . $unit . '". Allowed: ' . implode(',', array_keys(self::TABLE_GROUPS)) . '.');
            }
            $selected[$unit] = true;
        }
        $tables = [];
        foreach (self::TABLE_GROUPS as $unit => $groupTables) {
            if (! isset($selected[$unit])) {
                continue;
            }
            foreach ($groupTables as $entry) {
                $tables[] = is_array($entry) ? [$entry[0], $entry[1]] : [$entry, null];
            }
        }
        return $tables;
    }

    private function purgeTable(ConnectionInterface $db, string $table, ?string $selfPredicate, ?int $tenant): int
    {
        $deleted = 0;
        if ($selfPredicate !== null) {
            // Pass 1: rows carrying the self-referencing link (reversals).
            $builder = $db->table($table)
                ->where($selfPredicate, null, false)
                ->where('tenant_id >', 0);
            if ($tenant !== null) {
                $builder->where('tenant_id', $tenant);
            }
            $deleted += $this->executeDelete($db, $table, $builder);
        }
        $builder = $db->table($table)->where('tenant_id >', 0);
        if ($tenant !== null) {
            $builder->where('tenant_id', $tenant);
        }
        return $deleted + $this->executeDelete($db, $table, $builder);
    }

    /**
     * With DBDebug off a failed DELETE returns false instead of throwing;
     * surface it, otherwise the purge would half-commit silently.
     */
    private function executeDelete(ConnectionInterface $db, string $table, object $builder): int
    {
        $result = $builder->delete();
        if ($result === false) {
            $error = $db->error();
            throw new DatabaseException('Purge DELETE failed on "' . $table . '": '
                . ($error['message'] ?? 'unknown database error'));
        }
        return $db->affectedRows();
    }

    /**
     * Append-only ledger/audit triggers (BEFORE DELETE ... SIGNAL) make some
     * tables impossible to purge; fail up front instead of no-op-ing.
     */
    private function assertNoBlockingTriggers(ConnectionInterface $db, array $tables): void
    {
        $blockers = [];
        foreach (array_unique($tables) as $table) {
            $rows = $db->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? AND EVENT_MANIPULATION = ?', [$table, 'DELETE'])->getResultArray();
            foreach ($rows as $row) {
                $blockers[] = $row['EVENT_OBJECT_TABLE'] . ' (' . $row['TRIGGER_NAME'] . ')';
            }
        }
        if ($blockers !== []) {
            throw new DomainException('Append-only triggers block DELETE on: ' . implode(', ', $blockers)
                . '. Drop them for the purge window and recreate them afterwards (see the inventory ledger migration).');
        }
    }

    private function countTable(ConnectionInterface $db, string $table, ?int $tenant): int
    {
        $builder = $db->table($table);
        if ($tenant !== null) {
            $builder->where('tenant_id', $tenant);
        }
        return $builder->countAllResults();
    }
}
