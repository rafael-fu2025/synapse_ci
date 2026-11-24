<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuthenticatedUser;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use DOMDocument;
use DOMXPath;

/** Real templates under the guarded no-dotenv runtime; no operational services or writes. */
final class ResourceHistoryPaginationViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        if (!defined('PAGINATION_ISOLATED_TESTS') || PAGINATION_ISOLATED_TESTS !== true) {
            $this->markTestSkipped('Use the guarded pagination-isolated-bootstrap.php.');
        }
        parent::setUp();
        helper(['form', 'url']);
        AuthenticatedUser::clear();
        service('request')->setGlobal('get', []);
        Services::resetSingle('renderer');
    }

    protected function tearDown(): void
    {
        service('request')->setGlobal('get', []);
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        parent::tearDown();
    }

    private function render(string $view, array $data): string
    {
        Services::resetSingle('renderer');
        // Layouts reuse the outer data after nested partials; each fixture gets a fresh renderer.
        return view($view, $data, ['saveData' => true]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $document->loadHTML('<?xml encoding="utf-8" ?>' . $html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new DOMXPath($document);
    }

    private function metadata(int $page, int $size, int $total): array
    {
        return ['page' => $page, 'per_page' => $size, 'total' => $total, 'filtered' => $total,
            'pages' => max(1, (int) ceil($total / $size)),
            'from' => $total === 0 ? 0 : ($page - 1) * $size + 1, 'to' => min($page * $size, $total)];
    }

    private function assertPager(DOMXPath $xpath, string $label, string $pageParam, string $sizeParam, array $state, ?string $fragment = null, bool $modal = false): void
    {
        $links = $xpath->query('//nav[@aria-label="' . $label . '"]//a');
        $this->assertGreaterThan(0, $links->length, $label);
        foreach ($links as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            $expected = array_map('strval', $state);
            $expected[$pageParam] = $link->getAttribute('data-table-page');
            $this->assertEquals($expected, $query);
            $this->assertSame((string) $state[$sizeParam], $query[$sizeParam]);
            $this->assertSame($fragment, parse_url($link->getAttribute('href'), PHP_URL_FRAGMENT));
            $this->assertSame($modal, $link->hasAttribute('data-app-modal-page'));
            $this->assertFalse($link->hasAttribute('data-filter-link'));
        }
    }

    private function taskFixture(): array
    {
        return [
            'unit' => 'clinic', 'baseUrl' => site_url('/clinic/teams'), 'cancelUrl' => site_url('/clinic/teams'),
            'title' => 'Team task #11', 'memberNames' => [1 => 'Reader <script>name</script>'],
            'statuses' => ['todo' => 'To do', 'in_progress' => 'In progress', 'blocked' => 'Blocked', 'done' => 'Done'],
            'priorities' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'],
            'task' => [
                'id' => 11, 'assignee_user_id' => 1, 'created_by_user_id' => 1, 'canEdit' => true, 'version' => 1,
                'title' => 'Task <script>title</script>', 'description' => '', 'checklist' => [], 'due_date' => null,
                'status' => 'todo', 'priority' => 'normal',
                'comments_total' => 121, 'comments_limit' => 50, 'activity_total' => 76, 'activity_limit' => 25,
                'commentsPagination' => $this->metadata(3, 50, 121), 'activityPagination' => $this->metadata(2, 25, 76),
                'comments' => [['actor_user_id' => 1, 'body' => 'Older <script>comment</script>', 'created_at' => '2026-11-01 00:00:00']],
                'activity' => [['actor_user_id' => 1, 'event' => 'updated', 'old_status' => null, 'new_status' => null, 'created_at' => '2026-11-01 00:00:00']],
            ],
        ];
    }

    public function testTaskModalHasIndependentOptInPagersWithoutHardCapClaimsOrChangedPosts(): void
    {
        service('request')->setGlobal('get', ['fragment' => '1']);
        $html = $this->render('teams/show', $this->taskFixture());
        $xpath = $this->xpath($html);
        $state = ['comments_page' => 3, 'comments_per_page' => 50, 'activity_page' => 2, 'activity_per_page' => 25];
        $this->assertPager($xpath, 'Task discussion pages', 'comments_page', 'comments_per_page', $state, null, true);
        $this->assertPager($xpath, 'Task activity pages', 'activity_page', 'activity_per_page', $state, null, true);
        $this->assertStringContainsString('Showing 101–121 of 121', $html);
        $this->assertStringContainsString('Showing 26–50 of 76', $html);
        $this->assertStringNotContainsString('Showing the latest', $html);
        $this->assertStringNotContainsString('Latest 25 entries', $html);
        $this->assertStringNotContainsString('<script>comment</script>', $html);
        $this->assertCount(1, $xpath->query('//details[contains(@class,"tws-activity") and @open]'));
        $this->assertCount(2, $xpath->query('//form[@method="post" and @data-modal-submit]'));
        $this->assertCount(0, $xpath->query('//form//*[@name="comments_page" or @name="activity_page"]'));
        foreach ($xpath->query('//form[@method="post"]') as $form) {
            $this->assertNull(parse_url($form->getAttribute('action'), PHP_URL_QUERY));
        }
    }

    private function facilitiesFixture(): array
    {
        return [
            'title' => 'Drum operations', 'unit' => ['id' => 11, 'unit_code' => 'DRUM-11', 'name' => 'Fixture drum', 'status' => 'active', 'drum_capacity_kg' => '80.00'],
            'schedule' => ['version' => 4, 'enabled' => false, 'times' => [], 'rotations' => 7, 'pwm' => null],
            'device' => null, 'canManage' => true, 'canConfigure' => false, 'canHardware' => false,
            'canUsb' => false, 'hardwareEnabled' => false, 'hardware_enabled' => false,
            'activeBatch' => ['id' => 500, 'batch_code' => 'ACTIVE-500', 'status' => 'processing', 'total_input_weight_kg' => '10.00', 'started_at' => '2026-11-01 00:00:00', 'notes' => 'Current batch'],
            'commands' => [['created_at' => '2026-11-01 00:00:00', 'command' => 'stop', 'status' => 'pending', 'expires_at' => '2026-11-01 00:01:00', 'ack_message' => '<script>ack</script>']],
            'runs' => [['occurred_at' => '2026-11-01 00:00:00', 'motor_state' => 'stopped', 'rotations' => 7, 'created_at' => '2026-11-01 00:00:30']],
            'pastBatches' => [['batch_code' => 'PAST-10', 'status' => 'released', 'total_input_weight_kg' => '9.00', 'started_at' => '2026-10-01 00:00:00', 'completed_at' => '2026-10-02 00:00:00', 'notes' => '<script>batch</script>']],
            'commandsPagination' => $this->metadata(3, 20, 65), 'runsPagination' => $this->metadata(2, 25, 76),
            'batchesPagination' => $this->metadata(2, 50, 111),
        ];
    }

    public function testDrumPagersRemainOutsideCurrentBatchAndPreserveEverySiblingCursor(): void
    {
        $html = $this->render('facilities/unit_detail', $this->facilitiesFixture());
        $xpath = $this->xpath($html);
        $state = ['commands_page' => 3, 'commands_per_page' => 20, 'runs_page' => 2, 'runs_per_page' => 25, 'batches_page' => 2, 'batches_per_page' => 50];
        $this->assertPager($xpath, 'Drum command history pages', 'commands_page', 'commands_per_page', $state, 'bmg-history');
        $this->assertPager($xpath, 'Drum motor event pages', 'runs_page', 'runs_per_page', $state, 'bmg-history');
        $this->assertPager($xpath, 'Drum past batch pages', 'batches_page', 'batches_per_page', $state, 'bmg-history');
        $this->assertCount(0, $xpath->query('//*[@id="bmg-batch"]//*[@data-table-pagination]'));
        $this->assertCount(3, $xpath->query('//*[@id="bmg-history"]//*[@data-table-pagination]'));
        $this->assertCount(1, $xpath->query('//form[contains(@action,"/facilities/batch/500/release")]'));
        $this->assertCount(1, $xpath->query('//form[contains(@action,"/facilities/batch/500/cancel")]'));
        $this->assertCount(0, $xpath->query('//*[@id="startBatchModal"]'));
        $this->assertStringContainsString('ACTIVE-500', $html);
        $this->assertStringNotContainsString('Latest 20', $html);
        $this->assertStringNotContainsString('Latest 50', $html);
        $this->assertStringNotContainsString('<script>ack</script>', $html);
        $this->assertStringContainsString('Status and device acknowledgements do not verify physical movement.', $html);
        $root = $xpath->query('//*[@data-refresh-owner="facilities.unit.11"]')->item(0);
        parse_str((string) parse_url($root->getAttribute('data-refresh-url'), PHP_URL_QUERY), $query);
        $this->assertEquals(array_map('strval', $state), $query);
    }

    private function inventoryFixture(): array
    {
        $operation = ['id' => 11, 'type' => 'reversal', 'created_at' => '2026-11-01 00:00:00', 'encounter_id' => null,
            'reason' => '<script>reason</script>', 'actor_id' => 1, 'actor_name' => 'Fixture reader',
            'reversal_mode' => 'record_only', 'reversal_of' => 999, 'reversed_by_operation_id' => null, 'correction_mode' => null];
        return [
            'title' => 'Medicine details', 'canManage' => true, 'canWrite' => true,
            'medicine' => ['id' => 11, 'is_active' => 1, 'name' => 'Fixture medicine', 'unit_of_measure' => 'tablet',
                'minimum_stock_level' => 2, 'usable_stock' => 10, 'total_stock' => 10, 'expired_stock' => 0,
                'quarantined_stock' => 0, 'near_expiry_stock' => 0],
            'batches' => [], 'operations' => [$operation, array_replace($operation, ['id' => 12, 'type' => 'dispense', 'reversed_by_operation_id' => 998])],
            'movements' => [['id' => 21, 'delta' => 0, 'created_at' => '2026-11-01 00:00:00', 'batch_id' => 31,
                'actor_id' => null, 'reason' => 'Record only', 'operation_id' => 997, 'original_movement_id' => 999]],
            'movementPagination' => $this->metadata(2, 50, 141), 'operationsPagination' => $this->metadata(3, 100, 231),
        ];
    }

    public function testInventoryOperationAndMovementLinksAndSizeFormKeepIndependentState(): void
    {
        $html = $this->render('inventory/show', $this->inventoryFixture());
        $xpath = $this->xpath($html);
        $state = ['page' => 2, 'per_page' => 50, 'operations_page' => 3, 'operations_per_page' => 100];
        $this->assertPager($xpath, 'Inventory operation pages', 'operations_page', 'operations_per_page', $state, 'inventory-operations');
        $this->assertPager($xpath, 'Stock movement pages', 'page', 'per_page', $state, 'inventory-movements');
        $this->assertCount(1, $xpath->query('//form[@method="get"]//input[@name="operations_page" and @value="3"]'));
        $this->assertCount(1, $xpath->query('//form[@method="get"]//input[@name="operations_per_page" and @value="100"]'));
        $this->assertCount(0, $xpath->query('//form[@method="get"]//*[@name="page"]'));
        $this->assertCount(1, $xpath->query('//select[@name="per_page"]//option[@value="50" and @selected]'));
        $this->assertStringContainsString('Showing 201–231 of 231', $html);
        $this->assertStringContainsString('Showing 51–100 of 141', $html);
        $this->assertStringNotContainsString('Latest 100', $html);
        $this->assertStringNotContainsString('outside latest 100', $html);
        $this->assertStringContainsString('on another operations page', $html);
        $this->assertStringNotContainsString('<script>reason</script>', $html);
        $this->assertStringContainsString('Legacy allocations with a null operation ID cannot be corrected.', $html);
        $this->assertCount(0, $xpath->query('//a[@href="#inventory-operation-997" or @href="#inventory-operation-998" or @href="#inventory-operation-999"]'));
        $root = $xpath->query('//*[@data-refresh-owner="inventory.detail.11"]')->item(0);
        parse_str((string) parse_url($root->getAttribute('data-refresh-url'), PHP_URL_QUERY), $query);
        $this->assertEquals(array_map('strval', $state), $query);
    }

    public function testEmptyHistoriesHaveZeroRangesAndDisabledBoundaries(): void
    {
        service('request')->setGlobal('get', ['fragment' => '1']);
        $task = $this->taskFixture();
        foreach (['comments', 'activity'] as $kind) {
            $task['task'][$kind] = [];
            $task['task'][$kind . '_total'] = 0;
            $task['task'][$kind . 'Pagination'] = $this->metadata(1, 50, 0);
        }
        $html = $this->render('teams/show', $task);
        $this->assertSame(2, substr_count($html, 'Showing 0–0 of 0'));
        $this->assertCount(0, $this->xpath($html)->query('//*[@data-table-pagination]//a'));
        service('request')->setGlobal('get', []);
        $facilities = $this->facilitiesFixture();
        foreach (['commands' => 20, 'runs' => 20, 'batches' => 50] as $kind => $size) {
            $facilities[$kind === 'batches' ? 'pastBatches' : $kind] = [];
            $facilities[$kind . 'Pagination'] = $this->metadata(1, $size, 0);
        }
        $html = $this->render('facilities/unit_detail', $facilities);
        $this->assertSame(3, substr_count($html, 'Showing 0–0 of 0'));
        $this->assertCount(0, $this->xpath($html)->query('//*[@id="bmg-history"]//nav//a'));
        $inventory = $this->inventoryFixture();
        $inventory['operations'] = $inventory['movements'] = [];
        $inventory['operationsPagination'] = $this->metadata(1, 100, 0);
        $inventory['movementPagination'] = $this->metadata(1, 100, 0);
        $html = $this->render('inventory/show', $inventory);
        $this->assertSame(2, substr_count($html, 'Showing 0–0 of 0'));
        $this->assertCount(0, $this->xpath($html)->query('//*[@data-table-pagination]//a'));
    }
}
