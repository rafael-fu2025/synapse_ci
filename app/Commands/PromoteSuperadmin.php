<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class PromoteSuperadmin extends BaseCommand
{
    protected $group       = 'Synapse';
    protected $name        = 'synapse:superadmin';
    protected $description = 'Create or promote a local break-glass Platform Owner (superadmin) account.';
    protected $usage       = 'synapse:superadmin <username_or_email> [password]';
    protected $arguments   = [
        'username_or_email' => 'The username or email address for the superadmin account',
        'password'          => 'Optional password (will prompt securely if omitted)',
    ];

    public function run(array $params)
    {
        $identifier = $params[0] ?? null;
        if (empty($identifier)) {
            $identifier = CLI::prompt('Enter Superadmin username or email');
        }

        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            CLI::error('Identifier cannot be empty.');
            return;
        }

        $password = $params[1] ?? null;
        if (empty($password)) {
            $password = CLI::prompt('Enter password', null, 'required');
        }

        $password = trim((string) $password);
        if (strlen($password) < 8) {
            CLI::error('Password must be at least 8 characters long.');
            return;
        }

        $userModel = new UserModel();
        $user = $userModel->findByIdentifier($identifier);

        $now = date('Y-m-d H:i:s');
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if ($user !== null) {
            $userModel->update($user['id'], [
                'role'          => 'superadmin',
                'status'        => 'active',
                'password_hash' => $hash,
                'updated_at'    => $now,
            ]);
            CLI::write("User [{$identifier}] has been promoted to Platform Owner (superadmin).", 'green');
        } else {
            $userModel->insert([
                'tenant_id'     => 1,
                'person_id'     => null,
                'username'      => $identifier,
                'email'         => str_contains($identifier, '@') ? $identifier : null,
                'role'          => 'superadmin',
                'status'        => 'active',
                'password_hash' => $hash,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            CLI::write("Platform Owner account [{$identifier}] created successfully.", 'green');
        }
    }
}
