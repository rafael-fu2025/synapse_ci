<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Facilities\BmgBuildService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

class PurgeBmgBuilds extends BaseCommand
{
    protected $group = 'BMG';
    protected $name = 'bmg:purge-builds';
    protected $description = 'Remove expired or invalid private BMG firmware commissioning build directories.';
    protected $usage = 'bmg:purge-builds';
    protected $options = [];

    public function run(array $params)
    {
        $summary = ['removed' => 0, 'busy' => 0, 'failed' => 0];
        try {
            if ($params !== [] || CLI::getOptions() !== []) {
                throw new InvalidArgumentException('Unexpected arguments or options.');
            }
            $result = $this->purgeService()->purgeExpired();
            if (!is_int($result['removed'] ?? null) || $result['removed'] < 0 || !is_bool($result['busy'] ?? null)) {
                throw new UnexpectedValueException('Unexpected purge result.');
            }
            $summary['removed'] = $result['removed'];
            $summary['busy'] = $result['busy'] ? 1 : 0;
        } catch (Throwable) {
            $summary['failed'] = 1;
        }
        CLI::write('removed=' . $summary['removed'] . ' busy=' . $summary['busy'] . ' failed=' . $summary['failed']);
        return $summary['failed'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }

    protected function purgeService(): BmgBuildService
    {
        return new BmgBuildService();
    }
}
