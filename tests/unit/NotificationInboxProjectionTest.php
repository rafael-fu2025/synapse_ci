<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NotificationService;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * The inbox projection, exercised without a database.
 *
 * The projection runs inside a static closure, so it must never reach for
 * `$this`: a static closure has no object to bind, and the failure surfaces
 * only as a 500 on the notifications endpoint. These tests pin the shape of a
 * projected notice and the deep-link target it carries.
 */
final class NotificationInboxProjectionTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function project(array $row): array
    {
        $method = new ReflectionMethod(NotificationService::class, 'project');
        $method->setAccessible(true);

        /** @var array<string, mixed> $projected */
        $projected = $method->invoke(null, $row, NotificationService::destinationUrls());

        return $projected;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function target(array $row): ?array
    {
        $method = new ReflectionMethod(NotificationService::class, 'target');
        $method->setAccessible(true);

        /** @var array<string, mixed>|null $target */
        $target = $method->invoke(null, $row);

        return $target;
    }

    public function testAppointmentNoticeCarriesItsRecordAsTheDeepLinkTarget(): void
    {
        $row = [
            'id' => '17',
            'type' => 'appointment_confirmed',
            'created_at' => '2026-11-04 08:30:00',
            'read_at' => null,
            'appointment_id' => '42',
        ];

        $projected = $this->project($row);

        $this->assertSame(17, $projected['id']);
        $this->assertSame('Appointment confirmed', $projected['title']);
        $this->assertSame(['kind' => 'appointment', 'id' => 42], $projected['target']);
        $this->assertNotSame('', $projected['url']);
    }

    public function testAnnouncementAndSurveyNoticesCarryTheirOwnRecord(): void
    {
        $announcement = $this->project([
            'id' => '3',
            'type' => 'announcement_published',
            'created_at' => '2026-11-04 08:30:00',
            'read_at' => null,
            'announcement_id' => '9',
        ]);
        $this->assertSame(['kind' => 'announcement', 'id' => 9], $announcement['target']);

        $survey = $this->project([
            'id' => '4',
            'type' => 'survey_published',
            'created_at' => '2026-11-04 08:30:00',
            'read_at' => null,
            'survey_id' => '11',
        ]);
        $this->assertSame(['kind' => 'survey', 'id' => 11], $survey['target']);
    }

    public function testNoticeWithoutAMobileSurfaceReportsNoTarget(): void
    {
        $projected = $this->project([
            'id' => '5',
            'type' => 'team_task_assigned',
            'created_at' => '2026-11-04 08:30:00',
            'read_at' => null,
            'team_unit' => 'clinic',
        ]);

        $this->assertNull($projected['target']);
    }

    public function testStudentReferralHasNoClinicalPayloadStaffLinkOrMobileTarget(): void
    {
        foreach (['clinic', 'guidance'] as $unit) {
            $item = $this->project(['id' => '8', 'type' => 'student_referral',
                'created_at' => '2026-11-04 08:30:00', 'read_at' => null,
                'target_module' => $unit, 'referral_status' => 'closed', 'referral_id' => 99,
                'reason_description' => 'SECRET', 'appointment_id' => 12]);
            $this->assertSame('Referral submitted', $item['title']);
            $this->assertSame('A referral has been submitted for you. The receiving office will contact you about next steps.', $item['message']);
            $this->assertNull($item['url']);
            $this->assertNull($item['target']);
            $this->assertSame(['id', 'title', 'message', 'created_at', 'read_at', 'target', 'url'], array_keys($item));
            $this->assertStringNotContainsString('SECRET', json_encode($item));
            $this->assertStringNotContainsString($unit, json_encode($item));
        }
    }

    public function testMissingOrInvalidRecordNeverProducesATarget(): void
    {
        $this->assertNull($this->target(['type' => 'appointment_confirmed']));
        $this->assertNull($this->target(['type' => 'appointment_confirmed', 'appointment_id' => '0']));
        $this->assertNull($this->target(['type' => 'appointment_confirmed', 'appointment_id' => 'not-a-number']));
        $this->assertNull($this->target(['type' => 'referral_received', 'referral_id' => '5']));
    }

    public function testStoredTimestampsAreExposedForDisplay(): void
    {
        $projected = $this->project([
            'id' => '6',
            'type' => 'appointment_confirmed',
            'created_at' => '2026-11-04 08:30:00',
            'read_at' => '2026-11-04 09:00:00',
            'appointment_id' => '42',
        ]);

        $this->assertSame('2026-11-04T16:30:00+08:00', $projected['created_at']);
        $this->assertSame('2026-11-04T17:00:00+08:00', $projected['read_at']);
    }

    public function testUnreadNoticeReportsNoReadTimestamp(): void
    {
        $projected = $this->project([
            'id' => '7',
            'type' => 'appointment_confirmed',
            'created_at' => '2026-11-04 08:30:00',
            'read_at' => null,
            'appointment_id' => '42',
        ]);

        $this->assertNull($projected['read_at']);
    }
}
