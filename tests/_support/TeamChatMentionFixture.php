<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Chat-only SQLite equivalent; production DDL remains MySQL/MariaDB-only. */
final class TeamChatMentionFixture
{
    public static function install(BaseConnection $db): void
    {
        if ($db->DBDriver !== 'SQLite3' || $db->database !== ':memory:') {
            throw new RuntimeException('Chat fixtures require private in-memory SQLite.');
        }
        config(\Config\Realtime::class)->enabled = false;
        $table = $db->protectIdentifiers($db->prefixTable('notifications'));
        $messages = $db->protectIdentifiers($db->prefixTable('team_chat_messages'));
        $db->query('CREATE UNIQUE INDEX ' . $db->prefixTable('notification_chat_message_scope') . " ON {$messages}(tenant_id,unit,id)");
        $db->query("CREATE TABLE {$table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, recipient_user_id INTEGER,
            recipient_person_id INTEGER, appointment_id INTEGER, appointment_version INTEGER,
            referral_id INTEGER, announcement_id INTEGER, survey_id INTEGER,
            type TEXT NOT NULL, team_unit TEXT, team_event_id INTEGER, team_task_id INTEGER,
            team_version INTEGER, team_comment_id INTEGER, workflow_event_id INTEGER,
            chat_unit VARCHAR(16), chat_message_id BIGINT, read_at TEXT, created_at TEXT NOT NULL,
            UNIQUE(tenant_id,recipient_user_id,chat_unit,chat_message_id),
            FOREIGN KEY(tenant_id,chat_unit,chat_message_id) REFERENCES {$messages}(tenant_id,unit,id),
            CHECK (((type<>'team_chat_mentioned' AND chat_unit IS NULL AND chat_message_id IS NULL)
                OR (type='team_chat_mentioned' AND recipient_user_id IS NOT NULL AND recipient_user_id>0
                    AND chat_unit IN ('clinic','guidance','facilities') AND chat_message_id IS NOT NULL AND chat_message_id>0
                    AND recipient_person_id IS NULL AND appointment_id IS NULL AND appointment_version IS NULL
                    AND referral_id IS NULL AND announcement_id IS NULL AND survey_id IS NULL AND workflow_event_id IS NULL
                    AND team_unit IS NULL AND team_event_id IS NULL AND team_task_id IS NULL
                    AND team_version IS NULL AND team_comment_id IS NULL)) IS TRUE)
        )");
        $db->resetDataCache();
    }
}
