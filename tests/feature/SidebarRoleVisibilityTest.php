<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

/** Sidebar sections come from the fresh role, never from the current URL/subdomain. */
final class SidebarRoleVisibilityTest extends CIUnitTestCase
{
    public static function roles(): array
    {
        $personal = ['Personal Health'];
        $clinic = ['Clinic Workstation'];
        $guidance = ['Guidance Workstation'];
        $facilities = ['Facilities (BMG)'];

        return [
            'superadmin' => ['superadmin', [], array_merge($clinic, $guidance, $facilities, $personal, ['Governance', 'Appearance']), ['/clinic', '/guidance', '/facilities', '/admin', '/admin/audit', '/admin/carousel'], []],
            'clinic_admin' => ['clinic_admin', ['clinic.announcements.manage', 'reports.read', 'rbac.manage'], array_merge($clinic, $personal, ['Appearance']), ['/clinic', '/admin/carousel'], ['/guidance', '/facilities', '/admin/audit', '/dashboard']],
            'clinic_staff' => ['clinic_staff', ['clinic.queue.read'], array_merge($clinic, $personal), ['/clinic'], ['/guidance', '/facilities', '/admin', '/admin/audit', '/admin/carousel', '/dashboard']],
            'guidance_admin' => ['guidance_admin', ['guidance.announcements.manage', 'guidance.surveys.manage', 'reports.read', 'rbac.manage'], array_merge($guidance, $personal, ['Appearance']), ['/guidance', '/admin/carousel'], ['/clinic', '/facilities', '/admin/audit', '/dashboard']],
            'guidance_supervisor' => ['guidance_supervisor', ['guidance.announcements.manage', 'guidance.surveys.manage', 'reports.read'], array_merge($guidance, $personal), ['/guidance'], ['/clinic', '/facilities', '/admin', '/admin/audit', '/admin/carousel', '/dashboard']],
            'counsellor' => ['counsellor', ['guidance.cases.read'], array_merge($guidance, $personal), ['/guidance'], ['/clinic', '/facilities', '/admin', '/admin/audit', '/admin/carousel', '/dashboard']],
            'bmg_admin' => ['bmg_admin', ['facilities.read', 'reports.read'], array_merge($facilities, $personal), ['/facilities'], ['/clinic', '/guidance', '/admin', '/admin/audit', '/admin/carousel', '/dashboard']],
            'facilities_op' => ['facilities_op', ['facilities.read'], array_merge($facilities, $personal), ['/facilities'], ['/clinic', '/guidance', '/admin', '/admin/audit', '/admin/carousel', '/dashboard']],
            'audit_reader' => ['audit_reader', ['audit.read', 'reports.read'], array_merge($personal, ['Governance']), ['/admin', '/admin/audit'], ['/clinic', '/guidance', '/facilities', '/admin/carousel', '/dashboard']],
            'report_viewer' => ['report_viewer', ['reports.read'], array_merge($personal, ['Governance']), ['/dashboard'], ['/clinic', '/guidance', '/facilities', '/admin', '/admin/audit', '/admin/carousel']],
            'student' => ['student', [], $personal, ['/portal'], ['/clinic', '/guidance', '/facilities', '/admin', '/admin/audit', '/admin/carousel', '/dashboard']],
            'employee' => ['employee', [], $personal, ['/portal'], ['/clinic', '/guidance', '/facilities', '/admin', '/admin/audit', '/admin/carousel', '/dashboard']],
        ];
    }

    #[DataProvider('roles')]
    public function testSectionsFollowRoleOnEveryWorkstationPath(
        string $role,
        array $permissions,
        array $sections,
        array $links,
        array $denied
    ): void {
        $app = config('App');
        $app->baseURL = 'http://localhost/';
        $previous = session()->get('user');
        try {
            session()->set(['tenant_id' => 1, 'user' => [
                'id' => 1, 'person_id' => 1, 'tenant_id' => 1, 'logged_in' => true,
                'name' => 'Role Fixture', 'username' => 'fixture', 'role' => $role,
                'permissions' => $permissions,
            ]]);
            foreach (['clinic', 'guidance', 'facilities', 'dashboard'] as $path) {
                Services::injectMock('uri', new SiteURI($app, $path));
                $html = view('partials/sidebar');
                $hrefs = $this->navHrefs($html);
                foreach ($sections as $section) {
                    $this->assertStringContainsString($section, $html, "{$role} on /{$path} is missing {$section}");
                }
                foreach (['Clinic Workstation', 'Guidance Workstation', 'Facilities (BMG)', 'Governance', 'Appearance'] as $section) {
                    if (!in_array($section, $sections, true)) {
                        $this->assertStringNotContainsString($section, $html, "{$role} on /{$path} must not expose {$section}");
                    }
                }
                foreach ($links as $link) {
                    $this->assertTrue($this->hasLink($hrefs, $link), "{$role} on /{$path} is missing {$link}");
                }
                foreach ($denied as $link) {
                    $this->assertFalse($this->hasLink($hrefs, $link), "{$role} on /{$path} must not expose {$link}");
                }
            }
        } finally {
            Services::resetSingle('uri');
            if ($previous === null) session()->remove('user');
            else session()->set('user', $previous);
        }
    }

    /** @return list<string> */
    private function navHrefs(string $html): array
    {
        preg_match_all('/<a class="nav-link[^"]*" href="([^"]+)"/', $html, $matches);
        return array_map(static fn (string $href): string => html_entity_decode($href, ENT_QUOTES), $matches[1]);
    }

    private function hasLink(array $hrefs, string $link): bool
    {
        foreach ($hrefs as $href) {
            $path = (string) parse_url($href, PHP_URL_PATH);
            $path = preg_replace('~^.*?/index\.php~', '', $path) ?: $path;
            if ($path === $link || str_starts_with($path, $link . '/') || str_starts_with($path, $link . '?')) {
                return true;
            }
        }
        return false;
    }
}
