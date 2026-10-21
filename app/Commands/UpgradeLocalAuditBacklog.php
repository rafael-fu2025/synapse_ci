<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Audit\AuditHash;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DomainException;
use Throwable;

/** Narrow local cutover for audited workflows; read-only unless --apply is explicit. */
final class UpgradeLocalAuditBacklog extends BaseCommand
{
    protected $group = 'Synapse';
    protected $name = 'synapse:audit-backlog-upgrade';
    protected $description = 'Inspect or explicitly back up and apply the pending guidance, BMG, queue, and employee-grant migrations.';
    protected $usage = 'synapse:audit-backlog-upgrade [--apply]';
    protected $options = ['--apply' => 'Authorize the local development migration cutover. Stop/drain application workers first.'];

    private const TARGETS = [
        '2026-10-19-000004' => 'HardenGuidanceNumbering',
        '2026-10-19-000007' => 'AddBmgBatchNotes',
        '2026-10-19-000008' => 'AddQueueNoShowStatus',
        '2026-10-19-000009' => 'RetireEmployeeReferralGrant',
    ];

    public function run(array $params)
    {
        $flags = [WRITEPATH . 'scheduling-maintenance.flag', WRITEPATH . 'bmg-maintenance.flag'];
        $ownedFlags = [];
        $ddlStarted = false;
        $db = null;
        try {
            $options = CLI::getOptions();
            foreach ($params as $key => $value) {
                if ($key !== 'apply' || $value !== null) throw new DomainException('Usage: ' . $this->usage);
                $options[$key] = $value;
            }
            if (array_diff(array_keys($options), ['apply']) !== [] || (isset($options['apply']) && $options['apply'] !== null)) {
                throw new DomainException('Usage: ' . $this->usage);
            }
            if (ENVIRONMENT !== 'development') throw new DomainException('This command is restricted to development.');
            $db = Database::connect('default');
            if ($db->DBDriver !== 'MySQLi' || !in_array($db->hostname, ['localhost', '127.0.0.1', '::1'], true)
                || $db->database !== 'humus_db') {
                throw new DomainException('Expected local development MySQL humus_db.');
            }
            foreach ($flags as $flag) {
                if (is_file($flag)) throw new DomainException('A migration maintenance flag already exists. Investigate it before retrying.');
            }

            $migrationTable = config('Migrations')->table;
            if (!$db->tableExists($migrationTable)) throw new DomainException('Migration history table is missing.');
            $pending = [];
            foreach (self::TARGETS as $version => $name) {
                $rows = $db->table($migrationTable)->where(['namespace' => 'App', 'version' => $version])->get()->getResultArray();
                if (count($rows) > 1) throw new DomainException('Duplicate migration history for ' . $version . '.');
                if ($rows === []) $pending[$version] = $name;
            }
            CLI::write('Pending targeted migrations: ' . ($pending === [] ? 'none' : implode(', ', array_keys($pending))) . '.');
            if (!array_key_exists('apply', $options) || $pending === []) {
                CLI::write('Read-only check complete. No schema, data, permissions, or maintenance flags changed.');
                return EXIT_SUCCESS;
            }

            if (!$db->fieldExists('hash_version', 'audit_events')) throw new DomainException('Apply audit-ledger hardening first.');
            $this->verifyAudit($db);
            $snapshot = $this->snapshot($db);
            $dir = WRITEPATH . 'audit-backlog-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
            if (!mkdir($dir, 0700)) throw new DomainException('Unable to create a private audit-backlog backup directory.');
            $backupPath = $dir . '/snapshot.json';
            $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (file_put_contents($backupPath, $encoded, LOCK_EX) !== strlen($encoded)) throw new DomainException('Backup write failed.');
            chmod($backupPath, 0600);
            $readback = file_get_contents($backupPath);
            if (!is_string($readback) || hash('sha256', $readback) !== hash('sha256', $encoded)) throw new DomainException('Backup read-back verification failed.');
            file_put_contents($dir . '/snapshot.sha256', hash('sha256', $encoded) . "\n", LOCK_EX);

            // Requires the operator to stop/drain workers first: flags block new HTTP requests,
            // but cannot cancel a request which entered before the flag was created.
            foreach ($flags as $flag) {
                $handle = @fopen($flag, 'x');
                if ($handle === false) throw new DomainException('Unable to acquire migration maintenance flags.');
                fwrite($handle, $backupPath . "\n");
                fclose($handle);
                $ownedFlags[] = $flag;
            }
            $this->drainTenantWriters($db);
            $this->assertBackupStillMatches($db, $snapshot);
            CLI::write('Verified private backup: ' . $backupPath);

            foreach ($pending as $version => $name) {
                $ddlStarted = true;
                $runnerConfig = clone config('Migrations');
                $runnerConfig->lock = true;
                $runner = new class($runnerConfig, 'default', $version, $name) extends \CodeIgniter\Database\MigrationRunner {
                    public function __construct($config, string $group, private string $targetVersion, private string $targetName)
                    {
                        parent::__construct($config, $group);
                    }

                    public function findMigrations(): array
                    {
                        return array_filter(parent::findMigrations(), fn (object $migration): bool =>
                            $migration->namespace === 'App' && $migration->version === $this->targetVersion
                            && $migration->name === $this->targetName);
                    }

                    public function regress(int $targetBatch = 0, ?string $group = null)
                    {
                        throw new DomainException('Automatic migration rollback is prohibited; inspect the maintenance state.');
                    }
                };
                if (!$runner->setNamespace('App')->latest('default')) throw new DomainException('Target migration failed: ' . $version);
                $count = $db->table($migrationTable)->where(['namespace' => 'App', 'version' => $version])->countAllResults();
                if ($count !== 1) throw new DomainException('Target migration history was not recorded exactly once: ' . $version);
                $this->verifyInstalled($db, $version);
            }

            $this->assertPreserved($db, $snapshot);
            $this->verifyAudit($db);
            foreach ($ownedFlags as $flag) {
                if (!unlink($flag)) throw new DomainException('Upgrade verified but maintenance flag could not be removed.');
            }
            $ownedFlags = [];
            $ddlStarted = false;
            CLI::write('Targeted migrations applied and verified; traffic may resume.');
            CLI::write('No patient, case, note, survey, or audit history was rewritten.');
            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            if ($db !== null && $db->transDepth > 0) $db->transRollback();
            if (!$ddlStarted) foreach ($ownedFlags as $flag) @unlink($flag);
            CLI::error($e instanceof DomainException ? $e->getMessage() : 'Audit-backlog upgrade failed. Inspect the verified backup and migration state.');
            if ($ddlStarted) CLI::error('Maintenance flags remain active. Keep workers stopped until partial DDL and data preservation are verified.');
            return EXIT_ERROR;
        }
    }

