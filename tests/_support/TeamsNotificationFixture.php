<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Proposed shared-schema contract, never installed outside private SQLite fixtures. */
final class TeamsNotificationFixture
{
    public static function install(BaseConnection $db): void
    {
        if ($db->DBDriver !== 'SQLite3' || $db->database !== ':memory:') {
            throw new RuntimeException('Teams notification fixtures require private in-memory SQLite.');
        }
        config(\Config\Realtime::class)->enabled = false;
        $table = $db->protectIdentifiers($db->prefixTable('notifications'));
        $db->query("CREATE TABLE {$table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, recipient_user_id INTEGER,
            recipient_person_id INTEGER, appointment_id INTEGER, appointment_version INTEGER,
            referral_id INTEGER, announcement_id INTEGER, survey_id INTEGER,
            type TEXT NOT NULL, team_unit TEXT, team_event_id INTEGER, team_task_id INTEGER,
            team_version INTEGER, team_comment_id INTEGER, read_at TEXT, created_at TEXT NOT NULL
        )");
    }
}
