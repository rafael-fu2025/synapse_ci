<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Push\PushPublisher;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/** No database or transport access: every external boundary is a double. */
final class PushPublisherTest extends CIUnitTestCase
{
    private function connection(int &$depth, bool &$healthy): BaseConnection
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('__get')->willReturnCallback(static function (string $name) use (&$depth) {
            return $name === 'transDepth' ? $depth : null;
        });
        $db->method('transStatus')->willReturnCallback(static function () use (&$healthy) { return $healthy; });
        $db->expects(self::never())->method('query');
        $db->expects(self::never())->method('transCommit');
        return $db;
    }

    public function testOnlyCommittedWritesReachTheRecipient(): void
    {
        $depth = 1; $healthy = true; $sent = [];
        $db = $this->connection($depth, $healthy);
        $publisher = new PushPublisher($db,
            static function ($tenant, $ids) {
                self::assertSame(7, $tenant);
                self::assertSame([31], $ids);
                return [['id' => 31, 'tenant_id' => 7, 'recipient_user_id' => 5, 'type' => 'appointment_confirmed',
                    'appointment_id' => 42]];
            },
            static fn () => true,
            static function ($tenant, $user, $message) use (&$sent) { $sent[] = [$tenant, $user, $message]; return true; },
            true,
        );
        $publisher->publishCommitted(7, [31]);
        self::assertSame([], $sent, 'Never push before the outer transaction commits');
        $depth = 0;
        $publisher->publishCommitted(7, [31]);
        self::assertCount(1, $sent);
        self::assertSame([7, 5], [$sent[0][0], $sent[0][1]]);
        self::assertSame('Appointment confirmed', $sent[0][2]['title']);
        self::assertSame(['type' => 'appointment_confirmed', 'id' => '42'], $sent[0][2]['data']);
    }

    public function testTeamTemplatesAreGenericScopedAndDefaultDenyWithoutCapability(): void
    {
        $depth = 0; $healthy = true; $sent = [];
        $rows = [
            ['id' => 31, 'tenant_id' => 7, 'recipient_user_id' => 5, 'type' => 'team_task_assigned',
                'team_unit' => 'clinic', 'team_task_id' => 41],
            ['id' => 32, 'tenant_id' => 7, 'recipient_user_id' => 6, 'type' => 'team_event_updated',
                'team_unit' => 'guidance', 'team_event_id' => 42],
            ['id' => 33, 'tenant_id' => 7, 'recipient_user_id' => 7, 'type' => 'team_chat_mentioned',
                'chat_unit' => 'facilities', 'chat_message_id' => 43],
        ];
        $denied = new PushPublisher($this->connection($depth, $healthy), static fn () => $rows,
            static fn () => true, static function (...$args) use (&$sent) { $sent[] = $args; return true; }, true);
        $denied->publishCommitted(7, [31, 32, 33]);
        self::assertSame([], $sent, 'Legacy/unclassified installations must never receive Team push.');

        $enabled = new PushPublisher($this->connection($depth, $healthy), static fn () => $rows,
            static fn () => true, static function (...$args) use (&$sent) { $sent[] = $args; return true; },
            true, static fn () => 0.0, static fn () => true);
        $enabled->publishCommitted(7, [31, 32, 33]);
        self::assertSame([
            ['type' => 'team_task', 'id' => '41', 'unit' => 'clinic'],
            ['type' => 'team_event', 'id' => '42', 'unit' => 'guidance'],
            ['type' => 'team_chat_message', 'id' => '43', 'unit' => 'facilities'],
        ], array_column(array_column($sent, 2), 'data'));
        self::assertStringNotContainsString('SECRET', json_encode($sent));
        self::assertStringNotContainsString('PRIVATE', json_encode($sent));
    }

    public function testNoticesWithoutAMobileSurfaceAreNeverPushed(): void
    {
        $depth = 0; $healthy = true; $sent = 0;
        $publisher = new PushPublisher($this->connection($depth, $healthy),
            static fn () => [
                ['id' => 31, 'tenant_id' => 7, 'recipient_user_id' => 5, 'type' => 'referral_received', 'referral_id' => 3],
                ['id' => 32, 'tenant_id' => 7, 'recipient_user_id' => 6, 'type' => 'workflow_alert'],
                ['id' => 33, 'tenant_id' => 7, 'recipient_user_id' => 7, 'type' => 'team_task_assigned'],
                ['id' => 34, 'tenant_id' => 7, 'recipient_user_id' => 8, 'type' => 'announcement_published', 'announcement_id' => 11],
            ],
            static fn () => true,
            static function () use (&$sent) { ++$sent; return true; },
            true,
        );
        $publisher->publishCommitted(7, [31, 32, 33, 34]);
        self::assertSame(1, $sent, 'Only the announcement has a mobile surface');
    }

    public function testUnauthorizedRecipientsAndForeignTenantsAreSkipped(): void
    {
        $depth = 0; $healthy = true; $sent = [];
        $publisher = new PushPublisher($this->connection($depth, $healthy),
            static fn () => [
                ['id' => 31, 'tenant_id' => 7, 'recipient_user_id' => 5, 'type' => 'survey_published', 'survey_id' => 9],
                ['id' => 32, 'tenant_id' => 8, 'recipient_user_id' => 6, 'type' => 'survey_published', 'survey_id' => 9],
                ['id' => 33, 'tenant_id' => 7, 'recipient_user_id' => 0, 'type' => 'survey_published', 'survey_id' => 9],
            ],
            static fn ($tenant, $user) => $user === 5,
            static function ($tenant, $user) use (&$sent) { $sent[] = $user; return true; },
            true,
        );
        $publisher->publishCommitted(7, [31, 32, 33]);
        self::assertSame([5], $sent);
    }

    public function testFailedTransportStopsTheFanout(): void
    {
        $depth = 0; $healthy = true; $sent = [];
        $publisher = new PushPublisher($this->connection($depth, $healthy),
            static fn () => [
                ['id' => 31, 'tenant_id' => 7, 'recipient_user_id' => 5, 'type' => 'announcement_published', 'announcement_id' => 1],
                ['id' => 32, 'tenant_id' => 7, 'recipient_user_id' => 6, 'type' => 'announcement_published', 'announcement_id' => 2],
            ],
            static fn () => true,
            static function () use (&$sent) { $sent[] = 1; return false; },
            true,
        );
        $publisher->publishCommitted(7, [31, 32]);
        self::assertCount(1, $sent);
    }

    public function testTransportExceptionsNeverEscapeAndDisabledPublishersDoNothing(): void
    {
        $depth = 0; $healthy = true;
        $publisher = new PushPublisher($this->connection($depth, $healthy),
            static fn () => [['id' => 31, 'tenant_id' => 7, 'recipient_user_id' => 5, 'type' => 'announcement_published', 'announcement_id' => 1]],
            static fn () => true,
            static function () { throw new RuntimeException('transport unavailable'); },
            true,
        );
        $publisher->publishCommitted(7, [31]);
        self::assertTrue(true);

        $disabled = new PushPublisher($this->connection($depth, $healthy),
            static fn () => self::fail('No audience expected while push is disabled'),
            static fn () => true,
            static fn () => true,
            false,
        );
        $disabled->publishCommitted(7, [31]);
        self::assertTrue(true);
    }

    public function testAttemptBudgetIsSharedAcrossBatches(): void
    {
        $depth = 0; $healthy = true; $sent = 0; $audiences = 0;
        $publisher = new PushPublisher($this->connection($depth, $healthy),
            static function ($tenant) use (&$audiences) {
                ++$audiences;
                return array_map(static fn ($id) => [
                    'id' => $id, 'tenant_id' => $tenant, 'recipient_user_id' => $id,
                    'type' => 'announcement_published', 'announcement_id' => $id,
                ], range(1, 5));
            },
            static fn () => true,
            static function () use (&$sent) { ++$sent; return true; },
            true,
            static fn () => 0.0,
        );
        $publisher->publishCommitted(7, [1]);
        self::assertSame(5, $sent);
        $publisher->publishCommitted(8, [1]);
        self::assertSame(8, $sent, 'Eight attempts in total, not eight per batch');
        self::assertSame(2, $audiences);
    }
}
