<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\WorkflowNotificationScanner;
use App\Services\WorkflowNotifications;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\WorkflowAlerts;
use InvalidArgumentException;
use Throwable;

/**
 * One finite invocation for the current SYNAPSE_TENANT_ID; recommend once per minute.
 * Scan is a full captured-ceiling sweep in bounded chunks, not a --limit-row sweep.
 */
class DispatchWorkflowNotifications extends BaseCommand
{
    protected $group = 'Notifications';
    protected $name = 'notifications:dispatch';
    protected $description = 'Scan current-tenant workflow alerts and dispatch a bounded outbox batch.';
    protected $usage = 'notifications:dispatch [--limit 100] [--scan 0|1]';
    protected $options = [
        '--limit' => 'Dispatch only: positive batch size, bounded by WorkflowAlerts.maximumDispatchLimit; does not cap the scan.',
        '--scan' => '1 (default) scans before dispatch; 0 dispatches only. A bare flag means 1.',
    ];

    public function run(array $params)
    {
        $counts = ['scanned' => 0, 'recorded' => 0, 'processed' => 0, 'failed' => 0];
        try {
            $config = $this->alertsConfig();
            $options = array_replace(CLI::getOptions(), $params);
            if (array_diff(array_keys($options), ['limit', 'scan']) !== []) throw new InvalidArgumentException('Invalid options.');
            $limit = $options['limit'] ?? $config->dispatchLimit;
            if (array_key_exists('limit', $options) && $options['limit'] === null) throw new InvalidArgumentException('Missing limit.');
            $limit = filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $config->maximumDispatchLimit]]);
            if ($limit === false) throw new InvalidArgumentException('Invalid limit.');
            $scan = array_key_exists('scan', $options) ? ($options['scan'] ?? '1') : '1';
            if (!in_array($scan, ['0', '1', 0, 1], true)) throw new InvalidArgumentException('Invalid scan flag.');
            if ($config->enabled) {
                if ((string) $scan === '1') {
                    $result = $this->scanNotifications();
                    foreach (['scanned', 'recorded', 'failed'] as $key) $counts[$key] += (int) $result[$key];
                }
                $result = $this->dispatchNotifications($limit);
                $counts['processed'] = (int) $result['processed'];
                $counts['failed'] += (int) $result['failed'];
            }
        } catch (Throwable $e) {
            // Neither exception bodies nor domain records belong on cron stdout/stderr.
            $counts['failed']++;
        }
        CLI::write(implode(' ', array_map(static fn ($key, $value) => $key . '=' . $value, array_keys($counts), $counts)));
        return $counts['failed'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }

    protected function alertsConfig(): WorkflowAlerts
    {
        return config(WorkflowAlerts::class);
    }

    protected function scanNotifications(): array
    {
        return (new WorkflowNotificationScanner())->scan();
    }

    protected function dispatchNotifications(int $limit): array
    {
        return (new WorkflowNotifications())->dispatch($limit);
    }
}
