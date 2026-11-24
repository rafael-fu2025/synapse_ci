<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuthenticatedUser;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use DOMDocument;
use DOMXPath;

/** Render production views under the guarded memory-only pagination bootstrap. */
final class ClinicPortalPaginationViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        if (!defined('PAGINATION_ISOLATED_TESTS') || PAGINATION_ISOLATED_TESTS !== true) {
            throw new \RuntimeException('Use the guarded pagination-isolated-bootstrap.php.');
        }
        parent::setUp();
        helper(['form', 'url']);
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        parent::tearDown();
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

    private function metadata(int $total, int $page = 1, int $size = 25, ?int $filtered = null): array
    {
        $filtered ??= $total;
        $pages = max(1, (int) ceil($filtered / $size));
        $page = min($page, $pages);
        return ['page' => $page, 'per_page' => $size, 'total' => $total, 'filtered' => $filtered,
            'pages' => $pages, 'from' => $filtered === 0 ? 0 : ($page - 1) * $size + 1, 'to' => min($page * $size, $filtered)];
    }

    private function chartData(bool $print = false): array
    {
        $encounters = [];
        $labs = [];
        $clearances = [];
        $isolations = [];
        for ($i = 1; $i <= ($print ? 31 : 2); $i++) {
            $encounters[] = ['id' => $i, 'status' => 'completed', 'chief_complaint' => 'Synthetic visit ' . $i,
                'started_at' => '2026-10-01 09:00:00', 'visited_at' => null, 'attending_name' => 'Synthetic clinician', 'disposition' => 'discharged'];
            $labs[] = ['id' => $i, 'test_name' => 'Synthetic lab ' . $i, 'result_status' => 'normal', 'school_year' => '2026-2027',
                'context_type' => 'other', 'result_summary' => 'Synthetic only', 'created_at' => '2026-10-01 09:00:00'];
            $clearances[] = ['id' => $i, 'school_year' => '2026-2027', 'semester' => 'Annual', 'applicant_category' => 'employee',
                'required_labs_json' => '{}', 'clearance_status' => 'pending'];
            $isolations[] = ['id' => $i, 'condition_code' => 'synthetic_condition', 'clearance_status' => 'cleared',
                'isolation_start_date' => '2026-10-01', 'isolation_end_date' => '2026-10-10'];
        }
        $chart = [
            'person' => ['id' => 88, 'kind' => 'guest', 'mis_id' => 'SYN-88', 'first_name' => 'Synthetic', 'last_name' => 'Patient',
                'middle_name' => '', 'email' => 'fixture@example.test', 'gender' => 'unspecified'],
            'health_profile' => [], 'student_profile' => null, 'employee_profile' => null,
            'encounters' => $encounters, 'vitals_history' => [], 'lab_results' => $labs,
            'enrollment_clearances' => $clearances, 'isolation_cases' => $isolations,
            'treatment_history' => [['encounter_id' => 1, 'diagnosis' => 'Complete child diagnosis', 'treatment_plan' => 'Full child plan', 'services_rendered' => 'Service']],
            'dispensation_history' => [],
            'visit_summary' => ['total_visits' => 61, 'current_sy_visits' => 40, 'current_school_year' => '2026-2027',
                'last_visit_at' => '2026-10-01 09:00:00', 'top_complaints' => [['label' => 'All-scope complaint', 'count' => 40]]],
        ];
        if (!$print) {
            $chart['history_pagination'] = ['visits' => $this->metadata(61, 2), 'labs' => $this->metadata(71, 3),
                'clearances' => $this->metadata(81, 2), 'isolation' => $this->metadata(91, 2)];
            $chart['open_visit'] = ['id' => 999, 'status' => 'in_progress'];
        }
        return ['title' => 'Synthetic patient chart', 'chart' => $chart, 'chartTab' => 'tab-labs', 'printChart' => $print,
            'chartBaseUrl' => site_url('/clinic/patients/88'), 'canStartVisit' => true, 'canWriteHealth' => false,
            'canWriteLabs' => true, 'canWriteClearance' => true, 'currentSchoolYear' => '2026-2027', 'schoolYearOptions' => ['2026-2027']];
    }

    public function testChartPagersPreserveFourCanonicalCursorsSelectedTabAndFullCounts(): void
    {
        $html = view('clinic/patients/chart', $this->chartData(), ['saveData' => false]);
        $xpath = $this->xpath($html);
        self::assertCount(4, $xpath->query('//*[@data-chart-history-pager]//*[@data-table-pagination]'));
        self::assertCount(1, $xpath->query('//aside//*[@data-chart-history-pager="isolation"]//*[@data-table-pagination]'));
        self::assertCount(1, $xpath->query('//*[@data-patient-chart]//*[@data-bs-target="#tab-labs" and @aria-selected="true"]'));
        self::assertCount(1, $xpath->query('//*[@id="tab-labs" and contains(@class,"active")]'));
        self::assertCount(0, $xpath->query('//*[@id="tab-visits" and contains(@class,"active")]'));
        self::assertCount(1, $xpath->query('//a[contains(@href,"/clinic/encounters/999")]'));
        self::assertCount(0, $xpath->query('//form[contains(@action,"/start-direct/")]'));
        foreach (['visits' => 61, 'labs' => 71, 'clearances' => 81] as $list => $total) {
            self::assertSame((string) $total, trim($xpath->query('//*[@data-bs-target="#tab-' . $list . '"]//*[@class="cp-tab-count"]')->item(0)->textContent));
        }
        self::assertStringContainsString('40 in SY 2026-2027', $html);
        self::assertStringContainsString('All-scope complaint', $html);
        self::assertStringContainsString('Complete child diagnosis', $html);
        foreach (['visits', 'labs', 'clearances', 'isolation'] as $list) {
            $links = $xpath->query('//*[@data-chart-history-pager="' . $list . '"]//a');
            self::assertGreaterThan(0, $links->length);
            foreach ($links as $link) {
                parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
                self::assertSame('25', $query['per_page']);
                foreach (['visits' => '2', 'labs' => '3', 'clearances' => '2', 'isolation' => '2'] as $other => $page) {
                    self::assertSame($other === $list ? $link->getAttribute('data-table-page') : $page, $query[$other . '_page']);
                }
                $tab = $list === 'isolation' ? 'tab-labs' : 'tab-' . $list;
                self::assertSame($tab, $query['tab']);
                self::assertSame($tab, parse_url($link->getAttribute('href'), PHP_URL_FRAGMENT));
                self::assertArrayNotHasKey('print', $query);
            }
        }
        $print = $xpath->query('//a[@data-print-chart]')->item(0);
        self::assertSame('_blank', $print->getAttribute('target'));
        parse_str((string) parse_url($print->getAttribute('href'), PHP_URL_QUERY), $printQuery);
        self::assertSame(['print' => '1'], $printQuery);
        self::assertCount(3, $xpath->query('//*[@data-chart-page-size]//select[@name="per_page"]/option'));
        self::assertCount(1, $xpath->query('//*[@data-chart-page-size]//input[@name="tab" and @value="tab-labs"]'));
        self::assertStringContainsString("const allowedTabs = ['tab-visits', 'tab-vitals', 'tab-labs', 'tab-clearances', 'tab-edit-health']", $html);
    }

    public function testAuthorizedPrintViewIncludesBeyondPageOneAndSuppressesPagersAndIssueForms(): void
    {
        $html = view('clinic/patients/chart', $this->chartData(true), ['saveData' => false]);
        $xpath = $this->xpath($html);
        self::assertCount(1, $xpath->query('//*[@data-patient-chart and @data-chart-print="1"]'));
        self::assertCount(31, $xpath->query('//*[@id="tab-visits"]//article'));
        self::assertStringContainsString('Synthetic visit 31', $html);
        self::assertStringContainsString('Synthetic lab 31', $html);
        self::assertCount(31, $xpath->query('//*[@id="tab-labs"]//tbody/tr'));
        self::assertCount(31, $xpath->query('//*[@id="tab-clearances"]//tbody/tr'));
        self::assertCount(31, $xpath->query('//*[@id="chart-isolation"]//li'));
        self::assertCount(0, $xpath->query('//*[@data-table-pagination]'));
        self::assertCount(0, $xpath->query('//*[@data-chart-page-size]'));
        self::assertCount(0, $xpath->query('//form[contains(@action,"/labs") or contains(@action,"/clearances")]'));
        foreach (['visits', 'vitals', 'labs', 'clearances'] as $tab) {
            self::assertCount(1, $xpath->query('//*[@id="tab-' . $tab . '" and contains(@class,"active")]'));
        }
        self::assertStringContainsString('Complete patient history is loaded for printing', $html);
    }

    private function surveyData(): array
    {
        return [
            'title' => 'Synthetic surveys', 'baseUrl' => site_url('/portal/surveys'), 'hasProfile' => true,
            'filters' => ['q' => 'Synthetic', 'status' => 'all', 'page' => 2, 'per_page' => 50, 'mi_page' => 2, 'mi_per_page' => 25],
            'pagination' => $this->metadata(126, 2, 50), 'counts' => ['all' => 126, 'available' => 100, 'submitted' => 16, 'closed' => 10],
            'miPagination' => $this->metadata(80, 2, 25), 'miCounts' => ['all' => 80, 'available' => 45, 'submitted' => 20, 'closed' => 35],
            'miGateEnabled' => false,
            'miAssignments' => [
                ['id' => 52, 'status' => 'in_progress', 'draft_count' => 3, 'academic_term' => 'First Term', 'academic_year' => '2026-2027'],
                ['id' => 51, 'status' => 'completed', 'submission_id' => 700, 'draft_count' => 0, 'academic_term' => 'First Term', 'academic_year' => '2026-2027'],
            ],
            'items' => [['id' => 900, 'title' => 'Synthetic general survey', 'description' => 'Synthetic only', 'submitted' => false, 'closed' => false]],
        ];
    }

    public function testSurveySectionsBothPageIndependentlyInsideLiveResultsWithFullDisplayCounts(): void
    {
        $html = view('surveys/portal_index', $this->surveyData(), ['saveData' => false]);
        $xpath = $this->xpath($html);
        self::assertCount(1, $xpath->query('//*[@data-live-filters and @data-filter-page-params="page mi_page"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//input[@type="hidden" and @name="mi_page" and @value="2"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//input[@name="page" and @value="2"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//select[@name="per_page"]/option[@value="50" and @selected]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//select[@name="mi_per_page"]/option[@value="25" and @selected]'));
        self::assertCount(3, $xpath->query('//*[@data-filter-form]//select[@name="mi_per_page"]/option'));
        self::assertCount(1, $xpath->query('//*[@data-filter-region="results"]//*[@data-mi-assignment-section]//nav[@aria-label="MI assignment pages"]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-region="results"]//*[@data-general-survey-section]//nav[@aria-label="General survey pages"]'));
        self::assertCount(2, $xpath->query('//*[@data-mi-assignment-section]//article'));
        self::assertCount(1, $xpath->query('//*[@data-general-survey-section]//article'));
        self::assertStringContainsString('MI assignments', $html);
        self::assertStringContainsString('General surveys', $html);
        self::assertStringContainsString('Showing 26–50 of 80', $html);
        self::assertStringContainsString('Showing 51–100 of 126', $html);
        foreach (['all' => '206', 'available' => '145', 'submitted' => '36', 'closed' => '45'] as $state => $count) {
            $tabs = $xpath->query('//*[@class="pe-tabs"]//a');
            foreach ($tabs as $tab) {
                parse_str((string) parse_url($tab->getAttribute('href'), PHP_URL_QUERY), $query);
                if ($query['status'] !== $state) continue;
                self::assertSame($count, trim($xpath->query('.//span', $tab)->item(0)->textContent));
                self::assertSame('1', $query['page']);
                self::assertSame('1', $query['mi_page']);
                self::assertSame('50', $query['per_page']);
                self::assertSame('25', $query['mi_per_page']);
            }
        }
        foreach (['MI assignment pages', 'General survey pages'] as $label) {
            $links = $xpath->query('//nav[@aria-label="' . $label . '"]//a');
            self::assertGreaterThan(0, $links->length);
            foreach ($links as $link) {
                self::assertTrue($link->hasAttribute('data-filter-link'));
                parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
                self::assertSame('Synthetic', $query['q']);
                self::assertSame('all', $query['status']);
                self::assertSame('50', $query['per_page']);
                self::assertSame('25', $query['mi_per_page']);
                if ($label === 'MI assignment pages') {
                    self::assertSame('2', $query['page']);
                    self::assertSame($link->getAttribute('data-table-page'), $query['mi_page']);
                } else {
                    self::assertSame('2', $query['mi_page']);
                }
            }
        }
        self::assertCount(1, $xpath->query('//*[@data-mi-assignment-section]//a[contains(@href,"/portal/assessments/mi/52")]'));
        self::assertCount(1, $xpath->query('//*[@data-mi-assignment-section]//a[contains(@href,"/portal/assessments/mi/results/700") and not(contains(@href,"/pdf"))]'));
    }

    public function testSurveyEmptyPagesKeepBothFootersAndDoNotChangeRequestedSizes(): void
    {
        $data = $this->surveyData();
        $data['items'] = [];
        $data['miAssignments'] = [];
        $data['pagination'] = $this->metadata(126, 1, 100, 0);
        $data['miPagination'] = $this->metadata(80, 1, 50, 0);
        $data['filters']['status'] = 'closed';
        $html = view('surveys/portal_index', $data, ['saveData' => false]);
        $xpath = $this->xpath($html);
        self::assertCount(2, $xpath->query('//*[@data-filter-region="results"]//nav[contains(@aria-label,"pages")]'));
        self::assertCount(0, $xpath->query('//nav[contains(@aria-label,"pages")]//a'));
        self::assertCount(1, $xpath->query('//*[@data-mi-assignment-section]//*[@data-per-page="50"]'));
        self::assertCount(1, $xpath->query('//*[@data-general-survey-section]//*[@data-per-page="100"]'));
        self::assertStringContainsString('No MI assignments match', $html);
        self::assertStringContainsString('No closed surveys', $html);
        self::assertSame(2, substr_count($html, 'Showing 0–0 of 0'));
    }

    public function testCallSlipViewKeepsBeyondFiveHundredTotalsAndMetadataAndSizeOnLinks(): void
    {
        $html = view('portal/call_slips', [
            'title' => 'Synthetic call slips', 'filter' => 'pending', 'filters' => ['status' => 'pending', 'per_page' => 50],
            'counts' => ['all' => 624, 'pending' => 3, 'acknowledged' => 1, 'history' => 620],
            'pagination' => $this->metadata(624, 2, 50, 123), 'baseUrl' => site_url('/portal/call-slips'),
            'callSlips' => [['id' => 12, 'attendance_status' => 'pending', 'call_slip_number' => 'SYN-SLIP',
                'form_instance_number' => 'SYN-INSTANCE', 'call_date' => '2000-01-01', 'call_time' => '09:00:00', 'purpose' => 'counseling']],
        ], ['saveData' => false]);
        $xpath = $this->xpath($html);
        self::assertStringContainsString('Showing 51–100 of 123', $html);
        self::assertStringContainsString('(624 total call slips)', $html);
        self::assertStringContainsString('SYN-INSTANCE', $html);
        self::assertStringContainsString('SYN-SLIP', $html);
        self::assertCount(1, $xpath->query('//form[@data-background-submit and contains(@action,"/12/acknowledge")]'));
        self::assertCount(1, $xpath->query('//select[@name="per_page"]/option[@value="50" and @selected]'));
        foreach ($xpath->query('//*[@data-table-pagination]//a') as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame('pending', $query['status']);
            self::assertSame('50', $query['per_page']);
            self::assertSame($link->getAttribute('data-table-page'), $query['page']);
        }
        foreach ($xpath->query('//nav[@aria-label="Filter guidance call slips"]//a') as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame('50', $query['per_page']);
            self::assertArrayNotHasKey('page', $query);
        }
    }
}
