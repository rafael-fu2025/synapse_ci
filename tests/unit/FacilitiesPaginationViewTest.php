<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuthenticatedUser;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use DOMDocument;
use DOMXPath;

final class FacilitiesPaginationViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['form', 'url']);
        session()->remove('user');
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
    }

    public function testDirectoryLinksRetainViewAndSizeWithoutReopeningCreate(): void
    {
        foreach ([false, true] as $archived) {
            Services::resetSingle('renderer');
            $item = ['unit' => ['id' => 26, 'unit_code' => 'DRUM-26', 'name' => 'Drum 26',
                'status' => 'offline', 'drum_capacity_kg' => '80.00', 'deleted_at' => '2026-10-20 10:00:00'],
                'schedule' => ['version' => 0, 'enabled' => false, 'times' => [], 'duration_seconds' => 60], 'device' => null];
            $html = view('facilities/index', [
                'units' => [$item], 'archivedView' => $archived, 'canConfigure' => true, 'openCreate' => !$archived,
                'stats' => ['total' => 61, 'active' => 40, 'maintenance' => 10, 'bound' => 30],
                'pagination' => ['page' => 2, 'pages' => 3, 'per_page' => 25, 'total' => 61, 'filtered' => 61, 'from' => 26, 'to' => 50],
            ], ['saveData' => false]);
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new DOMXPath($document);
            self::assertStringContainsString('Showing 26–50 of 61', $html);
            self::assertStringContainsString('Total drums', $html);
            self::assertStringNotContainsString('Drums shown', $html);
            self::assertCount(4, $xpath->query('//select[@name="per_page"]/option'));
            self::assertCount(1, $xpath->query('//select[@name="per_page"]/option[@value="25" and @selected]'));
            self::assertCount($archived ? 1 : 0, $xpath->query('//form[@method="get"]/input[@name="view" and @value="archived"]'));
            self::assertCount(0, $xpath->query('//form[@method="get"]//*[@name="page" or @name="create"]'));
            $links = $xpath->query('//*[@data-table-pagination]//a');
            self::assertGreaterThan(0, $links->length);
            foreach ($links as $link) {
                parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
                self::assertSame(($archived ? ['view' => 'archived'] : []) + [
                    'page' => $link->getAttribute('data-table-page'), 'per_page' => '25',
                ], $query);
            }
            self::assertCount($archived ? 1 : 0, $xpath->query('//form[contains(@action,"/facilities/unit/26/restore")]'));
            self::assertCount($archived ? 0 : 1, $xpath->query('//a[contains(@class,"stretched-link") and contains(@href,"/facilities/unit/26")]'));
            self::assertCount(1, $xpath->query('//*[@id="unitModal"]'));
            self::assertCount($archived ? 0 : 1, $xpath->query('//*[@data-bmg-open-create]'));
        }
    }

    public function testEmptyDirectoryStillHasCountedFooterAndNoConfigurationActionsForReader(): void
    {
        $html = view('facilities/index', ['units' => [], 'canConfigure' => false,
            'stats' => ['total' => 0, 'active' => 0, 'maintenance' => 0, 'bound' => 0],
            'pagination' => ['page' => 1, 'pages' => 1, 'per_page' => 20, 'total' => 0, 'filtered' => 0, 'from' => 0, 'to' => 0],
        ], ['saveData' => false]);
        self::assertStringContainsString('Showing 0–0 of 0', $html);
        self::assertStringContainsString('No drums registered', $html);
        self::assertStringNotContainsString('id="unitModal"', $html);
        self::assertStringNotContainsString('view=archived', $html);
    }
}
