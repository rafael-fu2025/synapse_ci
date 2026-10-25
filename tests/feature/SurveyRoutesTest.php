<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthenticatedUser;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/SurveyWorkflowTest.php';

/** Runs the real route/filter/template stack only after the isolated database gate. */
final class SurveyRoutesTest extends SurveyTestCase
{
    use FeatureTestTrait;

    private function getAs(array $actor, string $route)
    {
        AuthenticatedUser::clear();
        return $this->withSession(['user' => $actor, 'tenant_id' => $this->tenant])->withHeaders([])->get($route);
    }

    private function postAs(array $actor, string $route, array $data = [], bool $csrf = true)
    {
        AuthenticatedUser::clear();
        $hash = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
        Services::resetSingle('security');
        if ($csrf) { $data[config('Security')->tokenName] = $hash; }
        return $this->withSession(['user' => $actor, 'tenant_id' => $this->tenant])
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->post($route, $data);
    }

    public function testStaffBuilderAndLifecycleRoutes(): void
    {
        $this->getAs($this->admin, 'guidance/surveys/create')->assertStatus(200);
        $create = $this->postAs($this->admin, 'guidance/surveys/store', $this->payload());
        $create->assertStatus(200);
        $created = $this->db->table('surveys')->where('tenant_id', $this->tenant)->orderBy('id', 'DESC')->get()->getRowArray();
        $id = (int) $created['id'];
        $this->assertSame(1, (int) $created['version']);
        $edit = $this->getAs($this->admin, 'guidance/surveys/' . $id . '/edit');
        $edit->assertStatus(200);
        $edit->assertSee('Add question');
        $this->assertStringNotContainsString('textarea name="questions_json"', $edit->getBody());
        $this->assertStringContainsString('name="version" value="1"', $edit->getBody());
        $this->postAs($this->admin, 'guidance/surveys/' . $id . '/update', $this->payload(['title' => 'Updated draft', 'version' => $created['version']]))->assertStatus(200);
        $this->postAs($this->admin, 'guidance/surveys/' . $id . '/update', $this->payload(['title' => 'Stale overwrite', 'version' => '1']))->assertStatus(409);
        $reviewedDraft = $this->db->table('surveys')->where('tenant_id', $this->tenant)->where('id', $id)->get()->getRowArray();
        $this->assertSame(2, (int) $reviewedDraft['version']);
        $draftListing = $this->getAs($this->admin, 'guidance/surveys');
        $draftListing->assertStatus(200);
        $this->assertStringContainsString('name="version" value="' . $reviewedDraft['version'] . '"', $draftListing->getBody());
        $this->postAs($this->admin, 'guidance/surveys/' . $id . '/publish', ['version' => $reviewedDraft['version']])->assertStatus(200);
        $this->getAs($this->admin, 'guidance/surveys/' . $id . '/edit')->assertStatus(409);
        $this->postAs($this->admin, 'guidance/surveys/' . $id . '/update', $this->payload())->assertStatus(409);
        $listing = $this->getAs($this->admin, 'guidance/surveys');
        $listing->assertStatus(200);
        $listing->assertSee('Updated draft');
        $this->assertStringContainsString('data-confirm=', $listing->getBody());
        $reviewedPublished = $this->db->table('surveys')->where('tenant_id', $this->tenant)->where('id', $id)->get()->getRowArray();
        $this->assertSame(3, (int) $reviewedPublished['version']);
        $this->assertStringContainsString('name="version" value="' . $reviewedPublished['version'] . '"', $listing->getBody());
        $this->postAs($this->admin, 'guidance/surveys/' . $id . '/close', ['version' => $reviewedPublished['version']])->assertStatus(200);
        $closed = $this->db->table('surveys')->where('tenant_id', $this->tenant)->where('id', $id)->get()->getRowArray();
        $this->assertSame('closed', $closed['status']);
        $this->assertSame(4, (int) $closed['version']);
    }

