<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class AuditExplorerViewTest extends CIUnitTestCase
{
    private function render(array $data = []): string
    {
        helper(['url', 'form']);
        $template = new class {
            public function extend(string $layout): string { return ''; }
            public function section(string $name): string { return ''; }
            public function endSection(): string { return ''; }
            public function render(array $data): string
            {
                extract($data);
                ob_start();
                try {
                    include APPPATH . 'Views/admin/audit.php';
                    return ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            }
        };
        return $template->render(array_replace(['events' => []], $data));
    }

    public function testFilterFormResetsCursorAndEscapesValuesAndErrors(): void
    {
        $html = $this->render([
            'filters' => ['entity_id' => '"><script>alert(1)</script>', 'per_page' => '100'],
            'filterErrors' => ['<script>bad filter</script>'],
        ]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('bad filter', $html);
        $this->assertStringContainsString('value="100" selected', $html);
        $this->assertStringContainsString('method="GET"', $html);
        foreach (['event_type', 'action', 'entity_type', 'entity_id', 'actor_user_id', 'from', 'to', 'per_page'] as $key) {
            $this->assertStringContainsString('name="' . $key . '"', $html);
        }
        $this->assertStringNotContainsString('name="cursor"', $html);
        $this->assertStringNotContainsString('name="direction"', $html);
        $this->assertStringContainsString('were not loaded', $html);
        $this->assertStringNotContainsString('No audit events recorded yet.', $html);
    }

    public function testCompactFiltersUseCustomDatepickersWithIsoSubmissionValues(): void
    {
        $html = $this->render(['filters' => ['from' => '2026-10-01', 'to' => '2026-10-26']]);
        $this->assertStringNotContainsString('type="date"', $html);
        $this->assertSame(2, substr_count($html, 'data-datepicker data-target='));
        foreach (['from' => '2026-10-01', 'to' => '2026-10-26'] as $key => $value) {
            $this->assertStringContainsString('data-target="#audit-' . $key . '"', $html);
            $this->assertStringContainsString('type="hidden" id="audit-' . $key . '" name="' . $key . '" value="' . $value . '"', $html);
            $this->assertStringContainsString('for="audit-' . $key . '-display"', $html);
        }
        $this->assertStringContainsString('col-lg-3', $html);
        $this->assertStringContainsString('Text and ID filters use exact matches.', $html);
    }

    public function testEmptyLedgerFiltersAndOutOfRangePageHaveDistinctMessages(): void
    {
        $this->assertStringContainsString('No audit events recorded yet.', $this->render());
        foreach ([['filters' => ['action' => 'create']], ['hasCursor' => true]] as $data) {
            $html = $this->render($data);
            $this->assertStringContainsString('No audit events match the current filters or page.', $html);
            $this->assertStringNotContainsString('No audit events recorded yet.', $html);
        }
    }

    public function testPaginationLinksPreserveUrlAndVerificationScopeIsExplicit(): void
    {
        $html = $this->render(['pagination' => [
            'first_url' => '/admin/audit?action=create&per_page=25',
            'older_url' => '/admin/audit?action=create&cursor=18446744073709551615',
            'newer_url' => null,
        ]]);
        $this->assertStringContainsString('cursor=18446744073709551615', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        $this->assertStringNotContainsString('action=create&cursor=', $html);
        $this->assertStringContainsString('aria-disabled="true">Newer', $html);
        $this->assertStringContainsString('Latest matching events', $html);
        $this->assertStringContainsString('whole tenant chain, not the current filtered page', $html);
        $this->assertStringContainsString('No external checkpoint', $html);
    }

    public function testRoleDetailsRemainEscapedAndGeneralPayloadsAreNotExposed(): void
    {
        $event = ['id' => '18446744073709551615', 'event_type' => 'access.role_changed',
            'action' => 'assign_role', 'entity_type' => 'user', 'entity_id' => '9',
            'actor_name' => '<script>actor</script>', 'actor_ip' => '127.0.0.1',
            'prev_hash' => str_repeat('0', 64), 'curr_hash' => str_repeat('a', 64),
            'occurred_at' => '2026-10-23 12:00:00',
            'payload_json' => json_encode(['previous_role' => 'staff', 'new_role' => 'admin',
                'reason' => '<script>reason</script>', 'secret' => 'never-display-sentinel'])];
        $html = $this->render(['events' => [$event]]);
        $this->assertStringContainsString('#18446744073709551615', $html);
        $this->assertStringContainsString('Role change details', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('never-display-sentinel', $html);
        $this->assertStringContainsString('1 events shown', $html);
    }
}
