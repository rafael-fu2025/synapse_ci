<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\TablePage;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\View\View;
use Config\Services;
use DOMDocument;
use DOMElement;
use DOMXPath;

/** Registry sections and the real shared pager, with no layout/application database reads. */
final class GuidanceRegistryPaginationViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        if (!defined('PAGINATION_ISOLATED_TESTS') || PAGINATION_ISOLATED_TESTS !== true) {
            self::markTestSkipped('Requires the no-dotenv pagination-isolated-bootstrap.php.');
        }
        parent::setUp();
        helper(['form', 'url']);
        $request = new IncomingRequest(config('App'), new SiteURI(config('App'), 'guidance'), null, new UserAgent());
        Services::injectMock('request', $request);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('renderer');
        Services::resetSingle('request');
        parent::tearDown();
    }

    private function render(string $name, array $data = []): array
    {
        $renderer = new GuidanceRegistryPaginationSectionView(config('View'), APPPATH . 'Views', null, false);
        Services::injectMock('renderer', $renderer);
        $buffers = ob_get_level();
        try {
            $html = view($name, $data, ['saveData' => false]);
        } finally {
            while (ob_get_level() > $buffers) {
                ob_end_clean();
            }
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return [$html, new DOMXPath($document)];
    }

    private function metadata(int $total = 125, int $filtered = 63, string $page = '2', string $size = '20'): array
    {
        return TablePage::forList(['page' => $page, 'per_page' => $size])->metadata($total, $filtered);
    }

    private function query(DOMElement $anchor): array
    {
        parse_str((string) parse_url($anchor->getAttribute('href'), PHP_URL_QUERY), $query);
        return $query;
    }

    private function assertSizeOptions(DOMXPath $xpath, string $formSelector, string $selected = '20'): void
    {
        $options = $xpath->query($formSelector . '//select[@name="per_page"]/option');
        self::assertCount(4, $options);
        self::assertSame(['20', '25', '50', '100'], array_map(static fn (DOMElement $option): string => $option->getAttribute('value'), iterator_to_array($options)));
        self::assertCount(1, $xpath->query($formSelector . '//select[@name="per_page"]/option[@value="' . $selected . '" and @selected]'));
        self::assertCount(0, $xpath->query($formSelector . '//select[@name="per_page" and @data-native-select]'));
        self::assertCount(0, $xpath->query($formSelector . '[contains(concat(" ",normalize-space(@class)," ")," ce-toolbar-card ")]'));
    }

    private function assertPagerLinks(DOMXPath $xpath, string $selector, string $pageParam, array $expected, bool $live = true): void
    {
        $links = $xpath->query($selector . '//a');
        self::assertGreaterThan(0, $links->length);
        foreach ($links as $anchor) {
            $query = $this->query($anchor);
            $expectedQuery = $expected + [$pageParam => $anchor->getAttribute('data-table-page')];
            ksort($query);
            ksort($expectedQuery);
            self::assertSame($expectedQuery, $query);
            self::assertSame($live, $anchor->hasAttribute('data-filter-link'));
            self::assertNull(parse_url($anchor->getAttribute('href'), PHP_URL_FRAGMENT));
        }
    }

    public function testCallSlipPagerKeepsStatusPrefillCanonicalCursorAndMutationContracts(): void
    {
        [$html, $xpath] = $this->render('guidance/call_slips/index', [
            'callSlips' => [['id' => 42, 'person_id' => 101, 'first_name' => 'Synthetic', 'last_name' => 'Subject',
                'attendance_status' => 'pending', 'call_date' => '2026-11-20', 'call_time' => '09:00:00']],
            'status' => 'pending', 'prefillPerson' => ['id' => 101, 'first_name' => 'Synthetic', 'last_name' => 'Subject'],
            'prefillEpisodeId' => 77, 'pagination' => $this->metadata(),
        ]);
        self::assertStringContainsString('Showing 21–40 of 63', $html);
        self::assertStringContainsString('(125 total records)', $html);
        self::assertStringNotContainsString('Latest 100', $html);
        self::assertCount(1, $xpath->query('//*[@data-filter-region="list" and @data-refresh-region="list"]//*[@data-table-pagination]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//input[@name="page" and @value="2"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//input[@name="person_id" and @value="101"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//input[@name="episode_id" and @value="77"]'));
        $this->assertSizeOptions($xpath, '//*[@data-filter-form]');
        $this->assertPagerLinks($xpath, '//*[@data-table-pagination]', 'page', ['person_id' => '101', 'episode_id' => '77', 'status' => 'pending', 'per_page' => '20']);
        foreach ($xpath->query('//*[@data-filter-region="tabs"]//a') as $tab) {
            $query = $this->query($tab);
            self::assertSame('101', $query['person_id']);
            self::assertSame('77', $query['episode_id']);
            self::assertSame('20', $query['per_page']);
            self::assertArrayNotHasKey('page', $query);
        }
        self::assertCount(1, $xpath->query('//*[@data-filter-region="list"]//form[@data-background-submit and contains(@action,"/call-slips/42/status")]'));
        self::assertCount(1, $xpath->query('//*[@id="issueCallSlipModal"]//form[@data-background-submit]'));
        self::assertCount(1, $xpath->query('//*[@id="callSlipEpisodeId" and @value="77"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-region="list"]//*[contains(@class,"ce-tag") and normalize-space(.)="63 matching"]'));
        self::assertCount(0, $xpath->query('//*[@data-filter-region]//*[@data-filter-form]'));
        self::assertCount(1, $xpath->query('//nav[@data-filter-region="tabs" and @data-refresh-region="tabs"]/following-sibling::form[@data-filter-form]'));
    }

    public function testEpisodePagerAndTabsUseFullStatusCountsAndKeepOpenModal(): void
    {
        [$html, $xpath] = $this->render('guidance/episodes/index', [
            'episodes' => [['id' => 42, 'status' => 'closed', 'person_id' => 101]], 'status' => 'closed',
            'statusCounts' => ['' => 125, 'open' => 32, 'active' => 31, 'closed' => 31, 'transferred' => 31],
            'pagination' => $this->metadata(125, 31),
        ]);
        self::assertStringContainsString('Showing 21–31 of 31', $html);
        self::assertCount(1, $xpath->query('//*[@data-filter-region="list"]//*[@data-table-pagination]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//input[@name="page" and @value="2"]'));
        $this->assertSizeOptions($xpath, '//*[@data-filter-form]');
        $this->assertPagerLinks($xpath, '//*[@data-table-pagination]', 'page', ['status' => 'closed', 'per_page' => '20']);
        self::assertCount(1, $xpath->query('//*[@data-filter-region="tabs"]//a[@data-filter-reset]//*[contains(@class,"ge-folder-count") and normalize-space(.)="125"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-region="tabs"]//a[@aria-current="page"]//*[contains(@class,"ge-folder-count") and normalize-space(.)="31"]'));
        foreach ($xpath->query('//*[@data-filter-region="tabs"]//a') as $tab) {
            self::assertSame('20', $this->query($tab)['per_page']);
            self::assertArrayNotHasKey('page', $this->query($tab));
        }
        self::assertCount(1, $xpath->query('//*[@data-filter-region="list"]//*[contains(@class,"ce-tag") and normalize-space(.)="31 matching"]'));
        self::assertCount(1, $xpath->query('//*[@id="openEpisodeModal"]//form[@data-background-submit]'));
        self::assertCount(1, $xpath->query('//*[@id="openEpisodeModal"]/*[contains(concat(" ", normalize-space(@class), " "), " modal-dialog-centered ")]'));
        self::assertCount(0, $xpath->query('//*[@data-filter-region]//*[@data-filter-form]'));
        self::assertCount(1, $xpath->query('//nav[@data-filter-region="tabs"]/following-sibling::form[@data-filter-form]'));
    }

    public function testIntakeNamedPagersPreserveOtherCursorFiltersAndWorkspaceDirtyGuard(): void
    {
        [$html, $xpath] = $this->render('guidance/intake/index', [
            'statusFilter' => 'submitted', 'priorityFilter' => 'crisis',
            'statuses' => ['submitted', 'closed'], 'priorityHints' => ['routine', 'urgent', 'crisis'],
            'intakePagination' => $this->metadata(125, 63), 'incomingPagination' => $this->metadata(65, 65, '3'),
        ]);
        self::assertStringContainsString('Showing 21–40 of 63', $html);
        self::assertStringContainsString('Showing 41–60 of 65', $html);
        self::assertCount(1, $xpath->query('//*[@data-live-filters and @data-filter-page-params="intake_page incoming_page" and @data-refresh-owner="guidance.intake.queue"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//input[@name="intake_page" and @value="2"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//input[@name="incoming_page" and @value="3"]'));
        self::assertCount(0, $xpath->query('//*[@data-filter-region]//*[@data-filter-form]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-region="workspace" and @data-refresh-region="workspace"]'));
        self::assertCount(2, $xpath->query('//*[@data-filter-region="workspace"]//*[@data-table-pagination]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-region="workspace"]//*[@id="staffWalkinReferralCard"]//form[@data-background-submit]'));
        self::assertCount(1, $xpath->query('//*[@id="intakeStatusFilter" and contains(@onchange,"intake_page") and not(contains(@onchange,"incoming_page"))]'));
        self::assertCount(1, $xpath->query('//*[@id="intakePerPage" and contains(@onchange,"intake_page") and contains(@onchange,"incoming_page")]'));
        self::assertCount(1, $xpath->query('//form[@data-filter-form and contains(@class,"ce-intake-filter-form")]'));
        self::assertCount(1, $xpath->query('//form[@data-filter-form]/*[contains(@class,"ce-registry-filter-actions")]'));
        self::assertCount(0, $xpath->query('//form[@data-filter-form and contains(@class,"gc-case-filter-form")]'));
        $this->assertSizeOptions($xpath, '//*[@data-filter-form]');
        $this->assertPagerLinks($xpath, '//*[@data-table-pagination and @data-page-param="intake_page"]', 'intake_page',
            ['status' => 'submitted', 'priority_hint' => 'crisis', 'incoming_page' => '3', 'per_page' => '20']);
        $this->assertPagerLinks($xpath, '//*[@data-table-pagination and @data-page-param="incoming_page"]', 'incoming_page',
            ['status' => 'submitted', 'priority_hint' => 'crisis', 'intake_page' => '2', 'per_page' => '20']);
        self::assertCount(1, $xpath->query('//*[@data-filter-region="workspace"]//*[contains(@class,"ce-tag") and normalize-space(.)="63 records"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-region="workspace"]//*[contains(@class,"ce-tag") and normalize-space(.)="65 clinic referrals"]'));
    }

    public function testPractitionerNamedPagersStayInWorkspaceAndSelectorsAreNotPageLimited(): void
    {
        $users = [];
        for ($id = 1; $id <= 65; $id++) {
            $users[] = ['id' => $id, 'username' => 'synthetic-' . $id, 'role' => 'counsellor'];
        }
        [$html, $xpath] = $this->render('guidance/governance/practitioners', [
            'profilesPagination' => $this->metadata(66, 66), 'supervisionPagination' => $this->metadata(63, 63, '3'),
            'guidanceUsers' => $users, 'canManagePractitioners' => true, 'canReassign' => true,
        ]);
        self::assertStringContainsString('Showing 21–40 of 66', $html);
        self::assertStringContainsString('Showing 41–60 of 63', $html);
        self::assertCount(1, $xpath->query('//*[@data-live-filters and @data-filter-page-params="profiles_page supervision_page"]'));
        self::assertCount(0, $xpath->query('//*[@data-filter-region]//*[@data-filter-form]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//input[@name="profiles_page" and @value="2"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//input[@name="supervision_page" and @value="3"]'));
        self::assertCount(2, $xpath->query('//*[@data-filter-region="workspace" and @data-refresh-region="workspace"]//*[@data-table-pagination]'));
        self::assertCount(2, $xpath->query('//*[@data-filter-region="workspace"]//form[@data-background-submit]'));
        self::assertCount(66, $xpath->query('//*[@id="prac-user-id"]/option'));
        self::assertCount(66, $xpath->query('//*[@id="sup-associate"]/option'));
        self::assertCount(66, $xpath->query('//*[@id="sup-supervisor"]/option'));
        self::assertCount(1, $xpath->query('//*[@id="sup-supervisor"]/option[@value="65"]'));
        $this->assertSizeOptions($xpath, '//*[@data-filter-form]');
        $this->assertPagerLinks($xpath, '//*[@data-table-pagination and @data-page-param="profiles_page"]', 'profiles_page',
            ['supervision_page' => '3', 'per_page' => '20']);
        $this->assertPagerLinks($xpath, '//*[@data-table-pagination and @data-page-param="supervision_page"]', 'supervision_page',
            ['profiles_page' => '2', 'per_page' => '20']);
        self::assertCount(1, $xpath->query('//*[contains(@class,"gpr-count") and normalize-space(.)="66 Profiles"]'));
        self::assertCount(1, $xpath->query('//*[contains(@class,"gpr-count") and normalize-space(.)="63 Active"]'));
    }

    public function testCosignMetricsUseFullCountsAndOnlyAssignedQualifiedRowsOfferCosign(): void
    {
        [$html, $xpath] = $this->render('guidance/clinical/cosign_queue', [
            'actor' => ['id' => 11], 'canCoSign' => true, 'hasOversight' => true,
            'pendingForms' => [['id' => 42, 'assigned_supervisor_user_id' => 11], ['id' => 43, 'assigned_supervisor_user_id' => 12]],
            'pendingCount' => 162, 'assignedCount' => 125, 'pagination' => $this->metadata(162, 162),
        ]);
        self::assertStringContainsString('Showing 21–40 of 162', $html);
        self::assertCount(1, $xpath->query('//*[@data-queue-count="pending" and normalize-space(.)="162"]'));
        self::assertCount(1, $xpath->query('//*[@data-queue-count="assigned" and normalize-space(.)="125"]'));
        self::assertCount(1, $xpath->query('//*[contains(@class,"gcq-ledger")]//*[@data-table-pagination]'));
        self::assertCount(1, $xpath->query('//*[@data-form-id="42"]//a[contains(@href,"/forms/42") and normalize-space(.)="Review & Co-Sign"]'));
        self::assertCount(1, $xpath->query('//*[@data-form-id="43"]//a[contains(@href,"/forms/43") and normalize-space(.)="Inspect"]'));
        self::assertCount(0, $xpath->query('//form[@method="post" or @method="POST"]'));
        self::assertCount(1, $xpath->query('//*[contains(@class,"gcq-ledger")]/*[contains(@class,"ce-card-header")]//*[contains(@class,"ce-registry-page-size")]'));
        $this->assertSizeOptions($xpath, '//form[@method="get"]');
        $this->assertPagerLinks($xpath, '//*[@data-table-pagination]', 'page', ['per_page' => '20'], false);
    }

    public function testEveryEmptyRegistryStillRendersAccessiblePagerAndCanonicalFirstPage(): void
    {
        $empty = $this->metadata(0, 0, '999');
        foreach ([
            ['guidance/call_slips/index', ['pagination' => $empty], 1],
            ['guidance/episodes/index', ['pagination' => $empty], 1],
            ['guidance/intake/index', ['intakePagination' => $empty, 'incomingPagination' => $empty], 2],
            ['guidance/governance/practitioners', ['profilesPagination' => $empty, 'supervisionPagination' => $empty], 2],
            ['guidance/clinical/cosign_queue', ['pagination' => $empty, 'pendingCount' => 0, 'assignedCount' => 0], 1],
        ] as [$name, $data, $pagerCount]) {
            [$html, $xpath] = $this->render($name, $data);
            self::assertSame($pagerCount, substr_count($html, 'Showing 0–0 of 0'), $name);
            self::assertCount($pagerCount, $xpath->query('//*[@data-table-pagination and @data-page="1"]'));
            self::assertCount($pagerCount, $xpath->query('//*[@data-table-pagination and contains(@class,"ce-table-footer") and not(contains(@class,"mt-3"))]'));
            self::assertCount(4 * $pagerCount, $xpath->query('//*[@data-table-pagination]//*[@aria-disabled="true"]'));
            self::assertCount(0, $xpath->query('//*[@data-table-pagination]//a'));
            self::assertCount(0, $xpath->query('//*[@data-filter-form]//input[contains(@name,"page") and @value!="1"]'));
        }
    }
}

/** Render only the owned section; the real partial continues through the normal renderer. */
final class GuidanceRegistryPaginationSectionView extends View
{
    public function extend(string $layout)
    {
        // The shared application layout is intentionally outside these fixture-only view tests.
    }

    public function render(string $view, ?array $options = null, ?bool $saveData = null): string
    {
        $html = parent::render($view, $options, $saveData);
        return str_starts_with($view, 'guidance/') ? $this->renderSection('content') : $html;
    }
}
