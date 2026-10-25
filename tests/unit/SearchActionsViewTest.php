<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalContext;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;

/** DB-free presentation checks; route filters and services remain the authority. */
final class SearchActionsViewTest extends CIUnitTestCase
{
    public static function actionVisibility(): iterable
    {
        $personal = ['portal/appointments/clinic/create', 'portal/appointments/guidance/create'];
        $clinic = ['clinic/appointments/create', 'clinic/referrals/create'];
        $guidance = ['guidance/appointments/create', 'guidance/referrals/create', 'guidance/cases/create'];
        $allGrants = ['portal.appointments.create', 'clinic.appointments.write', 'guidance.schedule.manage',
            'referrals.create', 'guidance.cases.create'];

        yield 'no session identity' => [null, [], null, 'localhost', []];
        yield 'unknown role cannot use grants' => ['unknown', $allGrants, 17, 'localhost', []];
        yield 'guest cannot use grants' => ['guest', $allGrants, 17, 'localhost', []];
        yield 'student personal only' => ['student', $allGrants, 17, 'localhost', $personal];
        yield 'employee personal only' => ['employee', $allGrants, 17, 'localhost', $personal];
        yield 'personal grant missing' => ['student', [], 17, 'localhost', []];
        yield 'linked person missing' => ['employee', $allGrants, null, 'localhost', []];
        yield 'invalid linked person' => ['student', $allGrants, 0, 'localhost', []];
        yield 'clinic role without grants' => ['clinic_staff', [], 17, 'localhost', []];
        yield 'clinic staff' => ['clinic_staff', $allGrants, 17, 'localhost', [...$personal, ...$clinic]];
        yield 'clinic admin' => ['clinic_admin', $allGrants, 17, 'clinic.localhost', [...$personal, ...$clinic]];
        yield 'read grants are insufficient' => ['clinic_staff', ['clinic.appointments.read', 'referrals.read'], 17, 'localhost', []];
        yield 'referral grant independent' => ['clinic_staff', ['referrals.create'], 17, 'localhost', ['clinic/referrals/create']];
        yield 'appointment grant independent' => ['clinic_staff', ['clinic.appointments.write'], 17, 'localhost', ['clinic/appointments/create']];
        yield 'counsellor' => ['counsellor', $allGrants, 17, 'localhost', [...$personal, ...$guidance]];
        yield 'guidance admin' => ['guidance_admin', $allGrants, 17, 'guidance.localhost', [...$personal, ...$guidance]];
        yield 'guidance supervisor' => ['guidance_supervisor', $allGrants, 17, 'localhost', [...$personal, ...$guidance]];
        yield 'case grant independent' => ['counsellor', ['guidance.cases.create'], 17, 'localhost', ['guidance/cases/create']];
        yield 'guidance appointment grant independent' => ['counsellor', ['guidance.schedule.manage'], 17, 'localhost', ['guidance/appointments/create']];
        yield 'facilities cannot use care grants' => ['facilities_op', $allGrants, 17, 'localhost', $personal];
        yield 'reports cannot use care grants' => ['report_viewer', $allGrants, 17, 'localhost', $personal];
        yield 'main host hides workstations' => ['clinic_staff', $allGrants, 17, 'synapse.localhost', $personal];
        yield 'other host hides workstation' => ['counsellor', $allGrants, 17, 'clinic.localhost', $personal];
        yield 'superadmin bypass is staff only' => ['superadmin', [], 17, 'localhost', [...$clinic, ...$guidance]];
        yield 'personless superadmin' => ['superadmin', $allGrants, null, 'localhost', [...$clinic, ...$guidance]];
        yield 'superadmin clinic host' => ['superadmin', $allGrants, 17, 'clinic.localhost', [...$personal, ...$clinic]];
        yield 'superadmin guidance host' => ['superadmin', [], null, 'guidance.localhost', $guidance];
        yield 'superadmin main host' => ['superadmin', [], null, 'synapse.localhost', []];
        yield 'superadmin facilities host' => ['superadmin', [], null, 'bmg.localhost', []];
    }

    #[DataProvider('actionVisibility')]
    public function testOnlyAuthorizedGenericFormLinksAreRendered(
        ?string $role, array $permissions, ?int $personId, string $host, array $paths
    ): void {
        helper('url');
        $user = $role === null ? null : ['id' => 23, 'role' => $role, 'person_id' => $personId,
            'permissions' => $permissions, 'name' => 'private-name-sentinel', 'email' => 'private-email-sentinel'];
        $session = $this->createMock(Session::class);
        $session->method('get')->with('user')->willReturn($user);
        Services::injectMock('session', $session);
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('server', ['HTTP_HOST' => $host]);
        Services::injectMock('request', $request);

        $html = view('partials/search_actions', [], ['saveData' => false]);
        $dom = new DOMDocument();
        // libxml's HTML4 parser does not recognize the inert HTML5 template element.
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($dom->loadHTML($html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $xpath = new DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//*[@data-search-actions and @hidden]')->length);
        $anchors = $xpath->query('//*[@data-search-actions]/a');
        $this->assertCount(count($paths), $anchors);
        foreach ($anchors as $index => $anchor) {
            $path = $paths[$index];
            $portal = str_starts_with($path, 'portal/') ? 'main' : explode('/', $path)[0];
            $this->assertSame(PortalContext::urlFor($portal, $path), $anchor->getAttribute('href'));
            $this->assertSame($portal === 'main' ? 'Personal Health' : ucfirst($portal) . ' Workstation',
                $anchor->getAttribute('data-search-context'));
            $this->assertNotSame('', $anchor->getAttribute('data-search-label'));
            $this->assertSame($anchor->textContent, $anchor->getAttribute('data-search-label'));
            $this->assertTrue($anchor->hasAttribute('data-app-modal-open'));
            $this->assertNull(parse_url($anchor->getAttribute('href'), PHP_URL_QUERY));
            if ($host !== 'localhost') {
                $this->assertSame($portal === 'main' ? 'synapse.localhost' : $portal . '.localhost',
                    parse_url($anchor->getAttribute('href'), PHP_URL_HOST));
            }
        }
        foreach (['private-name-sentinel', 'private-email-sentinel', 'person_id', 'source_encounter_id', '<script', '<form'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $html);
        }
    }
}
