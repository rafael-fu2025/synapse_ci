<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Ai\Profile;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class AiStatus extends BaseCommand
{
    protected $group = 'AI';
    protected $name = 'ai:status';
    protected $description = 'Read deployment AI configuration readiness without SQL or network requests.';
    protected $usage = 'ai:status';

    public function run(array $params)
    {
        if ($params !== [] || CLI::getOptions() !== []) {
            CLI::write('error=invalid_options');
            return EXIT_ERROR;
        }
        try {
            $status = $this->profile()->publicStatus();
            $fields = [];
            foreach (['enabled', 'configured', 'governanceApproved', 'revisionApproved', 'compatibilityVerified'] as $key) {
                $fields[] = $key . '=' . (int) $status[$key];
            }
            $fields[] = 'revision=' . $status['revision'];
            CLI::write(implode(' ', $fields));
            return EXIT_SUCCESS;
        } catch (Throwable) {
            CLI::write('error=status_unavailable');
            return EXIT_ERROR;
        }
    }

    protected function profile(): Profile
    {
        return Profile::fromConfig();
    }
}
