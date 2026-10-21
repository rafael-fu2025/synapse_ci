<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Guidance\GuidanceCaseService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Render only the scoped templates, without layouts, filters, or a database. */
final class GuidanceAuditViewTest extends CIUnitTestCase
{
    private function renderTemplate(string $relativePath, array $data): string
    {
        $renderer = new class {
            public function extend(string $layout): string { return ''; }
            public function section(string $section): string { return ''; }
            public function endSection(): string { return ''; }
            public function render(string $path, array $data): string
            {
                extract($data, EXTR_SKIP);
                ob_start();
                try {
                    include $path;
                    return ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            }
        };
        return $renderer->render(APPPATH . 'Views/guidance/' . $relativePath, $data);
    }

    public static function surveyActors(): iterable
    {
        yield 'superadmin convention' => ['superadmin', [], true];
        foreach (['guidance_admin', 'guidance_supervisor'] as $role) {
            yield $role . ' granted' => [$role, ['guidance.surveys.manage'], true];
            yield $role . ' revoked' => [$role, [], false];
        }
        foreach (['counsellor', 'guidance_staff', 'student', 'admin'] as $role) {
            yield $role . ' permission alone insufficient' => [$role, ['guidance.surveys.manage'], false];
        }
    }

    #[DataProvider('surveyActors')]
    public function testSurveyLinkRequiresBothRoleAndManageGrant(string $role, array $permissions, bool $visible): void
    {
        $html = $this->renderTemplate('dashboard.php', [
            'user' => ['role' => $role, 'permissions' => $permissions],
            'activeCases' => 0, 'closedCases' => 0, 'completedSessions' => 0,
            'queueToday' => 0, 'pendingReferrals' => 0, 'recentCases' => [],
            'chartDays' => [], 'chartSeries' => [],
                        'categoryCounts' => array_fill_keys(['academic', 'personal', 'career', 'crisis', 'behavioral', 'family'], 0),
        ]);
        self::assertSame($visible, str_contains($html, 'Campus Guidance Surveys'));
    }

    public function testHistoryEscapesPlaintextAndAuthorAndHandlesEmptyHistory(): void
    {
        $html = $this->renderTemplate('sessions/history.php', [
            'history' => [[
                'version' => 2, 'created_at' => '2026-01-01',
                'author_name' => '<script>author</script>', 'text' => '<script>private note</script>',
            ]], 'cancelUrl' => '/guidance/cases',
        ]);
        self::assertStringContainsString('Version 2', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString(esc('<script>private note</script>'), $html);
        self::assertStringContainsString('No finalized versions', $this->renderTemplate('sessions/history.php', [
            'history' => [], 'cancelUrl' => '/guidance/cases',
        ]));
    }

    private function workspace(array $overrides = []): string
    {
        return $this->renderTemplate('cases/view.php', array_replace([
            'case' => ['id' => 10, 'person_id' => 1, 'status' => 'active', 'case_number' => 'FIXTURE-10',
                'title' => 'Synthetic case', 'category' => 'personal', 'opened_at' => '2026-01-01'],
            'package' => ['student' => null, 'employee' => null,
                'patient' => ['first_name' => 'Private', 'last_name' => 'Fixture', 'mis_id' => 'FIXTURE-1'],
                'sessions' => [], 'followups' => [
                    ['id' => 5, 'status' => 'pending', 'action_plan' => 'Synthetic plan', 'due_date' => '2020-01-01'],
                    ['id' => 6, 'status' => 'missed', 'action_plan' => 'Synthetic missed plan', 'due_date' => '2020-01-01'],
                ]],
            'activeSession' => null, 'decryptedNote' => null,
        ], $overrides));
    }

    public function testWorkspaceHasExplicitLifecycleFormsAndNeverInfersMissedFromDate(): void
    {
        $html = $this->workspace();
        foreach (['/guidance/cases/10/close', '/guidance/cases/10/monitor', '/guidance/followups/5/missed'] as $action) {
            self::assertStringContainsString($action, $html);
        }
        self::assertSame(3, substr_count($html, 'name="confirmed" value="1" required'));
        self::assertStringContainsString('Pending', $html);
        self::assertStringNotContainsString('/guidance/followups/6/missed', $html);
        self::assertStringNotContainsString('/guidance/followups/6/complete', $html);
        foreach (GuidanceCaseService::SESSION_TYPES as $type) {
            self::assertStringContainsString('value="' . $type . '"', $html);
        }
        $html = $this->workspace(['activeSession' => [
            'id' => 20, 'session_number' => 1, 'session_type' => 'regular', 'started_at' => '2026-01-01 12:00:00',
        ]]);
        self::assertStringNotContainsString('/guidance/cases/10/close', $html);
        self::assertStringNotContainsString('/guidance/cases/10/monitor', $html);
        self::assertStringContainsString('/guidance/sessions/20/close', $html);
    }

    public function testWorkspaceSeparatesNavigationFromStateChangesAndHonorsClosedCases(): void
    {
        $html = $this->workspace();
        self::assertStringContainsString('gc-session-form', $html);
        self::assertStringContainsString('data-bs-target="#case-status-actions"', $html);
        self::assertGreaterThan(strpos($html, 'Case workspace'), strpos($html, '/guidance/cases/10/session'));
        self::assertStringContainsString('role="tab"', $html);
        self::assertStringContainsString('role="tabpanel"', $html);
        self::assertStringNotContainsString('Student Commitments', $html);
        $html = $this->workspace(['case' => ['id' => 10, 'person_id' => 1, 'status' => 'closed',
            'case_number' => 'FIXTURE-10', 'title' => 'Synthetic case', 'category' => 'personal', 'opened_at' => '2026-01-01']]);
        foreach (['/guidance/cases/10/session', '/guidance/cases/10/monitor', '/guidance/cases/10/close'] as $action) {
            self::assertStringNotContainsString($action, $html);
        }
        self::assertStringContainsString('This case is closed', $html);
    }

    public function testQueuePickerHasAllTypesCsrfAndOriginalTicketAction(): void
    {
        $html = $this->renderTemplate('sessions/start.php', [
            'queueEntryId' => 37, 'formAction' => '/guidance/cases/start-for-ticket/37',
            'cancelUrl' => '/guidance/queue',
        ]);
        foreach (GuidanceCaseService::SESSION_TYPES as $type) {
            self::assertStringContainsString('value="' . $type . '"', $html);
        }
        self::assertStringContainsString('/guidance/cases/start-for-ticket/37', $html);
        self::assertStringContainsString('data-modal-submit', $html);
        self::assertStringContainsString('method="POST"', $html);
        self::assertStringContainsString(csrf_field(), $html);
        self::assertStringNotContainsString('name="appointment_id"', $html);
        self::assertStringNotContainsString('name="person_id"', $html);
    }
}
