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
use RuntimeException;

/**
 * @internal
 */
final class SurveyOtherOptionTest extends CIUnitTestCase
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

        $unique  = bin2hex(random_bytes(4));
        $persons = new PersonModel();
        $users   = new UserModel();

        $adminPersonId = (int) $persons->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'SOTH_ADM_' . $unique,
            'first_name' => 'Guidance',
            'last_name'  => 'Admin',
        ]);
        $this->adminUserId = (int) $users->insert([
            'tenant_id' => 1,
            'person_id' => $adminPersonId,
            'username'  => 'soth_adm_' . $unique,
            'role'      => 'guidance_admin',
            'status'    => 'active',
        ]);

        $studentPersonId = (int) $persons->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'SOTH_STU_' . $unique,
            'first_name' => 'Student',
            'last_name'  => 'Responder',
        ]);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('student_profiles')->insert([
            'tenant_id'  => 1,
            'person_id'  => $studentPersonId,
            'program'    => 'BSIT',
            'year_level' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->studentUserId = (int) $users->insert([
            'tenant_id' => 1,
            'person_id' => $studentPersonId,
            'username'  => 'soth_stu_' . $unique,
            'role'      => 'student',
            'status'    => 'active',
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
            'user'      => $actor,
            '_auth'     => [
                'started_at'   => $now,
                'last_seen_at' => $now,
                'version'      => (int) ($actor['auth_version'] ?? 1),
                'session_id'   => session_id(),
            ],
        ]);
    }

    public function testStudentCanTypeCustomOtherAnswerAndAnalyticsCountsAndListsSamples(): void
    {
        $this->actingAs($this->adminUserId);
        $service = new SurveyService();
        $created = $service->create([
            'title'       => 'Other Option Survey',
            'description' => 'Tests explicit allow_other and legacy Other option.',
            'audience'    => 'students',
            'survey_mode' => 'internal',
            'questions'   => [
                [
                    'type'        => 'single',
                    'label'       => 'Preferred channel',
                    'required'    => '1',
                    'allow_other' => '1',
                    'options'     => ['Email', 'SMS'],
                ],
                [
                    'type'        => 'multiple',
                    'label'       => 'Topics of interest',
                    'required'    => '1',
                    'allow_other' => '1',
                    'options'     => ['Peer tutoring', 'Counseling'],
                ],
                [
                    'type'     => 'single',
                    'label'    => 'How did you hear about us?',
                    'required' => '1',
                    'options'  => ['Classmates', 'Other (please specify)'],
                ],
            ],
        ]);
        $surveyId = (int) $created['id'];
        $service->publish($surveyId, (int) $created['version']);

        $this->actingAs($this->studentUserId);

        // Selecting Other without specifying custom text must fail with 422.
        try {
            $service->submit($surveyId, [
                0 => '__other__',
                1 => ['0'],
                2 => '0',
            ], [0 => '   ']);
            self::fail('Expected 422 when Other answer text is blank.');
        } catch (RuntimeException $e) {
            self::assertSame(422, $e->getCode());
            self::assertStringContainsString('Other', $e->getMessage());
        }

        // Selecting __other__ AND legacy Other option with custom text succeeds.
        $responseId = $service->submit(
            $surveyId,
            [
                0 => '__other__',
                1 => ['0', '__other__'],
                2 => '1',
            ],
            [
                0 => 'Discord @stu',
                1 => 'Time management workshop',
                2 => 'Department bulletin board',
            ]
        );
        self::assertGreaterThan(0, $responseId);

        $studentForm = $service->form($surveyId);
        self::assertTrue($studentForm['submitted']);
        self::assertNotEmpty($studentForm['submitted_at']);
        self::assertIsArray($studentForm['submitted_answers']);
        self::assertSame('Other: Discord @stu', $studentForm['submitted_answers'][0]['value']);
        self::assertSame(['Peer tutoring', 'Other: Time management workshop'], $studentForm['submitted_answers'][1]['value']);
        self::assertSame('Other: Department bulletin board', $studentForm['submitted_answers'][2]['value']);

        $html = view('surveys/take', ['title' => $studentForm['title'], 'survey' => $studentForm]);
        self::assertStringContainsString('Your submitted answers', $html);
        self::assertStringContainsString('Other: Discord @stu', $html);
        self::assertStringContainsString('Other: Time management workshop', $html);
        self::assertStringContainsString('Other: Department bulletin board', $html);
        self::assertStringNotContainsString('<h5 class="card-title">', $html);
        self::assertStringNotContainsString('You may submit once; answers cannot be changed after submission.', $html);

        $this->actingAs($this->adminUserId);
        $detail = $service->responseDetail($surveyId, $responseId);
        self::assertSame('Other: Discord @stu', $detail['answers'][0]['value']);
        self::assertSame(['Peer tutoring', 'Other: Time management workshop'], $detail['answers'][1]['value']);
        self::assertSame('Other: Department bulletin board', $detail['answers'][2]['value']);

        $analytics = $service->responses($surveyId)['analytics'];
        self::assertSame(1, $analytics['questions'][0]['counts']['Other (please specify)']);
        self::assertSame('Discord @stu', $analytics['questions'][0]['other_samples'][0]['text']);

        self::assertSame(1, $analytics['questions'][1]['counts']['Peer tutoring']);
        self::assertSame(1, $analytics['questions'][1]['counts']['Other (please specify)']);
        self::assertSame('Time management workshop', $analytics['questions'][1]['other_samples'][0]['text']);

        self::assertSame(1, $analytics['questions'][2]['counts']['Other (please specify)']);
        self::assertSame('Department bulletin board', $analytics['questions'][2]['other_samples'][0]['text']);
    }
}
