<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;

/** DB-free rendering of both queue units, including HTMX's board fragment. */
final class GuidanceAuditQueueIntegrationTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        session()->remove('user');
    }

    private function board(string $unit, mixed $actor): DOMXPath
    {
        session()->set('user', $actor);
        $summary = ['counts' => ['waiting' => 1, 'called' => 1, 'in_consultation' => 1,
            'done' => 0, 'skipped' => 1, 'total' => 4], 'done' => []];
        foreach (['waiting' => 11, 'called' => 12, 'in_consultation' => 13, 'skipped' => 14] as $state => $id) {
            $summary[$state] = [[
                'id' => $id, 'ticket_code' => 'FIXTURE-' . $id, 'first_name' => '<script>Private</script>',
                'last_name' => 'Fixture', 'mis_id' => 'SYNTHETIC-' . $id, 'priority' => 'routine',
                'queue_date' => '2099-01-01', 'called_at' => '2099-01-01 10:00:00',
                'started_at' => $state === 'in_consultation' ? '2099-01-01 10:05:00' : null,
                'skip_until' => '2099-01-01 11:00:00',
            ]];
        }
        $html = view('queue/board_partial', ['unit' => $unit, 'summary' => $summary], ['saveData' => false]);
        self::assertStringNotContainsString('<script>Private</script>', $html);
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML($html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($dom);
    }

    public static function guidanceActors(): iterable
    {
        yield 'counselor' => [['role' => 'counsellor', 'permissions' => ['guidance.cases.create']], true];
        yield 'superadmin' => [['role' => 'superadmin', 'permissions' => []], true];
        yield 'queue manager only' => [['role' => 'guidance_staff', 'permissions' => ['guidance.queue.manage']], false];
        yield 'case reader only' => [['role' => 'counsellor', 'permissions' => ['guidance.cases.read']], false];
        yield 'oversight alone' => [['role' => 'guidance_supervisor', 'permissions' => ['guidance.cases.read_any']], false];
        yield 'signed out' => [null, false];
        yield 'malformed identity' => ['invalid', false];
    }

    #[DataProvider('guidanceActors')]
    public function testGuidanceChartAndStartUsePermissionGatedReadOnlyModalLinks(mixed $actor, bool $visible): void
    {
        $xpath = $this->board('guidance', $actor);
        $links = $xpath->query('//a[@data-app-modal-open]');
        self::assertCount($visible ? 2 : 0, $links);
        if ($visible) {
            foreach ([12 => 'Start Visit', 13 => 'Open Chart'] as $id => $label) {
                $link = $xpath->query('//a[@data-app-modal-open and contains(@href, "/guidance/cases/choose-for-ticket/' . $id . '")]');
                self::assertCount(1, $link);
                self::assertSame($label, trim($link->item(0)->textContent));
                foreach (['hx-post', 'hx-get', 'hx-target', 'hx-swap'] as $attribute) {
                    self::assertFalse($link->item(0)->hasAttribute($attribute));
                }
            }
        }
        self::assertCount(0, $xpath->query('//*[@hx-post and contains(@hx-post, "/guidance/queue/start/")]'));
        self::assertCount(0, $xpath->query('//form[contains(@action, "/guidance/cases/start-for-ticket/")]'));
        self::assertCount(0, $xpath->query('//a[contains(@href, "/choose-for-ticket/11") or contains(@href, "/choose-for-ticket/14")]'));
        $this->assertBoardActions($xpath, 'guidance');
    }

    public function testClinicKeepsItsOriginalHtmxStartAndCsrfProtectedChartPost(): void
    {
        $xpath = $this->board('clinic', ['role' => 'nurse', 'permissions' => []]);
        self::assertCount(0, $xpath->query('//a[@data-app-modal-open]'));
        self::assertCount(0, $xpath->query('//*[contains(@href, "/guidance/")]'));
        $start = $xpath->query('//button[contains(@hx-post, "/clinic/queue/start/12") and @hx-target="#queue-board-container" and @hx-swap="innerHTML"]');
        self::assertCount(1, $start);
        $form = $xpath->query('//form[contains(@action, "/clinic/encounters/start-for-ticket/13") and @method="POST"]');
        self::assertCount(1, $form);
        self::assertCount(1, $xpath->query('.//input[@type="hidden" and @name="' . csrf_token() . '"]', $form->item(0)));
        self::assertCount(1, $xpath->query('.//button[@type="submit"]', $form->item(0)));
        $this->assertBoardActions($xpath, 'clinic');
    }

    private function assertBoardActions(DOMXPath $xpath, string $unit): void
    {
        foreach (['call/11', 'skip/12', 'complete/13', 'recall/14'] as $action) {
            self::assertCount(1, $xpath->query('//button[contains(@hx-post, "/' . $unit . '/queue/' . $action . '") and @hx-target="#queue-board-container" and @hx-swap="innerHTML"]'));
        }
    }
}
