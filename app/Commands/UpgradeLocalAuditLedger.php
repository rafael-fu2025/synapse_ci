<?php

declare(strict_types=1);

namespace App\Commands;

use App\Database\Migrations\HardenAuditLedger;
use App\Services\Audit\AuditHash;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DomainException;
use Throwable;

/** Deliberately restricted local cutover; never an unattended production migrator. */
final class UpgradeLocalAuditLedger extends BaseCommand
{
    protected $group = 'Synapse';
    protected $name = 'synapse:audit-upgrade';
    protected $description = 'Inspect the local ledger, or explicitly back up, rehearse and apply only audit hardening.';
    protected $usage = 'synapse:audit-upgrade [--apply]';
    protected $options = ['--apply' => 'Authorize the local audit-only cutover; default is read-only.'];

    public function run(array $params)
    {
        $flag = WRITEPATH . 'audit-maintenance.flag';
        $ownsFlag = false;
        $ddlStarted = false;
        $scratch = null;
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
                throw new DomainException('Expected the local development MySQL humus_db database.');
            }
            if (is_file($flag)) throw new DomainException('Maintenance is already active. Investigate the previous cutover before retrying.');
            $table = $db->protectIdentifiers($db->prefixTable('audit_events'));
            $rows = $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->getResultArray();
            $this->verify($rows);
            $version = '2026-10-19-000003';
            $history = $db->table(config('Migrations')->table)->where('namespace', 'App')->where('version', $version)->get()->getResultArray();
            CLI::write('Local ledger verified: ' . count($rows) . ' events. Audit migration history: ' . ($history === [] ? 'pending' : 'recorded') . '.');
            if (!array_key_exists('apply', $options)) {
                CLI::write('Read-only check complete. Use --apply for backup, rehearsal and the audit-only cutover.');
                return EXIT_SUCCESS;
            }

