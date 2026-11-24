<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\TablePage;
use App\Services\Api\ApiContext;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use DOMDocument;
use DOMXPath;
use ReflectionProperty;

/** Synthetic detail views only, guarded by main's no-dotenv/private-runtime bootstrap. */
final class DetailFormsPaginationViewTest extends CIUnitTestCase
{
    private ?BaseConnection $fixture = null;
    private array $savedConnections = [];
    private ?int $savedTenant = null;
    private ?array $savedApiContext = null;

    protected function setUp(): void
    {
        if (!defined('PAGINATION_ISOLATED_TESTS') || PAGINATION_ISOLATED_TESTS !== true) {
            $this->markTestSkipped('Use the explicit no-dotenv pagination-isolated bootstrap.');
        }
        if (!extension_loaded('sqlite3')) $this->markTestSkipped('Private SQLite fixtures require sqlite3.');
        parent::setUp();
        $this->savedConnections = (new ReflectionProperty(Database::class, 'instances'))->getValue();
        $this->savedTenant = (new ReflectionProperty(CurrentTenant::class, 'id'))->getValue();
        $this->savedApiContext = (new ReflectionProperty(ApiContext::class, 'state'))->getValue();
        $this->fixture = Database::connect([
            'DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'detail_view_',
            'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->fixture->initialize();
        (new ReflectionProperty(Database::class, 'instances'))->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        CurrentTenant::set(1);
        ApiContext::clear();
        session()->remove('user');
        helper(['url', 'form']);
        $this->request('guidance/cases/101');
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->close();
            (new ReflectionProperty(Database::class, 'instances'))->setValue(null, $this->savedConnections);
            (new ReflectionProperty(ApiContext::class, 'state'))->setValue(null, $this->savedApiContext);
            CurrentTenant::set($this->savedTenant);
        }
        parent::tearDown();
    }

    private function request(string $path, array $query = []): void
    {
        $config = clone config('App');
        $config->baseURL = 'http://127.0.0.1:8976/';
        $config->indexPage = '';
        $request = new IncomingRequest($config, new SiteURI($config, $path), null, new UserAgent());
        $request->setMethod('GET')->setGlobal('get', $query);
        Services::injectMock('request', $request);
        Services::injectMock('uri', $request->getUri());
    }

    private function render(string $view, array $data): string
    {
        Services::resetSingle('renderer');
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = (string) $query; };
        Events::on('DBQuery', $listener);
        try {
            $html = view($view, $data, ['saveData' => true]);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        self::assertSame([], $queries, 'Synthetic detail view rendering must not query records.');
        return $html;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }

    private function caseData(int $total = 53, string $page = '2'): array
    {
        $pagination = TablePage::forList(['forms_page' => $page], 'forms_page')->metadata($total, $total);
        $forms = [];
        for ($number = $pagination['from']; $number > 0 && $number <= $pagination['to']; ++$number) {
            $forms[] = ['id' => $number, 'canonical_title' => 'Synthetic form ' . $number,
                'internal_key' => 'counseling_contact_sheet', 'form_code' => 'F-OGC-GCS-CCF-009-00',
                'instance_number' => 'SYNTH-' . $number, 'status' => 'finalized', 'created_by_user_id' => 7,
                'updated_at' => '2026-11-01 09:00:00', 'attestations' => [
                    ['signer_user_id' => 8, 'signer_role' => 'licensed_counselor', 'attested_at' => '2026-11-01 10:00:00'],
                ]];
        }
        return [
            'title' => 'Synthetic Case', 'caseTab' => 'clinical-forms',
            'case' => ['id' => 101, 'tenant_id' => 1, 'person_id' => 10, 'counsellor_user_id' => 7,
                'case_number' => 'SYNTH-CASE-101', 'title' => 'Synthetic case', 'category' => 'personal',
                'status' => 'closed', 'opened_at' => '2026-11-01 08:00:00'],
            'package' => ['student' => null, 'employee' => null,
                'patient' => ['kind' => 'student', 'last_name' => 'Person', 'first_name' => 'Synthetic', 'mis_id' => 'SYNTH-10'],
                'sessions' => [['id' => 301, 'session_number' => 1, 'session_type' => 'regular', 'status' => 'completed',
                    'counsellor_user_id' => 7, 'counsellor_name' => 'Synthetic author', 'started_at' => '2026-11-01 09:00:00']],
                'followups' => [['id' => 401, 'status' => 'completed', 'action_plan' => 'Synthetic follow-up', 'due_date' => '2026-11-02']]],
            'activeSession' => null, 'decryptedNote' => null,
            'clinicalHistory' => ['controlled_forms' => $forms, 'formsPagination' => $pagination],
            'actor' => ['id' => 7, 'role' => 'counsellor', 'permissions' => ['guidance.cases.read']],
            'userDirectory' => [7 => 'Synthetic author', 8 => 'Synthetic signer'],
        ];
    }

    public function testCaseCountAndPaginationStayInClinicalTabWithPresentationRestorationIntact(): void
    {
        $html = $this->render('guidance/cases/view', $this->caseData());
        $xpath = $this->xpath($html);
        self::assertStringContainsString('Clinical Forms &amp; Attestations (53)', $html);
        self::assertStringContainsString('Showing 26–50 of 53', $html);
        self::assertCount(25, $xpath->query('//*[@id="tab-clinical-forms"]//tbody/tr'));
        self::assertCount(1, $xpath->query('//*[@id="tab-clinical-forms"]//*[@data-table-pagination]'));
        self::assertCount(1, $xpath->query('//*[@id="case-clinical-forms-tab" and @aria-selected="true" and contains(@class,"active")]'));
        self::assertCount(1, $xpath->query('//*[@id="tab-clinical-forms" and contains(@class,"show active")]'));
        self::assertCount(0, $xpath->query('//*[@id="case-history-tab" and @aria-selected="true"]'));
        self::assertStringContainsString('Synthetic author', $html);
        self::assertStringContainsString('Synthetic signer', $html);
        $links = $xpath->query('//*[@id="tab-clinical-forms"]//*[@data-table-pagination]//a');
        self::assertGreaterThan(0, $links->length);
        foreach ($links as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame(['tab' => 'clinical-forms', 'forms_page' => $link->getAttribute('data-table-page'), 'per_page' => '25'], $query);
            self::assertSame('tab-clinical-forms', parse_url($link->getAttribute('href'), PHP_URL_FRAGMENT));
        }
        self::assertCount(1, $xpath->query('//*[@data-refresh-owner="guidance.case.101"]//*[@data-refresh-region="workspace"]'));
        self::assertCount(1, $xpath->query('//*[@id="tab-timeline"]//a[contains(@href,"/guidance/sessions/301/read") and @data-app-modal-open]'));
        self::assertStringContainsString('Synthetic follow-up', $html);
        self::assertStringContainsString("document.addEventListener('synapse:before-swap'", $html);
        self::assertStringContainsString("tab.setAttribute('aria-selected', String(active))", $html);
        self::assertStringContainsString('never note contents', $html);
    }

    public function testEmptyAndClampedLastCasePagesShowRangesAndDisabledBoundaries(): void
    {
        foreach ([0 => 'Showing 0–0 of 0', 53 => 'Showing 51–53 of 53'] as $total => $range) {
            $html = $this->render('guidance/cases/view', $this->caseData($total, '1000000'));
            $xpath = $this->xpath($html);
            self::assertStringContainsString($range, $html);
            self::assertCount($total === 0 ? 0 : 3, $xpath->query('//*[@id="tab-clinical-forms"]//tbody/tr'));
            self::assertCount($total === 0 ? 4 : 2, $xpath->query('//*[@id="tab-clinical-forms"]//*[@aria-disabled="true"]'));
            if ($total === 0) self::assertStringContainsString('No controlled clinical forms', $html);
        }
    }

    public function testCaseTabDefaultsAndLegacyHistoryDataStillRenderWithoutPaging(): void
    {
        $data = $this->caseData(1, '1');
        unset($data['caseTab'], $data['clinicalHistory']['formsPagination']);
        $html = $this->render('guidance/cases/view', $data);
        self::assertCount(1, $this->xpath($html)->query('//*[@id="case-history-tab" and @aria-selected="true"]'));
        self::assertStringContainsString('Clinical Forms &amp; Attestations (1)', $html);
        self::assertStringNotContainsString('data-table-pagination', $html);
        $data['caseTab'] = 'followups';
        $html = $this->render('guidance/cases/view', $data);
        self::assertCount(1, $this->xpath($html)->query('//*[@id="case-followups-tab" and @aria-selected="true"]'));
        $data['caseTab'] = 'session';
        $html = $this->render('guidance/cases/view', $data);
        self::assertCount(1, $this->xpath($html)->query('//*[@id="case-history-tab" and @aria-selected="true"]'));
    }

    private function questionData(string $page = '2', int $selectedRevision = 1): array
    {
        $total = 53;
        $historyPagination = TablePage::forList(['history_page' => $page], 'history_page')->metadata($total, $total);
        $revisions = [];
        for ($number = $historyPagination['from']; $number <= $historyPagination['to']; ++$number) {
            $revisions[] = ['revision' => $total - $number + 1, 'created_at' => '2026-11-01 09:00:00'];
        }
        return ['title' => 'Question Revision History', 'baseUrl' => '/guidance/question-bank',
            'entry' => ['id' => 5, 'status' => 'archived'], 'revisions' => $revisions, 'historyPagination' => $historyPagination,
            'selected' => ['revision' => $selectedRevision, 'category' => 'Synthetic', 'audience' => 'all', 'review_note' => '',
                'question' => ['type' => 'text', 'label' => 'Selected independent revision ' . $selectedRevision, 'required' => true, 'options' => []]],
        ];
    }

    public function testQuestionHistoryTotalAndLinksRetainIndependentSelectedRevisionAndRefreshRegion(): void
    {
        $this->request('guidance/question-bank/5', ['history_page' => '2', 'revision' => '1']);
        $html = $this->render('question_bank/show', $this->questionData());
        $xpath = $this->xpath($html);
        self::assertStringContainsString('53 revisions', $html);
        self::assertStringContainsString('Showing 26–50 of 53', $html);
        self::assertStringContainsString('Selected independent revision 1', $html);
        self::assertCount(25, $xpath->query('//*[contains(@class,"qb-revision-row")]'));
        self::assertCount(1, $xpath->query('//*[@data-refresh-owner="question-bank.history.5"]//*[@data-refresh-region="selected-revision"]'));
        self::assertCount(0, $xpath->query('//*[@data-refresh-region="selected-revision"]//*[@data-table-pagination]'));
        $pageLinks = $xpath->query('//*[contains(@class,"qb-history-revisions")]//*[@data-table-pagination]//a');
        self::assertGreaterThan(0, $pageLinks->length);
        foreach ($pageLinks as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame(['revision' => '1', 'history_page' => $link->getAttribute('data-table-page'), 'per_page' => '25'], $query);
            self::assertSame('/guidance/question-bank/5', parse_url($link->getAttribute('href'), PHP_URL_PATH));
        }
        $revisionLinks = $xpath->query('//*[contains(@class,"qb-revision-list")]//a');
        self::assertCount(25, $revisionLinks);
        foreach ($revisionLinks as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame('2', $query['history_page']);
            self::assertSame('25', $query['per_page']);
            self::assertSame('Revision ' . $query['revision'], trim($link->textContent));
        }
    }

    public function testQuestionLastPageKeepsCurrentRevisionMarkerAndUnpagedCompatibility(): void
    {
        $html = $this->render('question_bank/show', $this->questionData('1000000', 2));
        $xpath = $this->xpath($html);
        self::assertStringContainsString('Showing 51–53 of 53', $html);
        self::assertCount(3, $xpath->query('//*[contains(@class,"qb-revision-row")]'));
        self::assertCount(1, $xpath->query('//*[contains(@class,"qb-revision-list")]//a[@aria-current="page" and contains(@href,"revision=2")]'));
        self::assertCount(2, $xpath->query('//*[@data-table-pagination]//*[@aria-disabled="true"]'));
        $data = $this->questionData('1000000', 2);
        unset($data['historyPagination']);
        $html = $this->render('question_bank/show', $data);
        self::assertStringContainsString('3 revisions', $html);
        self::assertStringNotContainsString('data-table-pagination', $html);
        foreach ($this->xpath($html)->query('//*[contains(@class,"qb-revision-list")]//a') as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame(['revision'], array_keys($query));
        }
    }

    public function testSurveyBankRangeIsVisibleStatusWithoutRemovingPagerSearchCompositionOrSelections(): void
    {
        $this->request('guidance/surveys/create', ['fragment' => '1']);
        $html = $this->render('surveys/form', ['survey' => null, 'title' => 'Synthetic Survey',
            'formAction' => '/guidance/surveys/store', 'cancelUrl' => '/guidance/surveys', 'formModalPage' => true,
            'aiPrefillStatus' => ['available' => false, 'reason' => 'Synthetic fixture'],
        ]);
        $xpath = $this->xpath($html);
        $range = $xpath->query('//*[@aria-label="Question bank picker"]//*[@role="status" and @aria-live="polite" and contains(@x-text,"Showing ")]');
        self::assertCount(1, $range);
        self::assertSame('bankLoaded && !bankBusy && !bankError', $range->item(0)->getAttribute('x-show'));
        self::assertSame("'Showing ' + bankFrom + '–' + bankTo + ' of ' + bankTotal", $range->item(0)->getAttribute('x-text'));
        self::assertCount(1, $xpath->query('//*[@aria-label="Question bank picker"]//*[contains(@x-text,"Page ") and contains(@x-text,"bankPages")]'));
        self::assertStringContainsString('bankPage - 1', $html);
        self::assertStringContainsString('bankPage + 1', $html);
        self::assertStringContainsString('bankComposition', $html);
        self::assertStringContainsString('bankSearchEnter', $html);
        self::assertStringContainsString('addSelectedBankQuestions()', $html);
        self::assertStringContainsString('Selections stay checked across pages and searches', $html);
        self::assertStringContainsString('item.revision', $html);
    }
}
