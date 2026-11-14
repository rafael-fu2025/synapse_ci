<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\Api\ApiContext;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\SurveyService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * @internal
 */
final class SurveyExternalUrlTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';
    protected $seed      = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private int $adminUserId;
    private int $studentUserId;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);

        $unique = bin2hex(random_bytes(4));
        $persons = new PersonModel();
        $users = new UserModel();

        $adminPersonId = (int) $persons->insert([
            'tenant_id' => 1,
            'kind' => 'employee',
            'mis_id' => 'SURV_ADM_' . $unique,
            'first_name' => 'Guidance',
            'last_name' => 'Admin',
        ]);
        $this->adminUserId = (int) $users->insert([
            'tenant_id' => 1,
            'person_id' => $adminPersonId,
            'username' => 'surv_adm_' . $unique,
            'role' => 'guidance_admin',
            'status' => 'active',
        ]);

        $studentPersonId = (int) $persons->insert([
            'tenant_id' => 1,
            'kind' => 'student',
            'mis_id' => 'SURV_STU_' . $unique,
            'first_name' => 'Student',
            'last_name' => 'Responder',
        ]);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('student_profiles')->insert([
            'tenant_id' => 1,
            'person_id' => $studentPersonId,
            'program' => 'BSIT',
            'year_level' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->studentUserId = (int) $users->insert([
            'tenant_id' => 1,
            'person_id' => $studentPersonId,
            'username' => 'surv_stu_' . $unique,
            'role' => 'student',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        ApiContext::clear();
        AuthenticatedUser::clear();
        session()->destroy();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function actingAs(int $userId): void
    {
        CurrentTenant::set(1);
        $actor = (new AuthenticatedUser())->forIdentity($userId, 1);
        self::assertNotNull($actor);
        ApiContext::set($actor, str_repeat('a', 64));
        $now = time();
        session()->set([
            'tenant_id' => 1,
            'user' => $actor,
            '_auth' => [
                'started_at' => $now,
                'last_seen_at' => $now,
                'version' => (int) ($actor['auth_version'] ?? 1),
                'session_id' => session_id(),
            ],
        ]);
    }

    public static function invalidExternalUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'ftp scheme' => ['ftp://docs.google.com/forms/123'];
        yield 'embedded space' => ['https://docs.google.com/forms/d/e/with space/viewform'];
        yield 'userinfo credentials' => ['https://user:secret@docs.google.com/forms/d/e/123/viewform'];
        yield 'too long' => ['https://docs.google.com/forms/' . str_repeat('a', 2040)];
    }

    #[DataProvider('invalidExternalUrls')]
    public function testInvalidExternalUrlsAreRejected(string $url): void
    {
        $this->actingAs($this->adminUserId);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);

        (new SurveyService())->create([
            'title' => 'External Check',
            'description' => 'Google Form',
            'audience' => 'all',
            'survey_mode' => 'external',
            'external_url' => $url,
            'questions' => [],
        ]);
    }

    public function testCreateSwitchPublishSubmitAndExportExternalSurvey(): void
    {
        $service = new SurveyService();
        $this->actingAs($this->adminUserId);

        $created = $service->create([
            'title' => 'Wellbeing Check Google Form',
            'description' => 'Complete this form in Google Forms.',
            'audience' => 'students',
            'survey_mode' => 'external',
            'external_url' => '  https://docs.google.com/forms/d/e/1FAIpQLSdExample/viewform  ',
            'questions' => [],
        ]);

        self::assertSame('draft', $created['status']);
        self::assertSame('https://docs.google.com/forms/d/e/1FAIpQLSdExample/viewform', $created['external_url']);
        self::assertSame([], $created['questions']);

        // Switch draft to internal mode (clears external_url) then back to forms.gle external link.
        $switchedInternal = $service->update((int) $created['id'], [
            'version' => $created['version'],
            'title' => 'Wellbeing Check',
            'description' => 'Internal questions temporarily',
            'audience' => 'students',
            'survey_mode' => 'internal',
            'external_url' => '',
            'questions' => [
                ['type' => 'text', 'label' => 'How are you today?', 'required' => true, 'options' => []],
            ],
        ]);
        self::assertNull($switchedInternal['external_url']);
        self::assertCount(1, $switchedInternal['questions']);

        $updatedExternal = $service->update((int) $created['id'], [
            'version' => $switchedInternal['version'],
            'title' => 'Wellbeing Check Google Form',
            'description' => 'Complete this form via short link.',
            'audience' => 'students',
            'survey_mode' => 'external',
            'external_url' => 'https://forms.gle/abc123XYZ',
            'questions' => [],
        ]);
        self::assertSame('https://forms.gle/abc123XYZ', $updatedExternal['external_url']);
        self::assertSame([], $updatedExternal['questions']);

        $service->publish((int) $created['id'], $updatedExternal['version']);

        // Targeted student sees external_url in available() and form(), can mark complete with [].
        $this->actingAs($this->studentUserId);
        $available = $service->available();
        $found = null;
        foreach ($available['items'] as $item) {
            if ((int) $item['id'] === (int) $created['id']) {
                $found = $item;
                break;
            }
        }
        self::assertNotNull($found);
        self::assertSame('https://forms.gle/abc123XYZ', $found['external_url']);

        $formSurvey = $service->form((int) $created['id']);
        self::assertSame('https://forms.gle/abc123XYZ', $formSurvey['external_url']);
        self::assertSame([], $formSurvey['questions']);

        try {
            $service->submit((int) $created['id'], ['unexpected']);
            self::fail('External survey should reject non-empty question payloads.');
        } catch (RuntimeException $e) {
            self::assertSame(422, $e->getCode());
        }

        $responseId = $service->submit((int) $created['id'], []);
        self::assertGreaterThan(0, $responseId);

        // Staff views response detail and exports CSV without error.
        $this->actingAs($this->adminUserId);
        $detail = $service->responseDetail((int) $created['id'], $responseId);
        self::assertTrue((bool) ($detail['answers']['_external_link_completed'] ?? false));
        self::assertSame('https://forms.gle/abc123XYZ', $detail['answers']['external_url'] ?? null);

        $analytics = $service->responses((int) $created['id']);
        self::assertSame(1, $analytics['analytics']['total_submissions']);
        self::assertSame([], $analytics['analytics']['questions']);

        $today = (new \DateTimeImmutable('now', \App\Services\Scheduling\SchedulingClock::timezone()))->format('Y-m-d');
        $export = $service->exportResponses((int) $created['id'], [
            'purpose' => 'External completion verification',
            'confirm_identified' => '1',
            'from' => $today,
            'to' => $today,
        ]);
        self::assertStringContainsString('"External Link"', $export['csv']);
        self::assertStringContainsString('"Completed via external form"', $export['csv']);
        self::assertStringContainsString('https://forms.gle/abc123XYZ', $export['csv']);
    }
}

