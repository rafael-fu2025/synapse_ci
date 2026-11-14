<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\GuidanceMultipleIntelligenceAssignmentModel;
use App\Models\GuidanceMultipleIntelligenceSubmissionModel;
use App\Models\PersonModel;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceMultipleIntelligenceService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceMultipleIntelligenceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceMultipleIntelligenceService $service;
    private GuidanceMultipleIntelligenceAssignmentModel $assignmentModel;
    private GuidanceMultipleIntelligenceSubmissionModel $submissionModel;
    private PersonModel $personModel;
    private EncryptionService $encryptionService;

    private int $studentPersonId;
    private int $studentUserId;
    private int $otherPersonId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->assignmentModel = new GuidanceMultipleIntelligenceAssignmentModel($this->db);
        $this->submissionModel = new GuidanceMultipleIntelligenceSubmissionModel($this->db);
        $this->personModel = new PersonModel($this->db);
        $this->encryptionService = new EncryptionService();

        $this->service = new GuidanceMultipleIntelligenceService(
            assignmentModel: $this->assignmentModel,
            submissionModel: $this->submissionModel,
            encryptionService: $this->encryptionService,
            db: $this->db
        );

        $unique = bin2hex(random_bytes(4));
        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'STU_' . $unique,
            'first_name' => 'Test',
            'last_name'  => 'Student_' . $unique,
            'email'      => 'student_' . $unique . '@example.com',
        ]);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('users')->insert([
            'tenant_id'  => 1,
            'person_id'  => $this->studentPersonId,
            'username'   => 'mi_stu_' . $unique,
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->studentUserId = (int) $this->db->insertID();

        $this->otherPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'OTH_' . $unique,
            'first_name' => 'Other',
            'last_name'  => 'Student_' . $unique,
            'email'      => 'other_' . $unique . '@example.com',
        ]);
    }

    public function testDatabaseTablesAndColumnsExist(): void
    {
        $tables = $this->db->listTables();
        self::assertContains('guidance_mi_assignments', $tables);
        self::assertContains('guidance_mi_submissions', $tables);

        $assignmentFields = $this->db->getFieldNames('guidance_mi_assignments');
        self::assertContains('id', $assignmentFields);
        self::assertContains('tenant_id', $assignmentFields);
        self::assertContains('person_id', $assignmentFields);
        self::assertContains('assigned_by_user_id', $assignmentFields);
        self::assertContains('status', $assignmentFields);
        self::assertContains('academic_term', $assignmentFields);
        self::assertContains('academic_year', $assignmentFields);
        self::assertContains('assigned_at', $assignmentFields);
        self::assertContains('due_at', $assignmentFields);
        self::assertContains('completed_at', $assignmentFields);

        $submissionFields = $this->db->getFieldNames('guidance_mi_submissions');
        self::assertContains('id', $submissionFields);
        self::assertContains('tenant_id', $submissionFields);
        self::assertContains('assignment_id', $submissionFields);
        self::assertContains('person_id', $submissionFields);
        self::assertContains('status', $submissionFields);
        self::assertContains('draft_responses_cipher', $submissionFields);
        self::assertContains('scores_json', $submissionFields);
        self::assertContains('domain_scores_json', $submissionFields);
        self::assertContains('attested_by_user_id', $submissionFields);
        self::assertContains('attested_at', $submissionFields);
        self::assertContains('attestation_statement_hash', $submissionFields);
        self::assertContains('snapshot_hash', $submissionFields);
        self::assertContains('form_instance_id', $submissionFields);
    }

    public function testPermissionsSeededCorrectly(): void
    {
        $codes = [
            'guidance.assessments.assign',
            'guidance.assessments.take',
            'guidance.assessments.view',
        ];

        foreach ($codes as $code) {
            $perm = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
            self::assertNotNull($perm, "Permission {$code} should be seeded.");
        }

        // Check counsellor has assign, take, view
        $counsellorPerms = $this->db->table('role_permissions')
            ->join('permissions', 'permissions.id = role_permissions.permission_id')
            ->where('role', 'counsellor')
            ->select('permissions.code')
            ->get()->getResultArray();
        $counsellorCodes = array_column($counsellorPerms, 'code');
        self::assertContains('guidance.assessments.assign', $counsellorCodes);
        self::assertContains('guidance.assessments.take', $counsellorCodes);
        self::assertContains('guidance.assessments.view', $counsellorCodes);

        // Check student has take, view
        $studentPerms = $this->db->table('role_permissions')
            ->join('permissions', 'permissions.id = role_permissions.permission_id')
            ->where('role', 'student')
            ->select('permissions.code')
            ->get()->getResultArray();
        $studentCodes = array_column($studentPerms, 'code');
        self::assertContains('guidance.assessments.take', $studentCodes);
        self::assertContains('guidance.assessments.view', $studentCodes);
        self::assertNotContains('guidance.assessments.assign', $studentCodes);
    }

    public function testCatalogReturnsVerbatim80ItemsAnd8Domains(): void
    {
        $catalog = $this->service->getSurveyCatalog();

        self::assertSame(80, $catalog['total_items']);
        self::assertCount(80, $catalog['items']);
        self::assertCount(8, $catalog['domains']);

        // Check scale
        self::assertCount(5, $catalog['scale']);
        self::assertSame(0, $catalog['scale'][0]['value']);
        self::assertSame('Never', $catalog['scale'][0]['label']);
        self::assertSame(4, $catalog['scale'][4]['value']);
        self::assertSame('Always', $catalog['scale'][4]['label']);

        // Check domain modulo mapping
        $expectedMappings = [
            1 => 'logical_mathematical',
            2 => 'linguistic',
            3 => 'spatial',
            4 => 'musical',
            5 => 'bodily_kinesthetic',
            6 => 'interpersonal',
            7 => 'intrapersonal',
            8 => 'naturalist',
            9 => 'logical_mathematical',
            73 => 'logical_mathematical',
            74 => 'linguistic',
            75 => 'spatial',
            76 => 'musical',
            77 => 'bodily_kinesthetic',
            78 => 'interpersonal',
            79 => 'intrapersonal',
            80 => 'naturalist',
        ];

        foreach ($expectedMappings as $itemNum => $expectedDomain) {
            self::assertSame($expectedDomain, GuidanceMultipleIntelligenceService::getDomainKeyForItem($itemNum));
        }

        // Verify verbatim text of sample items across 1..80
        self::assertSame('I keep my things neat and orderly.', GuidanceMultipleIntelligenceService::ITEMS[1]);
        self::assertSame('I enjoy reading all kinds of materials.', GuidanceMultipleIntelligenceService::ITEMS[2]);
        self::assertSame('I can imagine ideas in my mind.', GuidanceMultipleIntelligenceService::ITEMS[3]);
        self::assertSame('I easily pick up on patterns.', GuidanceMultipleIntelligenceService::ITEMS[4]);
        self::assertSame('I enjoy making things with my hands.', GuidanceMultipleIntelligenceService::ITEMS[5]);
        self::assertSame('I learn best interacting with others.', GuidanceMultipleIntelligenceService::ITEMS[6]);
        self::assertSame('I am keenly aware of my moral beliefs.', GuidanceMultipleIntelligenceService::ITEMS[7]);
        self::assertSame('I enjoy organizing things by common traits.', GuidanceMultipleIntelligenceService::ITEMS[8]);
        self::assertSame('I’ve always been interested in playing an instrument.', GuidanceMultipleIntelligenceService::ITEMS[28]);
        self::assertSame('I can’t begin an assignment until all my questions are answered.', GuidanceMultipleIntelligenceService::ITEMS[49]);
        self::assertSame('I am a “team player.”', GuidanceMultipleIntelligenceService::ITEMS[54]);
        self::assertSame('I enjoy working on a garden.', GuidanceMultipleIntelligenceService::ITEMS[80]);

        // Verify all 80 items are non-empty strings and partition cleanly into 8 domains of 10 items each
        $seenItems = [];
        foreach ($catalog['domains'] as $domain) {
            self::assertCount(10, $domain['items']);
            self::assertSame(40, $domain['max_score']);
            foreach ($domain['items'] as $itemId) {
                self::assertSame($domain['key'], GuidanceMultipleIntelligenceService::getDomainKeyForItem($itemId));
                $seenItems[] = $itemId;
            }
        }
        sort($seenItems);
        self::assertSame(range(1, 80), $seenItems);
    }

    public function testAssignSurveyEnforcesSingleActiveAssignment(): void
    {
        $assignment = $this->service->assignSurvey($this->studentPersonId, [
            'assigned_by_user_id' => 1,
            'academic_term' => '1st Trimester',
            'academic_year' => '2026-2027',
        ]);

        self::assertNotNull($assignment);
        self::assertSame('assigned', $assignment['status']);
        self::assertSame($this->studentPersonId, (int) $assignment['person_id']);

        // Duplicate active assignment attempt must fail with DomainException
        $this->expectException(DomainException::class);
        $this->expectExceptionCode(409);
        $this->service->assignSurvey($this->studentPersonId, [
            'assigned_by_user_id' => 1,
        ]);
    }

    public function testSaveDraftEncryptsResponses(): void
    {
        $assignment = $this->service->assignSurvey($this->studentPersonId);
        $assignmentId = (int) $assignment['id'];

        $partialResponses = [
            1 => 4,
            2 => 3,
            3 => 2,
            4 => 1,
            5 => 0,
            9 => 4,
        ];

        $sub = $this->service->saveDraft($assignmentId, $partialResponses);
        self::assertSame('draft', $sub['status']);

        // Inspect raw database row to ensure encryption
        $rawRow = $this->submissionModel->scoped(1)->where('assignment_id', $assignmentId)->first();
        self::assertNotNull($rawRow);
        self::assertNotEmpty($rawRow['draft_responses_cipher']);
        self::assertStringNotContainsString('I keep my things neat', $rawRow['draft_responses_cipher']);
        self::assertStringNotContainsString('"1":4', $rawRow['draft_responses_cipher']);

        // Assignment status should have progressed to in_progress
        $updatedAssignment = $this->assignmentModel->find($assignmentId);
        self::assertSame('in_progress', $updatedAssignment['status']);

        // Decrypt via getDraft
        $decryptedDraft = $this->service->getDraft($assignmentId);
        self::assertNotNull($decryptedDraft);
        self::assertSame(4, $decryptedDraft[1]);
        self::assertSame(3, $decryptedDraft[2]);
        self::assertSame(2, $decryptedDraft[3]);
        self::assertSame(1, $decryptedDraft[4]);
        self::assertSame(0, $decryptedDraft[5]);
        self::assertSame(4, $decryptedDraft[9]);
    }

    public function testSaveDraftValidatesScoreRange(): void
    {
        $assignment = $this->service->assignSurvey($this->studentPersonId);
        $assignmentId = (int) $assignment['id'];

        $this->expectException(InvalidArgumentException::class);
        $this->service->saveDraft($assignmentId, [1 => 5]); // Invalid score > 4
    }

    public function testFinalizeRequiresAll80Items(): void
    {
        $assignment = $this->service->assignSurvey($this->studentPersonId);
        $assignmentId = (int) $assignment['id'];

        // Only answer 79 items
        $incomplete = [];
        for ($i = 1; $i <= 79; $i++) {
            $incomplete[$i] = 2;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->service->finalizeAssessment(
            $assignmentId,
            $incomplete,
            'I confirm that all responses submitted are my own.'
        );
    }

    public function testFinalizeRequiresAttestationStatement(): void
    {
        $assignment = $this->service->assignSurvey($this->studentPersonId);
        $assignmentId = (int) $assignment['id'];

        $all80 = [];
        for ($i = 1; $i <= 80; $i++) {
            $all80[$i] = 3;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->service->finalizeAssessment(
            $assignmentId,
            $all80,
            '   ' // Empty statement
        );
    }

    public function testFinalizeComputesModulo8DomainScoresAndCompletes(): void
    {
        $assignment = $this->service->assignSurvey($this->studentPersonId);
        $assignmentId = (int) $assignment['id'];

        // Construct responses where:
        // logical_mathematical items (1, 9, 17, 25, 33, 41, 49, 57, 65, 73) score 4 each -> total 40
        // linguistic items (2, 10, 18, 26, 34, 42, 50, 58, 66, 74) score 3 each -> total 30
        // spatial items score 2 each -> total 20
        // musical items score 1 each -> total 10
        // bodily_kinesthetic items score 0 each -> total 0
        // interpersonal items score 4 each -> total 40
        // intrapersonal items score 2 each -> total 20
        // naturalist items score 3 each -> total 30
        $all80 = [];
        for ($i = 1; $i <= 80; $i++) {
            $rem = $i % 8;
            $all80[$i] = match ($rem) {
                1 => 4, // LM
                2 => 3, // Ling
                3 => 2, // Spa
                4 => 1, // Mus
                5 => 0, // B-K
                6 => 4, // Inter
                7 => 2, // Intra
                0 => 3, // Nat
            };
        }

        $statement = 'I, Test Student, digitally attest that these responses represent my honest reflections.';
        $finalized = $this->service->finalizeAssessment(
            $assignmentId,
            $all80,
            $statement,
            1,
            ['id' => 99, 'person_id' => $this->studentPersonId, 'role' => 'student']
        );

        self::assertSame('finalized', $finalized['status']);
        self::assertNotNull($finalized['domain_scores']);
        self::assertSame(40, $finalized['domain_scores']['logical_mathematical']);
        self::assertSame(30, $finalized['domain_scores']['linguistic']);
        self::assertSame(20, $finalized['domain_scores']['spatial']);
        self::assertSame(10, $finalized['domain_scores']['musical']);
        self::assertSame(0, $finalized['domain_scores']['bodily_kinesthetic']);
        self::assertSame(40, $finalized['domain_scores']['interpersonal']);
        self::assertSame(20, $finalized['domain_scores']['intrapersonal']);
        self::assertSame(30, $finalized['domain_scores']['naturalist']);

        // Check assignment updated to completed
        $updatedAssign = $this->assignmentModel->find($assignmentId);
        self::assertSame('completed', $updatedAssign['status']);
        self::assertNotNull($updatedAssign['completed_at']);

        // Check attestation statement hash and snapshot hash
        self::assertNotEmpty($finalized['attestation_statement_hash']);
        self::assertSame(hash('sha256', $statement), $finalized['attestation_statement_hash']);
        self::assertNotEmpty($finalized['snapshot_hash']);
        self::assertSame(99, (int) $finalized['attested_by_user_id']);

        // Double finalize should throw DomainException
        $this->expectException(DomainException::class);
        $this->service->finalizeAssessment($assignmentId, $all80, $statement);
    }

    public function testGetResultsReturnsAll8DomainsWithoutTruncationOrDiagnosticLabels(): void
    {
        $assignment = $this->service->assignSurvey($this->studentPersonId);
        $assignmentId = (int) $assignment['id'];

        $all80 = [];
        for ($i = 1; $i <= 80; $i++) {
            $all80[$i] = ($i % 5); // 0, 1, 2, 3, 4 distributed
        }

        $statement = 'Student attestation verified.';
        $finalized = $this->service->finalizeAssessment($assignmentId, $all80, $statement, 1, ['id' => $this->studentUserId, 'person_id' => $this->studentPersonId, 'role' => 'student']);

        $results = $this->service->getResults((int) $finalized['id'], 1);

        self::assertArrayHasKey('submission', $results);
        self::assertArrayHasKey('assignment', $results);
        self::assertArrayHasKey('domains', $results);
        self::assertArrayHasKey('domain_scores', $results);
        self::assertArrayHasKey('scores', $results);
        self::assertArrayHasKey('attestation', $results);

        // Strictly verify all 8 domains are returned without top-3 truncation
        self::assertCount(8, $results['domains']);
        $domainKeys = array_column($results['domains'], 'key');
        self::assertSame([
            'logical_mathematical',
            'linguistic',
            'spatial',
            'musical',
            'bodily_kinesthetic',
            'interpersonal',
            'intrapersonal',
            'naturalist',
        ], $domainKeys);

        foreach ($results['domains'] as $d) {
            self::assertGreaterThanOrEqual(0, $d['score']);
            self::assertLessThanOrEqual(40, $d['score']);
            self::assertSame(40, $d['max_score']);
            self::assertNotEmpty($d['description']);
            self::assertNotEmpty($d['educational_suggestions']);
            self::assertNotEmpty($d['characteristics']);

            // Strictly NO diagnostic labels
            self::assertArrayNotHasKey('diagnostic_label', $d);
            self::assertArrayNotHasKey('diagnosis', $d);
            self::assertArrayNotHasKey('deficiency', $d);
            self::assertArrayNotHasKey('pathology', $d);
        }

        // Test getResultsByAssignment
        $resultsByAssign = $this->service->getResultsByAssignment($assignmentId, 1);
        self::assertSame($results['submission']['id'], $resultsByAssign['submission']['id']);
    }

    public function testDraftSaveResumptionAndFinalizationLifecycle(): void
    {
        $assignment = $this->service->assignSurvey($this->studentPersonId);
        $assignmentId = (int) $assignment['id'];

        // Step 1: Save first partial draft (items 1..20)
        $draft1 = [];
        for ($i = 1; $i <= 20; $i++) {
            $draft1[$i] = 2;
        }
        $this->service->saveDraft($assignmentId, $draft1);

        $resumed1 = $this->service->getDraft($assignmentId);
        self::assertNotNull($resumed1);
        self::assertCount(20, $resumed1);
        self::assertSame(2, $resumed1[1]);
        self::assertSame(2, $resumed1[20]);

        // Step 2: Resume and update draft (modify item 1 to 4, add items 21..50)
        $draft2 = $resumed1;
        $draft2[1] = 4;
        for ($i = 21; $i <= 50; $i++) {
            $draft2[$i] = 3;
        }
        $this->service->saveDraft($assignmentId, $draft2);

        $resumed2 = $this->service->getDraft($assignmentId);
        self::assertNotNull($resumed2);
        self::assertCount(50, $resumed2);
        self::assertSame(4, $resumed2[1]);
        self::assertSame(3, $resumed2[50]);

        // Step 3: Resume and complete all 80 items, then finalize
        $completeResponses = $resumed2;
        for ($i = 51; $i <= 80; $i++) {
            $completeResponses[$i] = 4;
        }

        $finalized = $this->service->finalizeAssessment(
            $assignmentId,
            $completeResponses,
            'I attest that I completed all 80 items myself.'
        );

        self::assertSame('finalized', $finalized['status']);
        self::assertNull($finalized['draft_responses_cipher']);
        self::assertNull($this->service->getDraft($assignmentId));
    }

    public function testReassignmentAllowedAfterCompletion(): void
    {
        $first = $this->service->assignSurvey($this->studentPersonId, [
            'academic_term' => '1st Trimester',
            'academic_year' => '2026-2027',
        ]);
        $firstId = (int) $first['id'];

        $all80 = array_fill(1, 80, 2);
        $this->service->finalizeAssessment($firstId, $all80, 'Completed 1st trimester survey.');

        // Now that first assignment is completed, a new assignment is allowed
        $second = $this->service->assignSurvey($this->studentPersonId, [
            'academic_term' => '2nd Trimester',
            'academic_year' => '2026-2027',
        ]);
        self::assertNotSame($firstId, (int) $second['id']);
        self::assertSame('assigned', $second['status']);
    }

    public function testAccessControlStudentSelfTakingVsGuidancePractitionerViewing(): void
    {
        $unique = bin2hex(random_bytes(4));

        // Create person records for counselor, supervisor, and nurse
        $counselorPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'CNS_' . $unique,
            'first_name' => 'Guidance',
            'last_name'  => 'Counselor_' . $unique,
            'email'      => 'counselor_' . $unique . '@example.com',
        ]);

        $supervisorPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'SUP_' . $unique,
            'first_name' => 'Guidance',
            'last_name'  => 'Supervisor_' . $unique,
            'email'      => 'supervisor_' . $unique . '@example.com',
        ]);

        $nursePersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'NRS_' . $unique,
            'first_name' => 'Clinic',
            'last_name'  => 'Nurse_' . $unique,
            'email'      => 'nurse_' . $unique . '@example.com',
        ]);

        // Reuse student A user from setUp() (already linked to $this->studentPersonId) and create remaining users
        $now            = date('Y-m-d H:i:s');
        $studentAUserId = $this->studentUserId;

        $this->db->table('users')->insert([
            'tenant_id'     => 1,
            'person_id'     => $this->otherPersonId,
            'username'      => 'student_b_' . $unique,
            'email'         => 'student_b_' . $unique . '@example.com',
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'role'          => 'student',
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $studentBUserId = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'tenant_id'     => 1,
            'person_id'     => $counselorPersonId,
            'username'      => 'counselor_' . $unique,
            'email'         => 'counselor_' . $unique . '@example.com',
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'role'          => 'counsellor',
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $counselorUserId = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'tenant_id'     => 1,
            'person_id'     => $supervisorPersonId,
            'username'      => 'supervisor_' . $unique,
            'email'         => 'supervisor_' . $unique . '@example.com',
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'role'          => 'guidance_supervisor',
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $supervisorUserId = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'tenant_id'     => 1,
            'person_id'     => $nursePersonId,
            'username'      => 'nurse_' . $unique,
            'email'         => 'nurse_' . $unique . '@example.com',
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'role'          => 'nurse',
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $nurseUserId = (int) $this->db->insertID();

        $studentAActor   = ['id' => $studentAUserId, 'tenant_id' => 1];
        $studentBActor   = ['id' => $studentBUserId, 'tenant_id' => 1];
        $counselorActor  = ['id' => $counselorUserId, 'tenant_id' => 1];
        $supervisorActor = ['id' => $supervisorUserId, 'tenant_id' => 1];
        $nurseActor      = ['id' => $nurseUserId, 'tenant_id' => 1];

        // 1. Student cannot assign a survey (lacks guidance.assessments.assign)
        try {
            $this->service->assignSurvey($this->studentPersonId, [], 1, $studentAActor);
            self::fail('Expected DomainException when student attempts to assign survey.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // 2. Clinic Nurse cannot assign a survey
        try {
            $this->service->assignSurvey($this->studentPersonId, [], 1, $nurseActor);
            self::fail('Expected DomainException when nurse attempts to assign survey.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // 3. Guidance Counselor can assign survey to Student A
        $assignment = $this->service->assignSurvey($this->studentPersonId, [
            'academic_term' => '1st Trimester',
            'academic_year' => '2026-2027',
        ], 1, $counselorActor);
        $assignmentId = (int) $assignment['id'];
        self::assertSame($counselorUserId, (int) $assignment['assigned_by_user_id']);

        // 4. Student B cannot save draft for Student A's assignment
        try {
            $this->service->saveDraft($assignmentId, [1 => 3, 2 => 4], 1, $studentBActor);
            self::fail('Expected DomainException when Student B saves draft on Student A assignment.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // 5. Guidance Counselor cannot self-take / save draft on Student A's assignment
        try {
            $this->service->saveDraft($assignmentId, [1 => 3, 2 => 4], 1, $counselorActor);
            self::fail('Expected DomainException when counselor attempts to self-take Student A assignment.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // 6. Student A can save draft and resume their own draft
        $this->service->saveDraft($assignmentId, [1 => 4, 2 => 3], 1, $studentAActor);
        $draftForA = $this->service->getDraft($assignmentId, 1, $studentAActor);
        self::assertNotNull($draftForA);
        self::assertSame(4, $draftForA[1]);

        // 7. Student B cannot view Student A's draft
        try {
            $this->service->getDraft($assignmentId, 1, $studentBActor);
            self::fail('Expected DomainException when Student B attempts to read Student A draft.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // 8. Student B cannot finalize Student A's assessment
        $all80 = array_fill(1, 80, 3);
        try {
            $this->service->finalizeAssessment($assignmentId, $all80, 'Impersonated attestation', 1, $studentBActor);
            self::fail('Expected DomainException when Student B attempts to finalize Student A assessment.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // 9. Guidance Counselor cannot finalize/attest Student A's self-assessment
        try {
            $this->service->finalizeAssessment($assignmentId, $all80, 'Counselor attestation on behalf of student', 1, $counselorActor);
            self::fail('Expected DomainException when counselor attempts to finalize Student A self-assessment.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // 10. Student A can finalize and digitally attest their own assessment
        $finalized = $this->service->finalizeAssessment(
            $assignmentId,
            $all80,
            'I, Student A, digitally attest that these responses are my own.',
            1,
            $studentAActor
        );
        $submissionId = (int) $finalized['id'];
        self::assertSame($studentAUserId, (int) $finalized['attested_by_user_id']);

        // 11. Student A can view their own finalized results
        $studentAResults = $this->service->getResults($submissionId, 1, $studentAActor);
        self::assertCount(8, $studentAResults['domains']);

        // 12. Student B cannot view Student A's finalized results
        try {
            $this->service->getResults($submissionId, 1, $studentBActor);
            self::fail('Expected DomainException when Student B views Student A results.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // 13. Guidance Counselor and Guidance Supervisor can view Student A's finalized results
        $counselorResults = $this->service->getResults($submissionId, 1, $counselorActor);
        self::assertCount(8, $counselorResults['domains']);

        $supervisorResults = $this->service->getResultsByAssignment($assignmentId, 1, $supervisorActor);
        self::assertCount(8, $supervisorResults['domains']);

        // 14. Clinic Nurse cannot view Student A's finalized results
        try {
            $this->service->getResults($submissionId, 1, $nurseActor);
            self::fail('Expected DomainException when Clinic Nurse views Student A results.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }
    }
}
