<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Realtime\Server;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Realtime;
use Throwable;
use Workerman\Worker;

final class RealtimeServe extends BaseCommand
{
    protected $group = 'Realtime';
    protected $name = 'realtime:serve';
    protected $description = 'Run the opt-in realtime server in the foreground (one process).';
    protected $usage = 'realtime:serve [start]';
    protected $arguments = ['start' => 'Foreground only; stop with Ctrl+C or your process supervisor.'];

    public function run(array $params)
    {
        if (($params !== [] && $params !== ['start']) || CLI::getOptions() !== []) {
            CLI::error('Use php spark realtime:serve [start] without options; foreground only.');
            return EXIT_ERROR;
        }
        try {
            $config = config(Realtime::class);
            $config->assertValid();
            if (! class_exists(Worker::class)) {
                CLI::error('Workerman is not installed. Install the project realtime dependency first.');
                return EXIT_ERROR;
            }
            if (version_compare(Worker::VERSION, '5.0.0', '<')) {
                CLI::error('Realtime requires Workerman 5 or newer.');
                return EXIT_ERROR;
            }
            // Workerman must never parse Spark command names or arbitrary CLI flags.
            $savedArgv = $GLOBALS['argv'] ?? [];
            $savedArgc = $GLOBALS['argc'] ?? 0;
            $savedServerArgv = $_SERVER['argv'] ?? [];
            $savedServerArgc = $_SERVER['argc'] ?? 0;
            try {
                $GLOBALS['argv'] = $_SERVER['argv'] = [ROOTPATH . 'spark', 'start'];
                $GLOBALS['argc'] = $_SERVER['argc'] = 2;
                Worker::$daemonize = false;
                Worker::$pidFile = WRITEPATH . 'realtime.pid';
                Worker::$statusFile = WRITEPATH . 'realtime.status';
                Worker::$logFile = WRITEPATH . 'logs/realtime.log';
                (new Server($config))->run();
            } finally {
                $GLOBALS['argv'] = $savedArgv;
                $GLOBALS['argc'] = $savedArgc;
                $_SERVER['argv'] = $savedServerArgv;
                $_SERVER['argc'] = $savedServerArgc;
            }
        } catch (Throwable) {
            CLI::error('Realtime startup failed. Check enabled, keys, exact origins, listeners, limits, and Workerman runtime support.');
            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
