<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\GuidanceController;
use App\Services\Guidance\GuidanceCaseService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;

/** DB-free: no controller/service constructor, migrations, seeds, or operational fixtures. */
final class GuidanceAuditSafetyTest extends CIUnitTestCase
{
    public static function invalidConfirmations(): iterable
    {
        foreach ([null, '', '0', 'yes', ['1'], ['1', '1']] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('invalidConfirmations')]
    public function testConfirmationRequiresExplicitPostedValue(mixed $value): void
    {
        $controller = (new ReflectionClass(GuidanceController::class))->newInstanceWithoutConstructor();
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('post', ['confirmed' => $value]);
        $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        (new ReflectionMethod($controller, 'requireConfirmation'))->invoke($controller);
    }

    public function testClosureRefusesMissingConfirmationBeforeDatabaseAccess(): void
    {
        $service = (new ReflectionClass(GuidanceCaseService::class))->newInstanceWithoutConstructor();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $service->closeCase(10);
    }

    public function testInvalidQueueTypeIsRejectedBeforeDatabaseAccess(): void
    {
        $service = (new ReflectionClass(GuidanceCaseService::class))->newInstanceWithoutConstructor();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $service->openSessionForTicket(10, 7, false, 'unknown');
    }

    public static function sessionTypes(): iterable
    {
        foreach (['intake', 'regular', 'crisis', 'follow_up', 'termination'] as $type) {
            yield [$type];
        }
    }

    #[DataProvider('sessionTypes')]
    public function testWorkspaceAcceptsEachSupportedType(string $type): void
    {
        $controller = (new ReflectionClass(GuidanceController::class))->newInstanceWithoutConstructor();
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('post', ['session_type' => $type, 'confirmed' => '1']);
        $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
        self::assertSame($type, (new ReflectionMethod($controller, 'postedSessionType'))->invoke($controller));
        self::assertNull((new ReflectionMethod($controller, 'requireConfirmation'))->invoke($controller));
    }

    public static function invalidSessionTypes(): iterable
    {
        foreach ([null, '', 'follow-up', 'REGULAR', ['regular'], true] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('invalidSessionTypes')]
    public function testMalformedSessionTypeIsRejected(mixed $type): void
    {
        $controller = (new ReflectionClass(GuidanceController::class))->newInstanceWithoutConstructor();
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('post', ['session_type' => $type]);
        $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        (new ReflectionMethod($controller, 'postedSessionType'))->invoke($controller);
    }

    private function method(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        return implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    public function testLifecycleWritesKeepLocksGuardsAndExplicitTransitions(): void
    {
        foreach (['closeCase', 'monitorCase'] as $method) {
            $source = $this->method(GuidanceCaseService::class, $method);
            self::assertStringContainsString('$this->transaction(', $source);
            self::assertStringContainsString("'guidance.cases.write', true", $source);
            self::assertStringContainsString('$this->assertNoActiveSessions($caseId)', $source);
            self::assertLessThan(strpos($source, '->update('), strpos($source, 'assertNoActiveSessions'));
        }
        $guard = $this->method(GuidanceCaseService::class, 'assertNoActiveSessions');
        self::assertStringContainsString("status = 'in_progress' AND deleted_at IS NULL", $guard);
        self::assertStringContainsString("['open', 'active']", $this->method(GuidanceCaseService::class, 'monitorCase'));
        $miss = $this->method(GuidanceCaseService::class, 'missFollowup');
        self::assertStringContainsString("'missed'", $miss);
        $transition = $this->method(GuidanceCaseService::class, 'transitionFollowup');
        self::assertStringNotContainsString('due_date', $transition);
        self::assertStringContainsString("\$followup['status'] !== 'pending'", $transition);
        self::assertStringContainsString("'completed_at' => \$status === 'completed' ? date('Y-m-d H:i:s') : null", $transition);
    }

    public function testHistoryAuthorizesBeforeCiphertextAndAuditsBeforeReturning(): void
    {
        $source = $this->method(GuidanceCaseService::class, 'getDecryptedSessionNoteHistory');
        self::assertLessThan(strpos($source, 'getVersionHistory('), strpos($source, 'authorizedCase('));
        self::assertLessThan(strpos($source, 'return $history'), strpos($source, "'note_history_read'"));
        self::assertStringNotContainsString('log_message', $source);
        self::assertStringNotContainsString('cache(', $source);
        $authority = $this->method(GuidanceCaseService::class, 'authorizedCase');
        foreach (['EngagementAccess::actor', "\$case['tenant_id']", "\$case['counsellor_user_id']", 'guidance.cases.read_any', '$this->person('] as $guard) {
            self::assertStringContainsString($guard, $authority);
        }
        $audit = $this->method(GuidanceCaseService::class, 'audit');
        self::assertStringContainsString('(string) $id, $action, [],', $audit);
        self::assertStringNotContainsString('text', $audit);
        self::assertStringContainsString('no-store', $this->method(GuidanceController::class, 'readSessionNoteHistory'));
    }

    public function testQueuePickerDoesNotMutateAndIntakePreservesLinks(): void
    {
        $source = $this->method(GuidanceController::class, 'startForTicket');
        self::assertStringContainsString('/guidance/cases/choose-for-ticket/', $source);
        self::assertStringContainsString('$this->postedSessionType()', $source);
        $picker = $this->method(GuidanceCaseService::class, 'getTicketForSessionSelection');
        foreach (['->insert(', '->update(', 'startConsultation('] as $mutation) {
            self::assertStringNotContainsString($mutation, $picker);
        }
        $intake = $this->method(GuidanceCaseService::class, 'openSessionForTicket');
        self::assertSame(2, substr_count($intake, '$counsellorUserId, $sessionType, $queueEntryId'));
        $open = $this->method(GuidanceCaseService::class, 'openSession');
        self::assertStringContainsString("'appointment_id' => \$appointmentId, 'queue_entry_id' => \$queueEntryId", $open);
        self::assertStringContainsString('return $existing;', $open);
    }
}