    private function snapshot($db): array
    {
        $tables = ['counselling_cases', 'counselling_sessions', 'counselling_notes', 'bmg_batches',
            'queue_entries', 'role_permissions', 'audit_events'];
        $snapshot = ['format' => 'synapse.local-audit-backlog.v1', 'schema' => $db->database];
        foreach ($tables as $table) {
            if (!$db->tableExists($table)) throw new DomainException('Required table is missing: ' . $table . '.');
            $key = match ($table) {
                'role_permissions' => 'role',
                default => 'id',
            };
            $builder = $db->table($table);
            $snapshot[$table] = $table === 'role_permissions'
                ? $builder->orderBy('role')->orderBy('permission_id')->get()->getResultArray()
                : $builder->orderBy($key)->get()->getResultArray();
        }
        $snapshot['referral_permission'] = $db->table('permissions')->where('code', 'referrals.create')->get()->getRowArray();
        return $snapshot;
    }

    private function drainTenantWriters($db): void
    {
        if (!$db->query('SET SESSION innodb_lock_wait_timeout=15') || !$db->transBegin()) {
            throw new DomainException('Unable to begin the writer-drain check.');
        }
        $tenantTable = $db->protectIdentifiers($db->prefixTable('tenants'));
        $result = $db->query("SELECT id FROM {$tenantTable} ORDER BY id FOR UPDATE");
        if ($result === false || !$db->transStatus() || !$db->transCommit()) {
            throw new DomainException('Unable to acquire/release the tenant writer drain.');
        }
    }

