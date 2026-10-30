<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Ai\AnalysisCatalog;
use App\Services\Ai\DeploymentControl;
use App\Services\Ai\Profile;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ResumeAiInsights extends BaseCommand
{
    protected $group = 'AI';
    protected $name = 'ai:resume';
    protected $description = 'Authorize the current reviewed AI revision in the cross-process dispatch manifest.';
    protected $usage = 'ai:resume --tenant <positive-id> [--unit <clinic|guidance|bmg>]';
    protected $options = ['--tenant' => 'Required explicitly approved tenant ID.',
        '--unit' => 'Approved unit to validate; defaults to the first approved unit. The manifest covers the reviewed revision.'];

    public function run(array $params)
    {
        try {
            $options = CLI::getOptions();
            foreach (array_intersect_key($options, $params) as $key => $value) {
                if ($value !== $params[$key]) { throw new \RuntimeException(); }
            }
            $options = array_replace($options, $params);
            if (array_diff(array_keys($options), ['tenant', 'unit']) !== [] || !isset($options['tenant'])
                || (!is_int($options['tenant']) && !is_string($options['tenant']))
                || !preg_match('/\A[1-9][0-9]*\z/D', (string) $options['tenant'])
                || filter_var($options['tenant'], FILTER_VALIDATE_INT) === false) { throw new \RuntimeException(); }
            $unit = null;
            if (array_key_exists('unit', $options)) {
                if (!is_string($options['unit']) || AnalysisCatalog::forUnit($options['unit']) === []) { throw new \RuntimeException(); }
                $unit = $options['unit'];
            }
            $this->resume((int) $options['tenant'], $unit);
            CLI::write('Current AI revision authorized for dispatch. This does not start a worker or generate a report.');
            return EXIT_SUCCESS;
        } catch (\Throwable) { CLI::error('AI authorization failed. Pause or stop workers and check the explicit tenant and unit, approvals, revision and deployment configuration.'); return EXIT_ERROR; }
    }

    protected function resume(int $tenantId, ?string $unit): void
    {
        (new DeploymentControl())->resume(Profile::fromConfig(), $tenantId, $unit);
    }
}
