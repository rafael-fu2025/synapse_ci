<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddTeamChatReferences extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('team_chat_messages', [
            'task_ids' => ['type' => 'TEXT', 'null' => true],
            'mentions' => ['type' => 'TEXT', 'null' => true],
            'reply_to_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('team_chat_messages', ['task_ids', 'mentions', 'reply_to_id']);
    }
}
