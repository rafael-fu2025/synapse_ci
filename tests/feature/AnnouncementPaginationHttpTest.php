<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthenticatedUser;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Real management routes and views against private SQLite read-only fixtures. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AnnouncementPaginationHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = ['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true];
        $this->db = Database::connect();
        $this->assertSame(':memory:', $this->db->database);
        $this->db->query('CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)');
        $this->db->query('CREATE TABLE people (id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT)');
        $this->db->query('CREATE TABLE employee_profiles (person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER)');
        $this->db->query('CREATE TABLE permissions (id INTEGER PRIMARY KEY, code TEXT)');
        $this->db->query('CREATE TABLE role_permissions (role TEXT, permission_id INTEGER)');
        $this->db->query('CREATE TABLE announcements (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit TEXT, title TEXT, body TEXT, audience TEXT, target_year_levels TEXT, target_student_categories TEXT, status TEXT, starts_at TEXT, ends_at TEXT, published_at TEXT, version INTEGER)');
        $this->db->query('CREATE TABLE announcements_reads (id INTEGER PRIMARY KEY, tenant_id INTEGER, announcement_id INTEGER, person_id INTEGER, read_at TEXT)');
        $this->db->table('tenants')->insert(['id' => 1, 'is_active' => 1]);
        $this->db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'person_id' => 1, 'username' => 'pagination', 'role' => 'superadmin', 'status' => 'active']);
        for ($id = 1; $id <= 26; $id++) {
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => 1, 'first_name' => 'Reader', 'last_name' => (string) $id]);
            $this->db->table('announcements')->insert(['id' => $id, 'tenant_id' => 1, 'unit' => 'guidance',
                'title' => 'Notice ' . $id, 'body' => 'Pagination fixture', 'audience' => 'all', 'status' => 'published', 'published_at' => '2026-10-01 00:00:00', 'version' => 1]);
            $this->db->table('announcements_reads')->insert(['id' => $id, 'tenant_id' => 1, 'announcement_id' => 1, 'person_id' => $id, 'read_at' => '2026-10-02 00:00:00']);
        }
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function getManagement(string $query): DOMXPath
    {
        Services::resetSingle('renderer');
        $result = $this->withSession(['tenant_id' => 1,
            'user' => ['id' => 1, 'tenant_id' => 1, 'logged_in' => true, 'role' => 'superadmin', 'permissions' => ['*']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()],
        ])->withHeaders([])->get('guidance/announcements?' . $query);
        $result->assertStatus(200);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $document->loadHTML('<?xml encoding="utf-8" ?>' . $result->getBody()); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new DOMXPath($document);
    }

    public function testManagementAndReadHistoryHaveIndependentSharedControls(): void
    {
        $xpath = $this->getManagement('page=999&reads=1&read_page=999');
        $this->assertCount(2, $xpath->query('//*[@data-table-pagination]'));
        foreach (['Announcement pages' => 'page', 'Read history pages' => 'read_page'] as $label => $pageParam) {
            $nav = $xpath->query('//nav[@aria-label="' . $label . '"]')->item(0);
            $metadata = $nav->parentNode;
            $this->assertSame('2', $metadata->getAttribute('data-page'));
            $this->assertSame('25', $metadata->getAttribute('data-per-page'));
            $this->assertStringContainsString('Showing 26–26 of 26', $metadata->textContent);
            $anchors = $xpath->query('.//a', $nav);
            $this->assertCount(3, $anchors);
            foreach ($anchors as $anchor) {
                parse_str((string) parse_url($anchor->getAttribute('href'), PHP_URL_QUERY), $query);
                $this->assertSame('1', $query['reads']);
                $this->assertSame('1', $query[$pageParam]);
                $this->assertSame('2', $query[$pageParam === 'page' ? 'read_page' : 'page']);
                $this->assertFalse($anchor->hasAttribute('data-filter-link'));
            }
        }
    }

    public function testEmptyReadHistoryAndManagementFirstPage(): void
    {
        $xpath = $this->getManagement('reads=2&read_page=999');
        $controls = $xpath->query('//*[@data-table-pagination]');
        $this->assertCount(2, $controls);
        $this->assertStringContainsString('Showing 1–25 of 26', $controls->item(0)->textContent);
        $this->assertStringContainsString('Showing 0–0 of 0', $controls->item(1)->textContent);
        $this->assertSame('1', $controls->item(1)->getAttribute('data-page'));
        $this->assertCount(0, $xpath->query('.//a', $controls->item(1)));
    }
}
