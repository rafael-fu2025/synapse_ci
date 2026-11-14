<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\GuidanceEvaluationTokenModel;
use App\Models\GuidanceServiceEvaluationModel;
use App\Models\PersonModel;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceServiceEvaluationService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceServiceEvaluationTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceServiceEvaluationService $service;
    private GuidanceEvaluationTokenModel $tokenModel;
    private GuidanceServiceEvaluationModel $evaluationModel;
    private PersonModel $personModel;
    private EncryptionService $encryptionService;

    /** @var list<int> */
    private array $studentPersonIds = [];
    private int $counselorAUserId;
    private int $counselorBUserId;
    private int $supervisorUserId;
    private int $adminUserId;
    private int $studentUserId;
    private int $nurseUserId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->tokenModel        = new GuidanceEvaluationTokenModel($this->db);
        $this->evaluationModel   = new GuidanceServiceEvaluationModel($this->db);
        $this->personModel       = new PersonModel($this->db);
        $this->encryptionService = new EncryptionService();

        $this->service = new GuidanceServiceEvaluationService(
            tokenModel: $this->tokenModel,
            evaluationModel: $this->evaluationModel,
            encryptionService: $this->encryptionService,
            db: $this->db
        );

        $unique = bin2hex(random_bytes(4));
        $now    = date('Y-m-d H:i:s');

        $this->studentPersonIds = [];
        for ($i = 1; $i <= 7; $i++) {
            $this->studentPersonIds[$i] = (int) $this->personModel->insert([
                'tenant_id'  => 1,
                'kind'       => 'student',
                'mis_id'     => "STU_{$i}_{$unique}",
                'first_name' => "Student{$i}",
                'last_name'  => "Eval_{$unique}",
                'email'      => "student_{$i}_{$unique}@example.com",
            ]);
        }

        $counselorAPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => "CNSA_{$unique}",
            'first_name' => 'Counselor',
            'last_name'  => "Alpha_{$unique}",
            'email'      => "counselor_a_{$unique}@example.com",
        ]);

        $counselorBPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => "CNSB_{$unique}",
            'first_name' => 'Counselor',
            'last_name'  => "Beta_{$unique}",
            'email'      => "counselor_b_{$unique}@example.com",
        ]);

        $supervisorPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => "SUP_{$unique}",
            'first_name' => 'Guidance',
            'last_name'  => "Supervisor_{$unique}",
            'email'      => "supervisor_{$unique}@example.com",
        ]);

        $adminPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => "ADM_{$unique}",
            'first_name' => 'Guidance',
            'last_name'  => "Admin_{$unique}",
            'email'      => "admin_{$unique}@example.com",
        ]);

        $nursePersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => "NRS_{$unique}",
            'first_name' => 'Clinic',
            'last_name'  => "Nurse_{$unique}",
            'email'      => "nurse_{$unique}@example.com",
        ]);

        $this->counselorAUserId = $this->insertUser($counselorAPersonId, "counselor_a_{$unique}", 'counsellor', $now);
        $this->counselorBUserId = $this->insertUser($counselorBPersonId, "counselor_b_{$unique}", 'counsellor', $now);
        $this->supervisorUserId = $this->insertUser($supervisorPersonId, "supervisor_{$unique}", 'guidance_supervisor', $now);
        $this->adminUserId      = $this->insertUser($adminPersonId, "admin_{$unique}", 'guidance_admin', $now);
        $this->studentUserId    = $this->insertUser($this->studentPersonIds[1], "student_1_{$unique}", 'student', $now);
        $this->nurseUserId      = $this->insertUser($nursePersonId, "nurse_{$unique}", 'nurse', $now);
    }

    private function insertUser(int $personId, string $username, string $role, string $now): int
    {
        $this->db->table('users')->insert([
            'tenant_id'     => 1,
            'person_id'     => $personId,
            'username'      => $username,
            'email'         => "{$username}@example.com",
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'role'          => $role,
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validPsychosocialPayload(array $overrides = []): array
    {
        return array_merge([
            'visit_type'               => 'walk_in',
            'rating_welcoming'         => 5,
            'rating_empathy'           => 4,
            'rating_clarity'           => 5,
            'rating_helpfulness'       => 4,
            'rating_environment'       => 5,
            'future_visit_willingness' => true,
            'qualitative_comments'     => 'Very supportive and welcoming atmosphere.',
            'academic_term'            => '1st Trimester',
            'academic_year'            => '2026-2027',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validCounselingPayload(array $overrides = []): array
    {
        return array_merge([
            'rating_rapport'                 => 5,
            'rating_understanding'           => 5,
            'rating_goal_progress'           => 4,
            'rating_confidentiality_comfort' => 5,
            'rating_overall_service'         => 5,
            'recommend_service'              => true,
            'qualitative_feedback'           => 'Helped me clarify my academic and personal goals.',
            'academic_term'                  => '1st Trimester',
            'academic_year'                  => '2026-2027',
        ], $overrides);
    }

    public function testSchemaUnlinkabilityAndPermissions(): void
    {
        $tables = $this->db->listTables();
        self::assertContains('guidance_evaluation_tokens', $tables);
        self::assertContains('guidance_service_evaluations', $tables);

        $tokenFields = $this->db->getFieldNames('guidance_evaluation_tokens');
        self::assertContains('id', $tokenFields);
        self::assertContains('tenant_id', $tokenFields);
        self::assertContains('episode_id', $tokenFields);
        self::assertContains('session_id', $tokenFields);
        self::assertContains('evaluation_type', $tokenFields);
        self::assertContains('provider_user_id', $tokenFields);
        self::assertContains('eligibility_hmac', $tokenFields);
        self::assertContains('token_hash', $tokenFields);
        self::assertContains('status', $tokenFields);
        self::assertNotContains('person_id', $tokenFields);
        self::assertNotContains('student_id', $tokenFields);

        $evalFields = $this->db->getFieldNames('guidance_service_evaluations');
        self::assertContains('id', $evalFields);
        self::assertContains('tenant_id', $evalFields);
        self::assertContains('evaluation_type', $evalFields);
        self::assertContains('form_code', $evalFields);
        self::assertContains('provider_user_id', $evalFields);
        self::assertContains('ratings_json', $evalFields);
        self::assertContains('boolean_response', $evalFields);
        self::assertContains('comments_cipher', $evalFields);
        self::assertContains('comment_moderation_status', $evalFields);
        self::assertContains('submitted_date', $evalFields);

        // Strictly verify NO linking columns exist on guidance_service_evaluations
        self::assertNotContains('person_id', $evalFields);
        self::assertNotContains('student_id', $evalFields);
        self::assertNotContains('session_id', $evalFields);
        self::assertNotContains('episode_id', $evalFields);
        self::assertNotContains('token_id', $evalFields);
        self::assertNotContains('token_hash', $evalFields);
        self::assertNotContains('eligibility_hmac', $evalFields);

        // Verify permissions are seeded
        $expectedPerms = [
            'guidance.evaluations.submit',
            'guidance.evaluations.view_aggregates',
            'guidance.evaluations.moderate',
        ];
        foreach ($expectedPerms as $code) {
            $perm = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
            self::assertNotNull($perm, "Permission {$code} must be seeded.");
        }
    }

    public function testOneTimeTokenIssuanceAndDuplicatePrevention(): void
    {
        $studentId = $this->studentPersonIds[1];

        $issued = $this->service->issueEvaluationToken(
            $studentId,
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            $this->counselorAUserId,
            ['episode_id' => 101, 'session_id' => 201]
        );

        self::assertNotEmpty($issued['token']);
        self::assertSame('issued', $issued['status']);
        self::assertSame('F-OGC-GPS-PEF-008-00', $issued['form_code']);
        self::assertSame(hash('sha256', $issued['token']), $issued['token_hash']);

        // Raw token must never be stored in the DB table
        $dbTokenRow = $this->tokenModel->find($issued['id']);
        self::assertNotNull($dbTokenRow);
        self::assertArrayNotHasKey('token', $dbTokenRow);
        self::assertSame($issued['token_hash'], $dbTokenRow['token_hash']);

        // Duplicate token issuance for same student + episode/session + evaluationType must throw 409
        try {
            $this->service->issueEvaluationToken(
                $studentId,
                GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
                $this->counselorAUserId,
                ['episode_id' => 101, 'session_id' => 201]
            );
            self::fail('Expected DomainException (409) on duplicate token issuance.');
        } catch (DomainException $e) {
            self::assertSame(409, $e->getCode());
        }

        // Same student CAN receive a token for a different evaluation type or different session
        $counselingToken = $this->service->issueEvaluationToken(
            $studentId,
            GuidanceServiceEvaluationService::TYPE_COUNSELING,
            $this->counselorAUserId,
            ['episode_id' => 101, 'session_id' => 201]
        );
        self::assertNotSame($issued['token'], $counselingToken['token']);
        self::assertSame('F-OGC-GCS-CEF-009-00', $counselingToken['form_code']);

        // Redeem the psychosocial token once
        $submitted = $this->service->submitEvaluation($issued['token'], $this->validPsychosocialPayload());
        self::assertSame('F-OGC-GPS-PEF-008-00', $submitted['form_code']);
        self::assertSame('pending_review', $submitted['comment_moderation_status']);

        // Token status in DB must now be 'redeemed'
        $afterRedeem = $this->tokenModel->find($issued['id']);
        self::assertSame('redeemed', $afterRedeem['status']);
        self::assertNotNull($afterRedeem['redeemed_at']);

        // Attempting to reuse a redeemed token must throw 409 DomainException
        try {
            $this->service->submitEvaluation($issued['token'], $this->validPsychosocialPayload());
            self::fail('Expected DomainException (409) when reusing a redeemed token.');
        } catch (DomainException $e) {
            self::assertSame(409, $e->getCode());
        }

        // Expired token cannot be redeemed
        $expiredIssued = $this->service->issueEvaluationToken(
            $this->studentPersonIds[2],
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            $this->counselorAUserId,
            [
                'episode_id' => 102,
                'session_id' => 202,
                'expires_at' => date('Y-m-d H:i:s', time() - 3600),
            ]
        );

        try {
            $this->service->submitEvaluation($expiredIssued['token'], $this->validPsychosocialPayload());
            self::fail('Expected DomainException (409) when redeeming an expired token.');
        } catch (DomainException $e) {
            self::assertSame(409, $e->getCode());
        }
    }

    public function testRatingAndFieldValidationForBothForms(): void
    {
        $psychToken = $this->service->issueEvaluationToken(
            $this->studentPersonIds[1],
            'F-OGC-GPS-PEF-008-00',
            $this->counselorAUserId,
            ['session_id' => 301]
        )['token'];

        // 1. Invalid rating > 5 on psychosocial form
        try {
            $this->service->submitEvaluation($psychToken, $this->validPsychosocialPayload([
                'rating_empathy' => 6,
            ]));
            self::fail('Expected InvalidArgumentException for rating > 5.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(422, $e->getCode());
        }

        // 2. Invalid rating < 1 on psychosocial form
        try {
            $this->service->submitEvaluation($psychToken, $this->validPsychosocialPayload([
                'rating_welcoming' => 0,
            ]));
            self::fail('Expected InvalidArgumentException for rating < 1.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(422, $e->getCode());
        }

        // 3. Invalid visit_type on psychosocial form
        try {
            $this->service->submitEvaluation($psychToken, $this->validPsychosocialPayload([
                'visit_type' => 'invalid_visit_kind',
            ]));
            self::fail('Expected InvalidArgumentException for invalid visit_type.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(422, $e->getCode());
        }

        // 4. Missing required rating on psychosocial form
        $missingFieldPayload = $this->validPsychosocialPayload();
        unset($missingFieldPayload['rating_environment']);
        try {
            $this->service->submitEvaluation($psychToken, $missingFieldPayload);
            self::fail('Expected InvalidArgumentException for missing rating_environment.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(422, $e->getCode());
        }

        // Valid psychosocial submission succeeds after failed validation attempts (token was not consumed)
        $psychEval = $this->service->submitEvaluation($psychToken, $this->validPsychosocialPayload([
            'visit_type'         => 'referred',
            'rating_welcoming'   => 1,
            'rating_empathy'     => 2,
            'rating_clarity'     => 3,
            'rating_helpfulness' => 4,
            'rating_environment' => 5,
        ]));
        self::assertSame('psychosocial_support_evaluation', $psychEval['evaluation_type']);
        self::assertSame('F-OGC-GPS-PEF-008-00', $psychEval['form_code']);

        // Counseling evaluation validation (F-OGC-GCS-CEF-009-00)
        $counsToken = $this->service->issueEvaluationToken(
            $this->studentPersonIds[1],
            'F-OGC-GCS-CEF-009-00',
            $this->counselorAUserId,
            ['session_id' => 302]
        )['token'];

        // 5. Out-of-range rating on counseling form
        try {
            $this->service->submitEvaluation($counsToken, $this->validCounselingPayload([
                'rating_goal_progress' => 9,
            ]));
            self::fail('Expected InvalidArgumentException for counseling rating > 5.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(422, $e->getCode());
        }

        // 6. Missing rating on counseling form
        $missingCounsPayload = $this->validCounselingPayload();
        unset($missingCounsPayload['rating_confidentiality_comfort']);
        try {
            $this->service->submitEvaluation($counsToken, $missingCounsPayload);
            self::fail('Expected InvalidArgumentException for missing counseling rating.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(422, $e->getCode());
        }

        // Valid counseling submission succeeds
        $counsEval = $this->service->submitEvaluation($counsToken, $this->validCounselingPayload([
            'rating_rapport'                 => 5,
            'rating_understanding'           => 4,
            'rating_goal_progress'           => 3,
            'rating_confidentiality_comfort' => 5,
            'rating_overall_service'         => 4,
            'recommend_service'              => false,
        ]));
        self::assertSame('counseling_evaluation', $counsEval['evaluation_type']);
        self::assertSame('F-OGC-GCS-CEF-009-00', $counsEval['form_code']);
        self::assertSame(0, (int) $counsEval['boolean_response']);
    }

    public function testEncryptedQualitativeCommentsContainNoPlaintext(): void
    {
        $token = $this->service->issueEvaluationToken(
            $this->studentPersonIds[1],
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            $this->counselorAUserId,
            ['session_id' => 401]
        )['token'];

        $secretComment = 'CONFIDENTIAL_FEEDBACK_998877: The counselor listened empathetically without judgment.';
        $eval = $this->service->submitEvaluation($token, $this->validPsychosocialPayload([
            'qualitative_comments' => $secretComment,
        ]));

        $rawRow = $this->evaluationModel->find($eval['id']);
        self::assertNotNull($rawRow);
        self::assertNotEmpty($rawRow['comments_cipher']);
        self::assertStringNotContainsString('CONFIDENTIAL_FEEDBACK_998877', (string) $rawRow['comments_cipher']);
        self::assertStringNotContainsString('listened empathetically', (string) $rawRow['comments_cipher']);

        $decrypted = $this->service->decryptComment((string) $rawRow['comments_cipher']);
        self::assertSame($secretComment, $decrypted);
    }

    public function testThresholdSuppressionAndCohortUnlockAtFiveResponses(): void
    {
        $supervisorActor = ['id' => $this->supervisorUserId, 'tenant_id' => 1];
        $counselorAActor = ['id' => $this->counselorAUserId, 'tenant_id' => 1];

        // 0 evaluations -> suppressed
        $agg0 = $this->service->getProviderAggregates(
            $this->counselorAUserId,
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            [],
            1,
            $counselorAActor
        );
        self::assertFalse($agg0['cohort_met']);
        self::assertSame(0, $agg0['total_responses']);
        self::assertSame(5, $agg0['minimum_required']);
        self::assertNull($agg0['averages']);
        self::assertNull($agg0['boolean_rate']);
        self::assertSame([], $agg0['comments']);

        // Submit 1..4 evaluations and approve their comments; verify all 1..4 remain suppressed
        $ratingsMatrix = [
            1 => ['w' => 5, 'e' => 5, 'c' => 5, 'h' => 5, 'env' => 5, 'bool' => true,  'vt' => 'walk_in'],
            2 => ['w' => 4, 'e' => 4, 'c' => 4, 'h' => 4, 'env' => 4, 'bool' => true,  'vt' => 'called'],
            3 => ['w' => 3, 'e' => 4, 'c' => 5, 'h' => 4, 'env' => 4, 'bool' => false, 'vt' => 'referred'],
            4 => ['w' => 5, 'e' => 5, 'c' => 4, 'h' => 5, 'env' => 5, 'bool' => true,  'vt' => 'follow_up'],
            5 => ['w' => 3, 'e' => 2, 'c' => 2, 'h' => 2, 'env' => 2, 'bool' => true,  'vt' => 'walk_in'],
        ];

        for ($i = 1; $i <= 4; $i++) {
            $r = $ratingsMatrix[$i];
            $tok = $this->service->issueEvaluationToken(
                $this->studentPersonIds[$i],
                GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
                $this->counselorAUserId,
                ['session_id' => 500 + $i]
            )['token'];

            $ev = $this->service->submitEvaluation($tok, [
                'visit_type'               => $r['vt'],
                'rating_welcoming'         => $r['w'],
                'rating_empathy'           => $r['e'],
                'rating_clarity'           => $r['c'],
                'rating_helpfulness'       => $r['h'],
                'rating_environment'       => $r['env'],
                'future_visit_willingness' => $r['bool'],
                'qualitative_comments'     => "Approved comment #{$i}",
            ]);

            $this->service->moderateComment((int) $ev['id'], 'approved', 1, $supervisorActor);

            $agg = $this->service->getProviderAggregates(
                $this->counselorAUserId,
                GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
                [],
                1,
                $counselorAActor
            );

            self::assertFalse($agg['cohort_met'], "Cohort must remain suppressed at {$i} evaluations.");
            self::assertSame($i, $agg['total_responses']);
            self::assertSame(5, $agg['minimum_required']);
            self::assertNull($agg['averages']);
            self::assertNull($agg['boolean_rate']);
            self::assertSame([], $agg['comments']);
        }

        // Submit 5th evaluation -> unlocks aggregate stats
        $r5 = $ratingsMatrix[5];
        $tok5 = $this->service->issueEvaluationToken(
            $this->studentPersonIds[5],
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            $this->counselorAUserId,
            ['session_id' => 505]
        )['token'];

        $ev5 = $this->service->submitEvaluation($tok5, [
            'visit_type'               => $r5['vt'],
            'rating_welcoming'         => $r5['w'],
            'rating_empathy'           => $r5['e'],
            'rating_clarity'           => $r5['c'],
            'rating_helpfulness'       => $r5['h'],
            'rating_environment'       => $r5['env'],
            'future_visit_willingness' => $r5['bool'],
            'qualitative_comments'     => 'Approved comment #5',
        ]);
        $this->service->moderateComment((int) $ev5['id'], 'approved', 1, $supervisorActor);

        $agg5 = $this->service->getProviderAggregates(
            $this->counselorAUserId,
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            [],
            1,
            $counselorAActor
        );

        self::assertTrue($agg5['cohort_met']);
        self::assertSame(5, $agg5['total_responses']);
        self::assertSame(5, $agg5['minimum_required']);
        self::assertNotNull($agg5['averages']);

        // Expected dimension averages:
        // welcoming: (5+4+3+5+3)/5 = 4.0
        // empathy: (5+4+4+5+2)/5 = 4.0
        // clarity: (5+4+5+4+2)/5 = 4.0
        // helpfulness: (5+4+4+5+2)/5 = 4.0
        // environment: (5+4+4+5+2)/5 = 4.0
        // overall_mean: 4.0
        self::assertSame(4.0, $agg5['averages']['rating_welcoming']);
        self::assertSame(4.0, $agg5['averages']['rating_empathy']);
        self::assertSame(4.0, $agg5['averages']['rating_clarity']);
        self::assertSame(4.0, $agg5['averages']['rating_helpfulness']);
        self::assertSame(4.0, $agg5['averages']['rating_environment']);
        self::assertSame(4.0, $agg5['averages']['overall_mean']);

        // Boolean rate: 4 true out of 5 = 0.8
        self::assertSame(0.8, $agg5['boolean_rate']);
        self::assertCount(5, $agg5['comments']);
        self::assertSame(2, $agg5['visit_type_distribution']['walk_in']);
        self::assertSame(1, $agg5['visit_type_distribution']['called']);
        self::assertSame(1, $agg5['visit_type_distribution']['referred']);
        self::assertSame(1, $agg5['visit_type_distribution']['follow_up']);
    }

    public function testCommentModerationGatingOnlyReturnsApprovedComments(): void
    {
        $supervisorActor = ['id' => $this->supervisorUserId, 'tenant_id' => 1];
        $counselorAActor = ['id' => $this->counselorAUserId, 'tenant_id' => 1];

        $evalIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $tok = $this->service->issueEvaluationToken(
                $this->studentPersonIds[$i],
                GuidanceServiceEvaluationService::TYPE_COUNSELING,
                $this->counselorAUserId,
                ['session_id' => 600 + $i]
            )['token'];

            $ev = $this->service->submitEvaluation($tok, $this->validCounselingPayload([
                'qualitative_feedback' => "Feedback statement #{$i}",
            ]));
            $evalIds[$i] = (int) $ev['id'];
        }

        // Moderate:
        // #1 -> approved
        // #2 -> redacted
        // #3 -> approved
        // #4 -> pending_review (untouched)
        // #5 -> redacted
        $this->service->moderateComment($evalIds[1], 'approved', 1, $supervisorActor);
        $this->service->moderateComment($evalIds[2], 'redacted', 1, $supervisorActor);
        $this->service->moderateComment($evalIds[3], 'approved', 1, $supervisorActor);
        $this->service->moderateComment($evalIds[5], 'redacted', 1, $supervisorActor);

        $agg = $this->service->getProviderAggregates(
            $this->counselorAUserId,
            GuidanceServiceEvaluationService::TYPE_COUNSELING,
            [],
            1,
            $counselorAActor
        );

        self::assertTrue($agg['cohort_met']);
        self::assertSame(5, $agg['total_responses']);
        self::assertSame([
            'Feedback statement #1',
            'Feedback statement #3',
        ], $agg['comments']);
        self::assertNotContains('Feedback statement #2', $agg['comments']);
        self::assertNotContains('Feedback statement #4', $agg['comments']);
        self::assertNotContains('Feedback statement #5', $agg['comments']);
    }

    public function testAccessControlForViewingAggregatesAndModeratingComments(): void
    {
        $counselorAActor = ['id' => $this->counselorAUserId, 'tenant_id' => 1];
        $counselorBActor = ['id' => $this->counselorBUserId, 'tenant_id' => 1];
        $supervisorActor = ['id' => $this->supervisorUserId, 'tenant_id' => 1];
        $adminActor      = ['id' => $this->adminUserId, 'tenant_id' => 1];
        $studentActor    = ['id' => $this->studentUserId, 'tenant_id' => 1];
        $nurseActor      = ['id' => $this->nurseUserId, 'tenant_id' => 1];

        // Create 1 evaluation for Counselor A
        $tok = $this->service->issueEvaluationToken(
            $this->studentPersonIds[1],
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            $this->counselorAUserId,
            ['session_id' => 701]
        )['token'];
        $ev = $this->service->submitEvaluation($tok, $this->validPsychosocialPayload(), 1, $studentActor);
        $evalId = (int) $ev['id'];

        // 1. Counselor A can view own aggregates
        $ownAgg = $this->service->getProviderAggregates(
            $this->counselorAUserId,
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            [],
            1,
            $counselorAActor
        );
        self::assertSame(1, $ownAgg['total_responses']);

        // 2. Counselor B is blocked from viewing Counselor A's aggregates (403)
        try {
            $this->service->getProviderAggregates(
                $this->counselorAUserId,
                GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
                [],
                1,
                $counselorBActor
            );
            self::fail('Expected DomainException (403) when Counselor B views Counselor A aggregates.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // 3. Supervisor and Admin can view Counselor A's aggregates
        $supAgg = $this->service->getProviderAggregates(
            $this->counselorAUserId,
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            [],
            1,
            $supervisorActor
        );
        self::assertSame(1, $supAgg['total_responses']);

        $admAgg = $this->service->getProviderAggregates(
            $this->counselorAUserId,
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            [],
            1,
            $adminActor
        );
        self::assertSame(1, $admAgg['total_responses']);

        // 4. Student and Nurse are blocked from viewing aggregates (403)
        foreach ([$studentActor, $nurseActor] as $unauthorizedViewer) {
            try {
                $this->service->getProviderAggregates(
                    $this->counselorAUserId,
                    GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
                    [],
                    1,
                    $unauthorizedViewer
                );
                self::fail('Expected DomainException (403) for unauthorized aggregate viewer.');
            } catch (DomainException $e) {
                self::assertSame(403, $e->getCode());
            }
        }

        // 5. Counselor, Student, and Nurse cannot moderate comments (403)
        foreach ([$counselorAActor, $counselorBActor, $studentActor, $nurseActor] as $nonModerator) {
            try {
                $this->service->moderateComment($evalId, 'approved', 1, $nonModerator);
                self::fail('Expected DomainException (403) when non-moderator attempts to moderate comment.');
            } catch (DomainException $e) {
                self::assertSame(403, $e->getCode());
            }
        }

        // 6. Supervisor and Admin can moderate comments
        $moderatedBySup = $this->service->moderateComment($evalId, 'approved', 1, $supervisorActor);
        self::assertSame('approved', $moderatedBySup['comment_moderation_status']);
        self::assertSame($this->supervisorUserId, (int) $moderatedBySup['moderated_by_user_id']);

        $moderatedByAdm = $this->service->moderateComment($evalId, 'redacted', 1, $adminActor);
        self::assertSame('redacted', $moderatedByAdm['comment_moderation_status']);
        self::assertSame($this->adminUserId, (int) $moderatedByAdm['moderated_by_user_id']);
    }
}