    private function assertBackupStillMatches($db, array $snapshot): void
    {
        foreach (['counselling_cases', 'counselling_sessions', 'counselling_notes', 'bmg_batches', 'queue_entries', 'role_permissions', 'audit_events'] as $table) {
            $builder = $db->table($table);
            $current = $table === 'role_permissions'
                ? $builder->orderBy('role')->orderBy('permission_id')->get()->getResultArray()
                : $builder->orderBy('id')->get()->getResultArray();
            if ($current !== $snapshot[$table]) throw new DomainException('Data changed after backup; stop and take a new reviewed snapshot.');
        }
    }

    private function assertPreserved($db, array $snapshot): void
    {
        foreach (['counselling_cases', 'counselling_sessions', 'counselling_notes', 'queue_entries', 'audit_events'] as $table) {
            $current = $db->table($table)->orderBy('id')->get()->getResultArray();
            if ($current !== $snapshot[$table]) throw new DomainException('Historical rows changed during migration: ' . $table . '.');
        }
        $batches = $db->table('bmg_batches')->orderBy('id')->get()->getResultArray();
        $hasNotesInBackup = isset($snapshot['bmg_batches'][0]) && array_key_exists('notes', $snapshot['bmg_batches'][0]);
        if (!$hasNotesInBackup) {
            foreach ($batches as &$row) unset($row['notes']);
            unset($row);
        }
        if ($batches !== $snapshot['bmg_batches']) throw new DomainException('BMG batch history changed during migration.');

        $beforePermissions = $snapshot['role_permissions'];
        $permissionId = (int) ($snapshot['referral_permission']['id'] ?? 0);
        $expected = array_values(array_filter($beforePermissions, static fn (array $row): bool =>
            !($row['role'] === 'employee' && (int) $row['permission_id'] === $permissionId)));
        $actual = $db->table('role_permissions')->orderBy('role')->orderBy('permission_id')->get()->getResultArray();
        usort($expected, static fn (array $a, array $b): int => [$a['role'], $a['permission_id']] <=> [$b['role'], $b['permission_id']]);
        if ($actual !== $expected) throw new DomainException('Unexpected role grant change during migration.');
    }

    private function verifyInstalled($db, string $version): void
    {
        if ($version === '2026-10-19-000004') {
            foreach ([['counselling_sessions', 'uniq_guidance_session_number', ['tenant_id', 'case_id', 'session_number']],
                ['counselling_notes', 'uniq_guidance_note_version', ['tenant_id', 'session_id', 'version']]] as [$table, $index, $columns]) {
                $rows = $db->query('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
                    [$db->prefixTable($table), $index])->getResultArray();
                if (array_column($rows, 'COLUMN_NAME') !== $columns) throw new DomainException('Guidance unique index verification failed: ' . $index . '.');
            }
        } elseif ($version === '2026-10-19-000007') {
            if (!$db->fieldExists('notes', 'bmg_batches')) throw new DomainException('BMG notes schema verification failed.');
        } elseif ($version === '2026-10-19-000008') {
            $row = $db->query('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
                [$db->prefixTable('queue_entries'), 'status'])->getRowArray();
            if (strtolower($row['COLUMN_TYPE'] ?? '') !== "enum('waiting','called','in_consultation','done','skipped','no_show')") {
                throw new DomainException('Queue no-show schema verification failed.');
            }
        } elseif ($version === '2026-10-19-000009') {
            $permission = $db->table('permissions')->select('id')->where('code', 'referrals.create')->get()->getRowArray();
            if ($permission !== null && $db->table('role_permissions')->where('role', 'employee')->where('permission_id', $permission['id'])->countAllResults() !== 0) {
                throw new DomainException('Employee referral grant retirement verification failed.');
            }
        }
    }

    private function verifyAudit($db): void
    {
        $byTenant = [];
        foreach ($db->table('audit_events')->orderBy('id')->get()->getResultArray() as $row) $byTenant[(int) $row['tenant_id']][] = $row;
        foreach ($byTenant as $tenant => $events) {
            if (!AuditHash::verify($events, $tenant)['valid']) throw new DomainException('Audit integrity check failed; no automatic repair is permitted.');
        }
    }
}
