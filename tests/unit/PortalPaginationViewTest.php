<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuthenticatedUser;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use DOMDocument;
use DOMXPath;

final class PortalPaginationViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['form', 'url']);
        session()->remove('user');
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
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

    public function testAppointmentFooterAndModalActionsStayInsideLiveResults(): void
    {
        $html = view('portal/index', [
            'chart' => [], 'activeTickets' => [],
            'filters' => ['service' => 'clinic', 'status' => 'active', 'sort' => 'date_desc', 'per_page' => 20],
            'pagination' => ['page' => 2, 'pages' => 3, 'per_page' => 20, 'total' => 90, 'filtered' => 43, 'from' => 21, 'to' => 40],
            'appointments' => [[
                'id' => 123, 'unit' => 'clinic', 'status' => 'requested', 'type' => 'routine',
                'starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 09:30:00',
            ]],
        ], ['saveData' => false]);
        $xpath = $this->xpath($html);
        self::assertStringContainsString('Showing 21–40 of 43', $html);
        self::assertStringContainsString('(90 total appointments)', $html);
        self::assertCount(1, $xpath->query('//*[@data-filter-region="results"]//*[@data-table-pagination]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-form]//select[@name="per_page"]/option[@value="20" and @selected]'));
        self::assertCount(4, $xpath->query('//*[@data-filter-form]//select[@name="per_page"]/option'));
        self::assertCount(0, $xpath->query('//*[@data-filter-form]//*[@name="page"]'));
        $links = $xpath->query('//*[@data-table-pagination]//a');
        self::assertGreaterThan(0, $links->length);
        foreach ($links as $link) {
            self::assertTrue($link->hasAttribute('data-filter-link'));
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame(['service' => 'clinic', 'sort' => 'date_desc', 'status' => 'active',
                'page' => $link->getAttribute('data-table-page'), 'per_page' => '20'], $query);
        }
        self::assertCount(1, $xpath->query('//*[@data-filter-region="results"]//a[@data-app-modal-open and contains(@href,"/portal/appointments/123")]'));
        self::assertCount(1, $xpath->query('//*[@data-filter-region="results"]//form[@data-background-submit and @data-confirm-reason and contains(@action,"/portal/appointments/clinic/cancel/123")]'));
    }

    public function testEmptyAppointmentResultsStillHaveAnAccessibleFooter(): void
    {
        $html = view('portal/index', ['chart' => [], 'appointments' => [], 'activeTickets' => [],
            'filters' => ['service' => 'guidance', 'status' => 'all', 'sort' => 'upcoming', 'per_page' => 25],
            'pagination' => ['page' => 1, 'pages' => 1, 'per_page' => 25, 'total' => 10, 'filtered' => 0, 'from' => 0, 'to' => 0],
        ], ['saveData' => false]);
        self::assertStringContainsString('No appointments match these filters.', $html);
        self::assertStringContainsString('Showing 0–0 of 0', $html);
        $xpath = $this->xpath($html);
        self::assertCount(0, $xpath->query('//*[@data-table-pagination]//a'));
        self::assertCount(4, $xpath->query('//*[@data-table-pagination]//*[@aria-disabled="true"]'));
    }
}
