<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DomainException;
use Throwable;

/** Install only the additive library migration, without advancing unrelated work. */
final class InstallQuestionBank extends BaseCommand
{
    protected $group = 'Synapse';
    protected $name = 'synapse:question-bank-install';
    protected $description = 'Inspect or explicitly install the local question bank tables only.';
    protected $usage = 'synapse:question-bank-install [--apply]';
    protected $options = ['--apply' => 'Apply the additive library migration to local development humus_db.'];

    public function run(array $params)
    {
        try {
            $options = CLI::getOptions();
            foreach ($params as $key => $value) {
                if ($key !== 'apply' || $value !== null) throw new DomainException('Usage: ' . $this->usage);
                $options[$key] = $value;
            }
            if (array_diff(array_keys($options), ['apply']) !== [] || (array_key_exists('apply', $options) && $options['apply'] !== null)) throw new DomainException('Usage: ' . $this->usage);
            if (ENVIRONMENT !== 'development') throw new DomainException('This installer is restricted to development.');
            $db = Database::connect('default');
            if ($db->DBDriver !== 'MySQLi' || !in_array($db->hostname, ['localhost', '127.0.0.1', '::1'], true) || $db->database !== 'humus_db') {
                throw new DomainException('Expected local development MySQL humus_db.');
            }
            foreach (['tenants', 'users', 'surveys', 'audit_events'] as $table) {
                $meta = $db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$db->prefixTable($table)])->getRowArray();
                if (strtoupper($meta['ENGINE'] ?? '') !== 'INNODB') throw new DomainException('Expected existing InnoDB survey and identity tables.');
            }
            if (!$db->fieldExists('hash_version', 'audit_events')) throw new DomainException('Install and verify the audit-ledger prerequisite first.');
            $version = '2026-10-19-000006';
            $history = $db->table(config('Migrations')->table)->where(['namespace' => 'App', 'version' => $version])->countAllResults();
            CLI::write('Question bank migration: ' . ($history === 1 ? 'recorded' : 'pending') . '.');
            if (!array_key_exists('apply', $options)) return EXIT_SUCCESS;
            $config = clone config('Migrations');
            $config->lock = true;
            $runner = new class($config, 'default') extends \CodeIgniter\Database\MigrationRunner {
                public function findMigrations(): array
                {
                    return array_filter(parent::findMigrations(), static fn (object $migration): bool =>
                        $migration->namespace === 'App' && $migration->version === '2026-10-19-000006' && $migration->name === 'CreateSurveyQuestionBank');
                }
                public function regress(int $targetBatch = 0, ?string $group = null)
                {
                    throw new DomainException('Automatic library downgrade is prohibited. Inspect partial DDL before retrying.');
                }
            };
            if (!$runner->setNamespace('App')->latest('default')
                || $db->table(config('Migrations')->table)->where(['namespace' => 'App', 'version' => $version])->countAllResults() !== 1
                || !$db->tableExists('survey_question_bank') || !$db->tableExists('survey_question_bank_revisions')) {
                throw new DomainException('Question bank installation did not complete. Inspect migration state before retrying.');
            }
            CLI::write('Question bank installed. No existing surveys, responses, grants or unrelated migrations changed.');
            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error($e instanceof DomainException ? $e->getMessage() : 'Question bank installation failed. Inspect local logs and migration state; do not drop existing library history.');
            return EXIT_ERROR;
        }
    }
}