    public function testStudentSubmissionResultsAndNoAnswerSessionStorage(): void
    {
        $id = $this->published();
        $page = $this->getAs($this->student, 'portal/surveys/' . $id);
        $page->assertStatus(200);
        $page->assertSee('identified survey');
        $this->assertStringNotContainsString('window.confirm(', $page->getBody());
        $invalid = $this->postAs($this->student, 'portal/surveys/' . $id . '/submit', ['answers' => [0 => 'PRIVATE ANSWER']]);
        $invalid->assertStatus(422);
        $this->assertStringNotContainsString('PRIVATE ANSWER', json_encode(session()->get(), JSON_INVALID_UTF8_SUBSTITUTE));
        $this->postAs($this->student, 'portal/surveys/' . $id . '/submit', ['answers' => $this->validAnswers()])->assertStatus(200);
        $this->assertStringNotContainsString('PRIVATE SURVEY ANSWER', json_encode(session()->get(), JSON_INVALID_UTF8_SUBSTITUTE));
        $this->getAs($this->student, 'portal/surveys')->assertSee('Submitted');
        $this->postAs($this->student, 'portal/surveys/' . $id . '/submit', ['answers' => $this->validAnswers()])->assertStatus(409);
        $response = (int) $this->db->table('survey_responses')->where('tenant_id', $this->tenant)->get()->getRow('id');
        $this->getAs($this->admin, 'guidance/surveys/' . $id . '/responses')->assertSee('identified submissions');
        $detail = $this->getAs($this->admin, 'guidance/surveys/' . $id . '/responses/' . $response);
        $detail->assertStatus(200);
        $detail->assertSee('PRIVATE SURVEY ANSWER');
        $this->assertStringContainsString('no-store', $detail->response()->getHeaderLine('Cache-Control'));
        $denied = $this->getAs($this->student, 'guidance/surveys/' . $id . '/responses');
        $this->assertContains($denied->response()->getStatusCode(), [302, 403]);
    }

    public function testTakeScriptUsesPageLifecycleAndSharedSecurityOwners(): void
    {
        $id = $this->published();
        $page = $this->getAs($this->student, 'portal/surveys/' . $id);
        $page->assertStatus(200);
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $document->loadHTML($page->getBody()); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@id="main"]//form[@id="survey-response-form" and @autocomplete="off"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="main"]//script[contains(., "data-survey-submit")]')->length);
        $scripts = $xpath->query('//*[@id="page-scripts"]//script[contains(., "survey-response-form")]');
        $this->assertSame(1, $scripts->length, 'Progressive navigation must install the survey script after replacing main.');
        $script = $scripts->item(0)->textContent;
        foreach (['registerCleanup(dispose)', 'request?.abort()', "'synapse:before-swap'", 'SynapseCsrf?.fromResponse',
            'SynapseCsrf?.sync', 'disposed || inFlight', "redirect:'error'", "cache:'no-store'", 'completed = true'] as $contract) {
            $this->assertStringContainsString($contract, $script);
        }
        $this->assertStringNotContainsString('result.redirect', $script);
        $this->assertStringNotContainsString('window.confirm', $script);
    }

    public function testCsrfAndServerOwnedIdentity(): void
    {
        $id = $this->published();
        $this->postAs($this->admin, 'guidance/surveys/store', $this->payload(), false)->assertStatus(403);
        $this->postAs($this->student, 'portal/surveys/' . $id . '/submit', ['answers' => $this->validAnswers()], false)->assertStatus(403);
        $this->postAs($this->student, 'portal/surveys/' . $id . '/submit', [
            'answers' => $this->validAnswers(), 'person_id' => $this->admin['person_id'],
        ])->assertStatus(422);
        $this->assertSame(0, $this->db->table('survey_responses')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testCounsellorCannotManageOrReadResultsDespiteCachedSuperadminRole(): void
    {
        $id = $this->published();
        $counsellor = $this->account('counsellor');
        $this->postAs($counsellor, 'guidance/surveys/store', $this->payload())->assertStatus(403);
        $this->postAs($counsellor, 'guidance/surveys/' . $id . '/close')->assertStatus(403);
        // Browser GET denial may redirect at the outer permission filter.
        $response = $this->getAs($counsellor, 'guidance/surveys/' . $id . '/responses');
        $this->assertContains($response->response()->getStatusCode(), [302, 403]);
    }

    public function testGuidanceAdministratorsAndSupervisorsHaveIntendedGrants(): void
    {
        foreach (['guidance_admin', 'guidance_supervisor'] as $role) {
            $actor = $this->account($role, 'employee');
            $this->postAs($actor, 'guidance/surveys/store', $this->payload())->assertStatus(200);
            $this->getAs($actor, 'guidance/surveys')->assertStatus(200);
        }
    }

    public function testEscapingAndPagination(): void
    {
        $id = $this->published(['title' => '<script>alert(1)</script>']);
        $page = $this->getAs($this->student, 'portal/surveys');
        $page->assertStatus(200);
        $this->assertStringContainsString('&lt;script&gt;', $page->getBody());
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page->getBody());
        $this->assertStringContainsString('data-table-pagination', $page->getBody());
        $this->getAs($this->student, 'portal/surveys?page[]=bad')->assertStatus(422);
        $this->getAs($this->student, 'portal/surveys/' . ($id + 100000))->assertStatus(404);
    }
}
