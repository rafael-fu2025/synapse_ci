<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\LocalTestAccounts;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DomainException;
use Throwable;

final class CreateTestAccounts extends BaseCommand
{
    protected $group = 'Synapse';
    protected $name = 'synapse:test-accounts';
    protected $description = 'Preview complete synthetic local identities; create or reset passwords explicitly in development.';
    protected $usage = 'synapse:test-accounts [--create | --reset-password] [--tenant <positive-id>]';
    protected $options = [
        '--create' => 'Explicitly create missing accounts; default is read-only preflight.',
        '--tenant' => 'Active tenant ID; defaults to CurrentTenant::id().',
                '--reset-password' => 'Reset all 15 recognized fixtures; read the new password from piped STDIN.',
    ];

    public function run(array $params)
    {
        try {
            // Never accept an environment override, password argument or positional argument.
            if (!defined('ENVIRONMENT') || ENVIRONMENT !== 'development') {
                throw new DomainException('Test accounts require the development environment.');
            }
            $options = CLI::getOptions();
            // Console forwards named options in $params alongside positional segments.
            foreach ($params as $name => $value) {
                if (!in_array($name, ['create', 'reset-password', 'tenant'], true)
                    || (array_key_exists($name, $options) && $options[$name] !== $value)) {
                    throw new DomainException('Usage: ' . $this->usage);
                }
                $options[$name] = $value;
            }
            if (array_diff(array_keys($options), ['create', 'reset-password', 'tenant']) !== []
                || (array_key_exists('create', $options) && $options['create'] !== null)
                || (array_key_exists('reset-password', $options) && $options['reset-password'] !== null)
                || (array_key_exists('create', $options) && array_key_exists('reset-password', $options))) {
                throw new DomainException('Usage: ' . $this->usage);
            }
            $tenantId = null;
            if (array_key_exists('tenant', $options)) {
                $value = $options['tenant'];
                if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)
                    || ($tenantId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
                    throw new DomainException('Select a positive tenant ID.');
                }
            }
            $resetPassword = null;
            if (array_key_exists('reset-password', $options)) {
                if (stream_isatty(STDIN)) {
                    throw new DomainException('Pipe the new password through STDIN; password arguments are not accepted.');
                }
                // Bounded read; remove only the optional final line ending, never password spaces.
                $input = stream_get_contents(STDIN, 75);
                if ($input === false) {
                    throw new DomainException('Unable to read reset password from STDIN.');
                }
                $resetPassword = preg_replace('/\r?\n\z/', '', $input);
                if (str_contains($resetPassword, "\n") || str_contains($resetPassword, "\r")) {
                    throw new DomainException('Supply exactly one password line through STDIN.');
                }
            }
            // Use the actual runtime constant, not CLI input or an injected environment.
            $result = (new LocalTestAccounts(environment: ENVIRONMENT))->run(array_key_exists('create', $options), $tenantId, $resetPassword);
            CLI::write('Synthetic test accounts — tenant ' . $result['tenant_id']);
            CLI::table(array_map(static fn (array $row): array => [
                $row['username'], $row['email'], $row['mis_id'], $row['role'], $row['status'],
            ], $result['accounts']), ['Username', 'Email', 'MIS ID', 'Role', 'Status']);
            if (isset($result['password'])) {
                CLI::write('Shared password (NEW accounts only; shown once): ' . $result['password']);
            }
            if ($resetPassword !== null) {
                CLI::write('Passwords reset for all 15 test accounts. Existing sessions are revoked; sign in again.');
            }
            return EXIT_SUCCESS;
        } catch (DomainException $error) {
            CLI::error($error->getMessage());
        } catch (Throwable) {
            CLI::error('Test account operation failed; no accounts were committed.');
        }
        return EXIT_ERROR;
    }
}
