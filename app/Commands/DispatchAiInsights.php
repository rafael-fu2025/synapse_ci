<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Ai\AnalysisCatalog;
use App\Services\Ai\InsightWorker;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class DispatchAiInsights extends BaseCommand
{
    protected $group = 'AI';
    protected $name = 'ai:dispatch';
    protected $description = 'Dispatch one shared, finite batch of approved department AI jobs for an explicit tenant.';
    protected $usage = 'ai:dispatch --tenant <positive-id> [--unit <clinic|guidance|bmg>]';
    protected $options = ['--tenant' => 'Required positive tenant ID; no default tenant is used.',
        '--unit' => 'One approved unit; omit to share the batch budget across all approved units.'];
    protected ?string $unit = null;

    public function run(array $params)
    {
        $counts = ['processed' => 0, 'failed' => 0, 'outcome_unknown' => 0];
        try {
            $options = CLI::getOptions();
            foreach (array_intersect_key($options, $params) as $key => $value) {
                if ($value !== $params[$key]) {
                    throw new \InvalidArgumentException();
                }
            }
            $options = array_replace($options, $params);
            $tenant = $options['tenant'] ?? null;
            if (array_diff(array_keys($options), ['tenant', 'unit']) !== [] || (! is_int($tenant) && ! is_string($tenant))
                || ! preg_match('/\A[1-9][0-9]*\z/D', (string) $tenant)
                || filter_var($tenant, FILTER_VALIDATE_INT) === false) {
                throw new \InvalidArgumentException();
            }
            $this->unit = null;
            if (array_key_exists('unit', $options)) {
                if (!is_string($options['unit']) || AnalysisCatalog::forUnit($options['unit']) === []) {
                    throw new \InvalidArgumentException();
                }
                $this->unit = $options['unit'];
            }
            $result = $this->dispatch((int) $tenant);
            foreach ($counts as $key => $_) {
                if (! isset($result[$key]) || ! is_int($result[$key]) || $result[$key] < 0) {
                    throw new \UnexpectedValueException();
                }
            }
            $counts = array_intersect_key($result, $counts);
        } catch (Throwable) {
            $counts = ['processed' => 0, 'failed' => 1, 'outcome_unknown' => 0];
        }
        CLI::write('processed=' . $counts['processed'] . ' failed=' . $counts['failed'] . ' outcome_unknown=' . $counts['outcome_unknown']);
        return $counts['failed'] === 0 && $counts['outcome_unknown'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }

    protected function dispatch(int $tenantId): array
    {
        // The default worker reloads deployment configuration at every dispatch gate.
        return (new InsightWorker())->run($tenantId, $this->unit);
    }
}
