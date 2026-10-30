<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use DOMDocument;
use DOMXPath;

final class TablePaginationViewTest extends CIUnitTestCase
{
    private function render(array $data = []): string
    {
        Services::resetSingle('renderer');
        return view('partials/table_pagination', $data + ['baseUrl' => '/records'], ['saveData' => false]);
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

    public function testDefaultsAndEmptyBoundariesRemainAccessible(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('Showing 0–0 of 0', $html);
        $this->assertStringContainsString('data-per-page="25"', $html);
        $this->assertStringNotContainsString('data-filter-link', $html);
        $xpath = $this->xpath($html);
        $this->assertCount(0, $xpath->query('//a'));
        $this->assertCount(4, $xpath->query('//*[@aria-disabled="true"]'));
        $this->assertCount(1, $xpath->query('//*[@aria-current="page"]'));
    }

    public function testDirectoryDefaultsPreserveFiltersCountsAndTableHooks(): void
    {
        $html = $this->render([
            'pagination' => ['page' => 2, 'per_page' => 25, 'filtered' => 51, 'total' => 90],
            'filters' => ['q' => 'A & B', 'kind' => 'student', 'status' => 'active', 'category' => 'not automatic'],
        ]);
        $this->assertStringContainsString('Showing 26–50 of 51', $html);
        $this->assertStringContainsString('(90 total records)', $html);
        $this->assertStringNotContainsString('data-filter-link', $html);
        foreach ($this->xpath($html)->query('//a') as $anchor) {
            parse_str((string) parse_url($anchor->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame(['q' => 'A & B', 'kind' => 'student', 'status' => 'active',
                'page' => $anchor->getAttribute('data-table-page'), 'per_page' => '25'], $query);
        }
    }

    public function testTwentyPerPageExtraFiltersAndLiveHooksApplyToEveryLink(): void
    {
        $html = $this->render([
            'pagination' => ['page' => 3, 'per_page' => 20, 'total' => 141],
            'filters' => ['q' => '<script> & café', 'status' => 'active'],
            'paginationExtraQuery' => ['category' => 'Health & wellbeing', 'audience' => 'students', 'page' => 999, 'per_page' => 100],
            'paginationLiveFilters' => true,
        ]);
        $this->assertStringContainsString('Showing 41–60 of 141', $html);
        $this->assertStringContainsString('data-per-page="20"', $html);
        $xpath = $this->xpath($html);
        $this->assertCount(9, $xpath->query('//li'));
        foreach ($xpath->query('//a') as $anchor) {
            $this->assertTrue($anchor->hasAttribute('data-filter-link'));
            parse_str((string) parse_url($anchor->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame('Health & wellbeing', $query['category']);
            $this->assertSame('students', $query['audience']);
            $this->assertSame('<script> & café', $query['q']);
            $this->assertSame('active', $query['status']);
            $this->assertSame('20', $query['per_page']);
            $this->assertSame($anchor->getAttribute('data-table-page'), $query['page']);
        }
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testIndependentReadHistoryCursorPreservesManagementPage(): void
    {
        $html = $this->render([
            'pagination' => ['page' => 2, 'pages' => 3, 'total' => 51, 'per_page' => 25],
            'paginationPageParam' => 'read_page',
            'paginationExtraQuery' => ['reads' => 42, 'page' => 4, 'read_page' => 999],
            'paginationLabel' => 'Read history pages',
        ]);
        $this->assertStringContainsString('Showing 26–50 of 51', $html);
        $this->assertStringContainsString('aria-label="Read history pages"', $html);
        foreach ($this->xpath($html)->query('//a') as $anchor) {
            parse_str((string) parse_url($anchor->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame('4', $query['page']);
            $this->assertSame('42', $query['reads']);
            $this->assertSame($anchor->getAttribute('data-table-page'), $query['read_page']);
            $this->assertFalse($anchor->hasAttribute('data-filter-link'));
        }
    }

    public function testLastPageClampingSizesFallbackAndExplicitRanges(): void
    {
        foreach ([20, 25, 50, 100] as $size) {
            $html = $this->render(['pagination' => ['page' => 999, 'per_page' => $size, 'total' => $size + 1]]);
            $this->assertStringContainsString('data-page="2"', $html);
            $this->assertStringContainsString('Showing ' . ($size + 1) . '–' . ($size + 1) . ' of ' . ($size + 1), $html);
            $this->assertCount(2, $this->xpath($html)->query('//*[@aria-disabled="true"]'));
        }
        $this->assertStringContainsString('data-per-page="25"', $this->render(['pagination' => ['per_page' => 13]]));
        $html = $this->render(['pagination' => ['page' => 2, 'pages' => 5, 'per_page' => 25, 'filtered' => 100, 'total' => 200, 'from' => 30, 'to' => 40]]);
        $this->assertStringContainsString('Showing 30–40 of 100', $html);
        $this->assertStringContainsString('(200 total records)', $html);
    }
}
