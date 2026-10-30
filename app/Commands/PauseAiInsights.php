<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Ai\DeploymentControl;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class PauseAiInsights extends BaseCommand
{
    protected $group = 'AI';
    protected $name = 'ai:pause';
    protected $description = 'Immediately revoke AI dispatch for all units; does not recall sent requests.';
    protected $usage = 'ai:pause';

    public function run(array $params)
    {
        try {
            if ($params !== [] || CLI::getOptions() !== []) { throw new \RuntimeException(); }
            (new DeploymentControl())->pause();
            CLI::write('AI dispatch paused for all units. Already transmitted requests cannot be recalled.');
            return EXIT_SUCCESS;
        } catch (\Throwable) { CLI::error('Unable to pause AI dispatch. Stop the worker and inspect deployment access.'); return EXIT_ERROR; }
    }
}
