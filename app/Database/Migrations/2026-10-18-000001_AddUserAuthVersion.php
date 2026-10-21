<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddUserAuthVersion extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'auth_version' => ['type' => 'INT', 'unsigned' => true, 'default' => 1, 'null' => false],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', 'auth_version');
    }
}