            $directory = WRITEPATH . 'audit-upgrade-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
            if (!mkdir($directory, 0700)) throw new DomainException('Unable to create a private backup directory.');
            $schema = $db->query('SHOW CREATE TABLE ' . $table)->getRowArray()['Create Table'];
            $triggers = $db->query('SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', [$db->prefixTable('audit_events')])->getResultArray();
            $backup = ['format' => 'synapse.local-audit-backup.v1', 'table' => $db->prefixTable('audit_events'),
                'create_table' => $schema, 'triggers' => $triggers, 'migration_history' => $history, 'rows' => $rows];
            $encoded = json_encode($backup, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (file_put_contents($directory . '/ledger.json', $encoded, LOCK_EX) !== strlen($encoded)) throw new DomainException('Backup write failed.');
            chmod($directory . '/ledger.json', 0600);
            $restored = json_decode(file_get_contents($directory . '/ledger.json'), true, 512, JSON_THROW_ON_ERROR);
            if ($restored !== $backup) throw new DomainException('Backup read-back differs.');
            file_put_contents($directory . '/ledger.sha256', hash('sha256', $encoded) . "\n", LOCK_EX);

            // Restore the file into a disposable table; LIKE omits foreign keys/triggers.
            // The scratch data remains in this same private database, never a remote service.
            $prefix = 'synapse_audit_rehearsal_' . bin2hex(random_bytes(6)) . '_';
            $scratch = $db->protectIdentifiers($prefix . 'audit_events');
            $db->query('CREATE TABLE ' . $scratch . ' LIKE ' . $table);
            foreach (array_chunk($restored['rows'], 100) as $chunk) $db->table($prefix . 'audit_events')->insertBatch($chunk);
            $copy = $db->query('SELECT * FROM ' . $scratch . ' ORDER BY id')->getResultArray();
            if ($copy !== $rows) throw new DomainException('Backup restore rehearsal differs from the source.');
            $settings = config('Database')->default;
            $settings['DBPrefix'] = $prefix;
            $copyDb = Database::connect($settings, false);
            require_once APPPATH . 'Database/Migrations/2026-10-19-000003_HardenAuditLedger.php';
            $rehearsal = new HardenAuditLedger(Database::forge($copyDb));
            $rehearsal->up();
            $rehearsal->up(); // Retry must be non-destructive.
            $copy = $db->query('SELECT * FROM ' . $scratch . ' ORDER BY id')->getResultArray();
            $this->sameHistory($rows, $copy);
            $tenant = (int) ($rows[0]['tenant_id'] ?? $db->table('tenants')->select('id')->orderBy('id')->get(1)->getRowArray()['id']);
            $prior = array_values(array_filter($copy, static fn (array $row): bool => (int) $row['tenant_id'] === $tenant));
            $event = ['tenant_id' => $tenant, 'event_type' => 'audit.rehearsal', 'entity_type' => 'test', 'entity_id' => 'local-cutover',
                'actor_user_id' => null, 'actor_role' => null, 'actor_ip' => '127.0.0.1', 'action' => 'rehearse',
                'payload_json' => null, 'prev_hash' => $prior === [] ? AuditHash::GENESIS : $prior[count($prior) - 1]['curr_hash'],
                'hash_version' => 2, 'occurred_at' => date('Y-m-d H:i:s')];
            $event['curr_hash'] = AuditHash::compute($event);
            $copyDb->table('audit_events')->insert($event);
            $this->verify($copyDb->table('audit_events')->orderBy('id')->get()->getResultArray());
            $copyDb->close();
            CLI::write('Backup restore, migration retry, legacy-byte preservation and v2 append rehearsal passed.');

            $handle = @fopen($flag, 'x');
            if ($handle === false) throw new DomainException('Unable to acquire the exclusive maintenance flag.');
            $ownsFlag = true;
            fwrite($handle, $directory . "\n");
            fclose($handle);
            // New HTTP requests stop before routing; AuditService also rejects in-flight/CLI appends.
            // Drain cooperating transactions in the same tenant-first order used by the writer.
            if (!$db->query('SET SESSION innodb_lock_wait_timeout = 15') || !$db->transBegin()) {
                throw new DomainException('Unable to begin the audit-writer drain.');
            }
            $locked = $db->query('SELECT id FROM ' . $db->protectIdentifiers($db->prefixTable('tenants')) . ' ORDER BY id FOR UPDATE');
            if ($locked === false || !$db->transStatus() || !$db->transCommit()) {
                throw new DomainException('Unable to verify the audit-writer drain.');
            }
            $current = $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->getResultArray();
            if ($current !== $rows) throw new DomainException('Ledger changed during rehearsal; no live DDL applied. Retry with a fresh backup.');
            $currentHistory = $db->table(config('Migrations')->table)->where('namespace', 'App')->where('version', $version)->get()->getResultArray();
            if ($currentHistory !== $history) throw new DomainException('Migration history changed during rehearsal; retry after reviewing other migration activity.');
            $ddlStarted = true;
            $migrationConfig = clone config('Migrations');
            $migrationConfig->lock = true;
            $runner = new class($migrationConfig, 'default') extends \CodeIgniter\Database\MigrationRunner {
                public function findMigrations(): array
                {
                    return array_filter(parent::findMigrations(), static fn (object $migration): bool =>
                        $migration->namespace === 'App' && $migration->version === '2026-10-19-000003'
                        && $migration->name === 'HardenAuditLedger');
                }

                public function regress(int $targetBatch = 0, ?string $group = null)
                {
                    throw new DomainException('Automatic audit downgrade is prohibited; investigate the partial cutover.');
                }
            };
            if (!$runner->setNamespace('App')->latest('default')) throw new DomainException('Targeted audit migration failed.');
            // Validate or finish a partial prior installation; never invoke down().
            (new HardenAuditLedger(Database::forge($db)))->up();
            $after = $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->getResultArray();
            $this->sameHistory($rows, $after);
            $this->verify($after);
            if ($db->table(config('Migrations')->table)->where('namespace', 'App')->where('version', $version)->countAllResults() !== 1) {
                throw new DomainException('Audit migration history was not recorded exactly once.');
            }
            $ddlStarted = false;
            if (!unlink($flag)) throw new DomainException('Upgrade verified but maintenance flag could not be removed.');
            $ownsFlag = false;
            CLI::write('Audit-only upgrade verified; HTTP traffic resumed. No legacy payloads or hashes changed.');
            CLI::write('Private verified backup: ' . $directory . '/ledger.json');
            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            if ($db !== null && $db->transDepth > 0) $db->transRollback();
            if ($ownsFlag && !$ddlStarted) @unlink($flag);
            CLI::error($e instanceof DomainException ? $e->getMessage() : 'Audit upgrade failed. Inspect local logs and backup before retrying.');
            if ($ownsFlag && $ddlStarted) CLI::error('Maintenance remains active: partial/non-verified DDL must be investigated before traffic resumes.');
            return EXIT_ERROR;
        } finally {
            if ($scratch !== null && $db !== null) {
                // Only the random table allocated by this command; DROP removes its scratch triggers.
                $db->query('DROP TABLE IF EXISTS ' . $scratch);
            }
        }
    }

    private function verify(array $rows): void
    {
        $tenants = [];
        foreach ($rows as $row) $tenants[(int) $row['tenant_id']][] = $row;
        foreach ($tenants as $tenant => $events) {
            if (!AuditHash::verify($events, $tenant)['valid']) throw new DomainException('Existing ledger integrity check failed; no automatic repairs are permitted.');
        }
    }

    private function sameHistory(array $before, array $after): void
    {
        if (count($before) !== count($after)) throw new DomainException('Audit event count changed during migration.');
        foreach ($before as $index => $row) {
            if (!array_key_exists('hash_version', $row)) unset($after[$index]['hash_version']);
            if ($row !== $after[$index]) throw new DomainException('Legacy audit bytes differ; traffic must remain stopped.');
        }
    }
}
