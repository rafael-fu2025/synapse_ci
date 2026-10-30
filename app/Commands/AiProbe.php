<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Ai\AiFailure;
use App\Services\Ai\SyntheticProbe;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class AiProbe extends BaseCommand
{
    protected $group = 'AI';
    protected $name = 'ai:probe';
    protected $description = 'Explicitly contact the configured endpoint with a fixed synthetic fixture only.';
    protected $usage = 'ai:probe --tenant <positive-id>';
    protected $options = ['--tenant' => 'Required approved tenant ID; no default tenant is used.'];

    public function run(array $params)
    {
        $options = CLI::getOptions();
        foreach (array_intersect_key($options, $params) as $key => $value) {
            if ($value !== $params[$key]) {
                CLI::write('success=0 error=invalid_options');
                return EXIT_ERROR;
            }
        }
        $options = array_replace($options, $params);
        $tenant = $options['tenant'] ?? null;
        if (array_keys($options) !== ['tenant'] || (! is_int($tenant) && ! is_string($tenant))
            || ! preg_match('/\A[1-9][0-9]*\z/D', (string) $tenant)
            || filter_var($tenant, FILTER_VALIDATE_INT) === false) {
            CLI::write('success=0 error=invalid_options');
            return EXIT_ERROR;
        }
        try {
            $result = $this->probe((int) $tenant);
            CLI::write('success=1 verified=1 revision=' . $result['revision']);
            return EXIT_SUCCESS;
        } catch (Throwable $error) {
            CLI::write('success=0 error=' . ($error instanceof AiFailure ? $error->reason : 'probe_failed'));
            return EXIT_ERROR;
        }
    }

    protected function probe(int $tenantId): array
    {
        return (new SyntheticProbe())->execute($tenantId);
    }
}
