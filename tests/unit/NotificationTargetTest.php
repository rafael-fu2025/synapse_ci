<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NotificationService;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/** No database or transport access: only the target projection is exercised. */
final class NotificationTargetTest extends CIUnitTestCase
{
    private function target(array $row): ?array
    {
        $method = new ReflectionMethod(NotificationService::class, 'target');
        return $method->invoke(new NotificationService(), $row);
    }

    public function testEachMobileSurfaceReportsItsOwnTarget(): void
    {
        self::assertSame(['kind' => 'appointment', 'id' => 42],
            $this->target(['type' => 'appointment_confirmed', 'appointment_id' => '42']));
        self::assertSame(['kind' => 'announcement', 'id' => 41],
            $this->target(['type' => 'announcement_published', 'announcement_id' => 41]));
        self::assertSame(['kind' => 'survey', 'id' => 7],
            $this->target(['type' => 'survey_published', 'survey_id' => '7']));
    }

    public function testTeamSurfacesReportExactUnitScopedTargets(): void
    {
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            self::assertSame(['kind' => 'team_task', 'unit' => $unit, 'id' => 51], $this->target([
                'type' => 'team_task_commented', 'team_unit' => $unit, 'team_task_id' => '51', 'team_comment_id' => 9,
            ]));
            self::assertSame(['kind' => 'team_event', 'unit' => $unit, 'id' => 52], $this->target([
                'type' => 'team_event_updated', 'team_unit' => $unit, 'team_event_id' => 52,
            ]));
            self::assertSame(['kind' => 'team_chat_message', 'unit' => $unit, 'id' => 53], $this->target([
                'type' => 'team_chat_mentioned', 'chat_unit' => $unit, 'chat_message_id' => '53',
            ]));
        }
    }

    public function testNoticesWithoutAMobileSurfaceReportNoTarget(): void
    {
        foreach ([
            ['type' => 'referral_received', 'referral_id' => 5],
            ['type' => 'workflow_alert'],
            ['type' => 'unknown'],
            [],
        ] as $row) {
            self::assertNull($this->target($row), json_encode($row));
        }
    }

    public function testMissingOrInvalidIdentifiersNeverProduceATarget(): void
    {
        self::assertNull($this->target(['type' => 'appointment_confirmed']));
        self::assertNull($this->target(['type' => 'appointment_confirmed', 'appointment_id' => null]));
        self::assertNull($this->target(['type' => 'appointment_confirmed', 'appointment_id' => 0]));
        self::assertNull($this->target(['type' => 'appointment_confirmed', 'appointment_id' => -1]));
        self::assertNull($this->target(['type' => 'appointment_confirmed', 'appointment_id' => 'abc']));
        self::assertNull($this->target(['type' => 'announcement_published', 'announcement_id' => 1.5]));
        self::assertNull($this->target(['type' => 'survey_published', 'survey_id' => '']));
        foreach ([
            ['type' => 'team_task_assigned', 'team_unit' => 'main', 'team_task_id' => 1],
            ['type' => 'team_task_assigned', 'team_unit' => 'clinic', 'team_task_id' => null],
            ['type' => 'team_event_added', 'team_unit' => 'clinic', 'team_event_id' => 0],
            ['type' => 'team_chat_mentioned', 'chat_unit' => 'clinic', 'chat_message_id' => 'abc'],
        ] as $row) {
            self::assertNull($this->target($row), json_encode($row));
        }
    }
}
