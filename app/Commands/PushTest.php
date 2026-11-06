<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Push\PushTransport;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Operator smoke test: deliver one push to a user's registered devices. */
final class PushTest extends BaseCommand
{
    protected $group = 'Push';
    protected $name = 'push:test';
    protected $description = 'Send one test push to a user\'s registered devices.';
    protected $usage = 'push:test <tenantId> <userId> [title] [body]';
    protected $arguments = [
        'tenantId' => 'Tenant id',
        'userId' => 'User id',
        'title' => 'Notification title',
        'body' => 'Notification body',
    ];

    public function run(array $params)
    {
        $tenant = (int) ($params[0] ?? 0);
        $user = (int) ($params[1] ?? 0);
        if ($tenant < 1 || $user < 1) {
            CLI::error('Usage: push:test <tenantId> <userId> [title] [body]');
            return EXIT_USER_INPUT;
        }
        $title = (string) ($params[2] ?? 'Push test');
        $body = (string) ($params[3] ?? 'If you can read this, delivery works.');

        $delivered = (new PushTransport())->send($tenant, $user, [
            'title' => $title,
            'body' => $body,
            'data' => ['type' => 'announcement_published', 'id' => '1'],
        ]);

        CLI::write('delivered=' . ($delivered ? 'true' : 'false'), $delivered ? 'green' : 'red');
        return $delivered ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
