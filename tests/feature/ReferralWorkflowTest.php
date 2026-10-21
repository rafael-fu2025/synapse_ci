<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Mis\MisClientInterface;
use App\Models\ClinicEncounterModel;
use App\Models\CounsellingCaseModel;
use App\Models\PersonModel;
use App\Models\ReferralModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Referrals\ReferralService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use RuntimeException;
use Tests\Support\InventoryTestDatabase;

/** Domain integration only: requires referral migration/notification integration and the isolated harness. */
final class ReferralWorkflowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private ReferralService $service;
    private int $tenant;
    private int $patient;
    private array $clinic;
    private array $guidance;
    private array $admin;
    private array $foreignTenants = [];

    protected function setUp(): void
    {
        InventoryTestDatabase::verify();
        parent::setUp();
        AuthenticatedUser::clear();
        $mis = $this->createMock(MisClientInterface::class);
        foreach (['studentLogin', 'employeeLogin', 'searchStudents', 'searchEmployees', 'fetchDepartments', 'fetchPrograms', 'fetchYearLevels'] as $method) {
            $mis->expects($this->never())->method($method);
        }
        Services::injectMock('misClient', $mis);
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('ref_workflow_'), 'name' => 'Referral workflow fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tenant = (int) $this->db->insertID();
        CurrentTenant::set($this->tenant);
        $this->service = new ReferralService();
        $this->patient = $this->person();
        $this->clinic = $this->account('clinic_staff');
        $this->guidance = $this->account('counsellor');
        $this->admin = $this->account('superadmin');
    }

    protected function tearDown(): void
    {
        // Only delete this test's tenant fixtures, never shared seed identities.
        if (isset($this->tenant)) {
            foreach ([$this->tenant, ...$this->foreignTenants] as $tenant) {
                foreach (['notifications', 'referrals', 'clinic_encounters', 'counselling_cases', 'users', 'people'] as $table) {
                    $this->db->table($table)->where('tenant_id', $tenant)->delete();
                }
                $this->db->table('tenants')->where('id', $tenant)->delete();
            }
        }
        Services::resetSingle('misClient');
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    private function person(): int
    {
        return (int) (new PersonModel())->insert(['tenant_id' => $this->tenant, 'kind' => 'student',
            'mis_id' => uniqid('ref_patient_'), 'first_name' => 'Workflow', 'last_name' => 'Patient',
            'email' => 'private@example.test', 'address' => 'Private address']);
    }

    private function account(string $role): array
    {
        $id = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $this->person(),
            'username' => uniqid('ref_staff_'), 'role' => $role, 'status' => 'active']);
        // Deliberately forge cached authority; all checks must reload the real account.
        return ['id' => $id, 'tenant_id' => $this->tenant, 'role' => 'superadmin', 'permissions' => ['*']];
    }

    private function data(bool $reverse = false): array
    {
        return ['patient_person_id' => $this->patient, 'issuer_user_id' => ($reverse ? $this->guidance : $this->clinic)['id'],
            'reason_code' => $reverse ? 'medical_assessment' : 'academic_distress',
            'reason_description' => '  Brief shared support request.  ', 'request_key' => bin2hex(random_bytes(32))];
    }

    private function referral(bool $reverse = false): array
    {
        return $this->service->createReferral($this->data($reverse), $reverse ? $this->guidance : $this->clinic);
    }

    private function ready(bool $reverse = false): array
    {
        $ref = $this->referral($reverse);
        $actor = $reverse ? $this->clinic : $this->guidance;
        return $this->service->acknowledge((int) $ref['id'], $actor['id'], $actor);
    }

    private function rejected(int $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected referral rejection.');
        } catch (RuntimeException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
        }
    }

    private function encounter(int $patient, string $status = 'in_progress'): int
    {
        return (int) (new ClinicEncounterModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $patient,
            'attending_user_id' => $this->clinic['id'], 'status' => $status, 'started_at' => date('Y-m-d H:i:s'),
            'chief_complaint' => 'PRIVATE COMPLAINT', 'assessment_notes' => 'PRIVATE ASSESSMENT']);
    }

    private function foreignEncounter(): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('ref_foreign_'), 'name' => 'Foreign referral fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $tenant = (int) $this->db->insertID();
        $this->foreignTenants[] = $tenant;
        $people = new PersonModel();
        $patient = (int) $people->insert(['tenant_id' => $tenant, 'kind' => 'student',
            'mis_id' => uniqid('ref_foreign_patient_'), 'first_name' => 'Foreign', 'last_name' => 'Patient']);
        $staff = (int) $people->insert(['tenant_id' => $tenant, 'kind' => 'employee',
            'mis_id' => uniqid('ref_foreign_staff_'), 'first_name' => 'Foreign', 'last_name' => 'Staff']);
        $attending = (int) (new UserModel())->insert(['tenant_id' => $tenant, 'person_id' => $staff,
            'username' => uniqid('ref_foreign_clinic_'), 'role' => 'clinic_staff', 'status' => 'active']);
        return (int) (new ClinicEncounterModel())->insert(['tenant_id' => $tenant, 'person_id' => $patient,
            'attending_user_id' => $attending, 'status' => 'in_progress', 'started_at' => $now]);
    }

    private function caseId(int $patient, int $owner, string $status = 'open'): int
    {
        return (int) (new CounsellingCaseModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $patient,
            'counsellor_user_id' => $owner, 'case_number' => uniqid('CASE-'), 'title' => 'Support case',
            'category' => 'personal', 'status' => $status, 'opened_at' => date('Y-m-d H:i:s'),
            'summary_notes' => 'PRIVATE COUNSELLING SUMMARY']);
    }

    public function testFreshContextAndDerivedRoutingRequireExplicitSuperadminWorkstation(): void
    {
        $this->assertSame('clinic', $this->service->context($this->clinic));
        $this->assertSame('guidance', $this->service->context($this->guidance));
        $this->rejected(403, fn () => $this->service->context($this->clinic, 'guidance'));
        $this->rejected(422, fn () => $this->service->context($this->admin));
        $this->assertSame('guidance', $this->service->context($this->admin, 'guidance'));
        $ref = $this->referral();
        $this->assertSame('clinic', $ref['source_module']);
        $this->assertSame('guidance', $ref['target_module']);
        $this->rejected(403, fn () => $this->service->createReferral($this->data() + ['source_module' => 'guidance'], $this->clinic));
        $this->rejected(422, fn () => $this->service->createReferral($this->data() + ['target_module' => 'clinic'], $this->clinic));
        $data = array_replace($this->data(), ['issuer_user_id' => $this->admin['id']]);
        $this->rejected(422, fn () => $this->service->createReferral($data, $this->admin));
        $ref = $this->service->createReferral($data + ['workstation' => 'clinic'], $this->admin);
        $this->assertSame('guidance', $ref['target_module']);
        (new UserModel())->update($this->clinic['id'], ['role' => 'counsellor']);
        $this->assertSame('guidance', $this->service->context($this->clinic));
    }

    public function testDestinationReasonsAndRequiredTrimmedBriefSummary(): void
    {
        $this->assertSame(['medical_assessment', 'medical_followup', 'physical_symptoms', 'health_advice', 'other'],
            array_keys($this->service->reasonOptions('clinic')));
        foreach (['', '   ', null, [], str_repeat('x', 2001)] as $summary) {
            $this->rejected(422, fn () => $this->service->createReferral(array_replace($this->data(), ['reason_description' => $summary]), $this->clinic));
        }
        $this->rejected(422, fn () => $this->service->createReferral(array_replace($this->data(), ['reason_code' => 'medical_assessment']), $this->clinic));
        $this->rejected(422, fn () => $this->service->createReferral(array_replace($this->data(true), ['reason_code' => 'academic_distress']), $this->guidance));
        $this->assertSame('Brief shared support request.', $this->referral()['reason_description']);
        $this->assertSame('clinic', $this->referral(true)['target_module']);
    }

    public function testRetryIsExactAndNotificationIsOnlyInsertedOnce(): void
    {
        $data = $this->data();
        $first = $this->service->createReferral($data, $this->clinic);
        $notices = $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults();
        $this->assertGreaterThan(0, $notices, 'New referral must invoke the real transactional notification hook.');
        $retry = $this->service->createReferral($data, $this->clinic);
        $this->assertSame($first['id'], $retry['id']);
        $this->assertSame($notices, $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults());
        $this->rejected(409, fn () => $this->service->createReferral(array_replace($data, ['priority' => 'urgent']), $this->clinic));
        foreach (['', 'abc', str_repeat('g', 64), null, []] as $key) {
            $this->rejected(422, fn () => $this->service->createReferral(array_replace($this->data(), ['request_key' => $key]), $this->clinic));
        }
        $second = $this->referral();
        $this->assertNotSame($first['referral_code'], $second['referral_code']);
        $this->assertSame(2, $this->db->table('referrals')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testReferralRejectsCallerTransactionWithoutChangingItsState(): void
    {
        $this->db->transBegin();
        try {
            $this->rejected(409, fn () => $this->referral());
            $this->assertSame(1, $this->db->transDepth);
            $this->assertTrue($this->db->transStatus());
        } finally {
            $this->db->transRollback();
        }
        $this->assertSame(0, $this->db->table('referrals')->where('tenant_id', $this->tenant)->countAllResults());
        $this->assertSame('submitted', $this->referral()['status']);
    }

    public function testSqlFailureRollsBackNotificationAndAllowsSameRequestRetry(): void
    {
        $data = $this->data();
        $name = $this->db->protectIdentifiers(uniqid('ref_failure_'));
        $table = $this->db->protectIdentifiers($this->db->prefixTable('notifications'));
        $this->assertNotFalse($this->db->query("CREATE TRIGGER {$name} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.tenant_id = {$this->tenant} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fixture referral notification failure'; END IF; END"));
        $failure = null;
        try {
            try {
                $this->service->createReferral($data, $this->clinic);
            } catch (\Throwable $e) {
                $failure = $e;
            }
        } finally {
            $this->db->query("DROP TRIGGER IF EXISTS {$name}");
        }
        $this->assertNotNull($failure, 'The notification SQL failure must abort creation.');
        $this->assertSame(0, $this->db->transDepth);
        $this->assertTrue($this->db->transStatus());
        $this->assertSame(0, $this->db->table('referrals')->where('tenant_id', $this->tenant)->countAllResults());
        $this->assertSame(0, $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults());
        $retry = $this->service->createReferral($data, $this->clinic);
        $this->assertSame('submitted', $retry['status']);
        $again = $this->service->createReferral($data, $this->clinic);
        $this->assertSame($retry['id'], $again['id']);
    }

    public function testListingTabsPaginationSearchAndNoClinicalOrContactData(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->referral();
        }
        $reverse = $this->ready(true);
        $closed = $this->ready();
        $this->service->close((int) $closed['id'], $this->guidance);
        $this->encounter($this->patient);
        $this->caseId($this->patient, $this->guidance['id']);
        $list = $this->service->listing('guidance', 'incoming', ['page' => '2'], $this->guidance);
        $this->assertCount(1, $list['referrals']);
        $this->assertSame(['incoming' => 26, 'sent' => 1, 'history' => 1], $list['counts']);
        $this->assertSame(26, $list['pagination']['total']);
        foreach ([25, 50, 100] as $size) {
            $all = $this->service->listing('clinic', 'sent', ['per_page' => $size], $this->clinic);
            $this->assertCount(min(26, $size), $all['referrals']);
        }
        $sent = $this->service->listing('guidance', 'sent', ['q' => $reverse['referral_code']], $this->guidance);
        $this->assertCount(1, $sent['referrals']);
        $history = $this->service->listing('clinic', 'history', [], $this->clinic);
        $this->assertSame($closed['id'], $history['referrals'][0]['id']);
        $empty = $this->service->listing('clinic', 'incoming', ['q' => 'PRIVATE ASSESSMENT', 'page' => 999], $this->clinic);
        $this->assertSame(0, $empty['pagination']['filtered']);
        $this->assertSame(1, $empty['pagination']['page']);
        foreach (['assessment_notes', 'chief_complaint', 'summary_notes', 'email', 'address', 'password_hash'] as $private) {
            $this->assertArrayNotHasKey($private, $list['referrals'][0]);
        }
        $this->rejected(403, fn () => $this->service->listing('clinic', 'incoming', [], $this->guidance));
    }

    public function testClinicCreateAndRetryNeverCreateQueueOrAppointment(): void
    {
        $ref = $this->ready(true);
        $id = (int) $ref['id'];
        $result = $this->service->handoff($id, ['mode' => 'create', 'chief_complaint' => 'DO NOT COPY PRIVATE DETAILS'], $this->clinic);
        $this->assertTrue($result['created']);
        $this->assertSame('/clinic/encounters/' . $result['target_id'], $result['target_url']);
        $visit = (new ClinicEncounterModel())->find($result['target_id']);
        $this->assertNull($visit['queue_entry_id']);
        $this->assertNull($visit['appointment_id']);
        $this->assertStringContainsString($ref['referral_code'], $visit['chief_complaint']);
        $this->assertStringNotContainsString('DO NOT COPY', $visit['chief_complaint']);
        $this->assertSame('under_review', $result['referral']['status']);
        $again = $this->service->handoff($id, ['mode' => 'create'], $this->clinic);
        $this->assertFalse($again['created']);
        $this->assertSame($result['target_id'], $again['target_id']);
        $this->assertSame(1, $this->db->table('clinic_encounters')->where('tenant_id', $this->tenant)->countAllResults());
        $this->service->close($id, $this->clinic);
        $this->rejected(409, fn () => $this->service->handoff($id, ['mode' => 'create'], $this->clinic));
    }

    public function testClinicLinkRequiresSamePatientOpenTenantAndTargetRole(): void
    {
        $ref = $this->ready(true);
        $id = (int) $ref['id'];
        $wrong = $this->encounter($this->person());
        $valid = $this->encounter($this->patient);
        $foreign = $this->foreignEncounter();
        $this->rejected(403, fn () => $this->service->handoff($id, ['mode' => 'link', 'target_id' => $valid], $this->guidance));
        foreach ([$wrong, $foreign] as $bad) {
            $this->rejected(422, fn () => $this->service->handoff($id, ['mode' => 'link', 'target_id' => $bad], $this->clinic));
        }
        $options = $this->service->handoffOptions($id, $this->clinic);
        $this->assertSame([$valid], array_map('intval', array_column($options['encounters'], 'id')));
        $this->assertArrayNotHasKey('chief_complaint', $options['encounters'][0]);
        $this->assertArrayNotHasKey('email', $options['patient']);
        $result = $this->service->handoff($id, ['mode' => 'link', 'target_id' => $valid], $this->clinic);
        $this->assertFalse($result['created']);
        $this->assertSame($valid, $result['target_id']);
        (new ClinicEncounterModel())->delete($valid);
        $this->rejected(422, fn () => $this->service->handoff($id, ['mode' => 'create'], $this->clinic));

    }

    public function testGuidanceCreateCompatibilityAndLegacyDuplicateGuard(): void
    {
        $ref = $this->ready();
        $id = (int) $ref['id'];
        $result = $this->service->convertToCase($id, $this->guidance['id'], 'Shared referral support', 'academic', $this->guidance);
        $this->assertSame('converted_to_case', $result['referral']['status']);
        $caseId = (int) $result['case']['case']['id'];
        $this->assertSame($caseId, (int) $result['referral']['target_case_id']);
        $this->assertArrayNotHasKey('summary_notes', $result['case']['case']);
        $stored = (new CounsellingCaseModel())->find($caseId);
        $this->assertStringContainsString($ref['referral_code'], $stored['summary_notes']);
        $retry = $this->service->handoff($id, ['mode' => 'create'], $this->guidance);
        $this->assertFalse($retry['created']);
        $this->assertSame($caseId, $retry['target_id']);
        (new ReferralModel())->update($id, ['target_case_id' => null]);
        $this->rejected(409, fn () => $this->service->handoff($id, ['mode' => 'create', 'title' => 'Duplicate', 'category' => 'personal'], $this->guidance));
        $this->assertSame(1, $this->db->table('counselling_cases')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testGuidanceLinksAreAssignedOrReadAnyAndOptionsAreNoteFree(): void
    {
        $ref = $this->ready();
        $id = (int) $ref['id'];
        $other = $this->account('counsellor');
        $own = $this->caseId($this->patient, $this->guidance['id'], 'monitoring');
        $unassigned = $this->caseId($this->patient, $other['id']);
        $wrongPatient = $this->caseId($this->person(), $this->guidance['id']);
        $closed = $this->caseId($this->patient, $this->guidance['id'], 'closed');
        $options = $this->service->handoffOptions($id, $this->guidance);
        $this->assertSame([$own], array_map('intval', array_column($options['cases'], 'id')));
        $this->assertArrayNotHasKey('summary_notes', $options['cases'][0]);
        foreach ([$unassigned, $wrongPatient, $closed] as $bad) {
            $this->rejected(422, fn () => $this->service->handoff($id, ['mode' => 'link', 'target_id' => $bad], $this->guidance));
        }
        $supervisor = $this->account('guidance_supervisor');
        $this->assertCount(2, $this->service->handoffOptions($id, $supervisor)['cases']);
        $result = $this->service->handoff($id, ['mode' => 'link', 'target_id' => $own], $this->guidance);
        $this->assertSame($own, $result['target_id']);
        $this->assertFalse($result['created']);
        $this->rejected(422, fn () => $this->service->handoff($id, ['mode' => 'create'], $other));
    }

    public function testListingClinicalIdsFollowCurrentCaseOwnerAndFreshGrants(): void
    {
        $sourceEncounter = $this->encounter($this->patient);
        $ref = $this->service->createReferral($this->data() + ['source_encounter_id' => $sourceEncounter], $this->clinic);
        $id = (int) $ref['id'];
        $this->service->acknowledge($id, $this->guidance['id'], $this->guidance);
        $handoff = $this->service->handoff($id, ['mode' => 'create', 'title' => 'Private link', 'category' => 'personal'], $this->guidance);
        $caseId = $handoff['target_id'];
        $row = $this->listed($ref, $this->clinic, 'clinic', 'sent');
        $this->assertTrue($row['has_target']);
        $this->assertFalse($row['can_open_target']);
        $this->assertNull($row['target_case_id']);
        $this->assertSame($sourceEncounter, (int) $row['source_encounter_id']);
        $row = $this->listed($ref, $this->guidance, 'guidance', 'incoming');
        $this->assertTrue($row['can_open_target']);
        $this->assertSame($caseId, (int) $row['target_case_id']);
        $this->assertNull($row['source_encounter_id']);
        $other = $this->account('counsellor');
        (new CounsellingCaseModel())->update($caseId, ['counsellor_user_id' => $other['id']]);
        // Referral assignment intentionally remains the old counsellor: it is not case authority.
        $row = $this->listed($ref, $this->guidance, 'guidance', 'incoming');
        $this->assertTrue($row['has_target']);
        $this->assertFalse($row['can_open_target']);
        $this->assertNull($row['target_case_id']);
        $this->assertTrue($this->listed($ref, $other, 'guidance', 'incoming')['can_open_target']);
        $supervisor = $this->account('guidance_supervisor');
        $this->assertTrue($this->listed($ref, $supervisor, 'guidance', 'incoming')['can_open_target']);
        $permission = $this->db->table('permissions')->where('code', 'guidance.cases.read_any')->get()->getRowArray();
        $grant = ['role' => 'guidance_supervisor', 'permission_id' => $permission['id']];
        $this->db->table('role_permissions')->where($grant)->delete();
        try {
            $row = $this->listed($ref, $supervisor, 'guidance', 'incoming');
            $this->assertFalse($row['can_open_target']);
            $this->assertNull($row['target_case_id']);
            $this->assertTrue($row['has_target']);
        } finally {
            $this->db->table('role_permissions')->insert($grant);
        }
        $this->service->close($id, $other);
        $this->assertTrue($this->listed($ref, $other, 'guidance', 'history')['can_open_target']);
        $this->assertFalse($this->listed($ref, $this->clinic, 'clinic', 'history')['can_open_target']);
        $reverse = $this->ready(true);
        $clinicHandoff = $this->service->handoff((int) $reverse['id'], ['mode' => 'create'], $this->clinic);
        $row = $this->listed($reverse, $this->guidance, 'guidance', 'sent');
        $this->assertTrue($row['has_target']);
        $this->assertFalse($row['can_open_target']);
        $this->assertNull($row['target_encounter_id']);
        $this->assertSame($clinicHandoff['target_id'], (int) $this->listed($reverse, $this->clinic, 'clinic', 'incoming')['target_encounter_id']);
    }

    private function listed(array $ref, array $actor, string $unit, string $tab): array
    {
        $rows = $this->service->listing($unit, $tab, ['q' => $ref['referral_code']], $actor)['referrals'];
        $this->assertCount(1, $rows);
        return $rows[0];
    }

    public function testConcurrentDifferentReferralHandoffsAllocateDifferentCases(): void
    {
        $first = $this->ready();
        $second = $this->ready();
        $this->assertDistinctCases($this->raceCases([
            ['operation' => 'handoff', 'id' => (int) $first['id']],
            ['operation' => 'handoff', 'id' => (int) $second['id']],
        ]));
        $refs = $this->db->table('referrals')->where('tenant_id', $this->tenant)->get()->getResultArray();
        $this->assertCount(2, array_unique(array_column($refs, 'target_case_id')));
    }

    public function testDirectCaseCreationAndReferralHandoffShareNumberMutex(): void
    {
        $ref = $this->ready();
        $this->assertDistinctCases($this->raceCases([
            ['operation' => 'direct'], ['operation' => 'handoff', 'id' => (int) $ref['id']],
        ]));
        $this->assertSame(0, $this->db->transDepth);
    }

    public function testCaseAllocationUsesCurrentReadDespiteEarlierRepeatableReadSnapshot(): void
    {
        $ref = $this->ready();
        $this->assertDistinctCases($this->raceCases([
            ['operation' => 'handoff', 'id' => (int) $ref['id']],
            ['operation' => 'direct', 'snapshot' => true, 'after_first' => true],
        ]));
    }

    public function testCaseNumberAllocatorRequiresTransaction(): void
    {
        $this->rejected(409, fn () => (new CounsellingCaseModel())->nextCaseNumber());
    }

    private function assertDistinctCases(array $rows): void
    {
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]['id'], $rows[1]['id']);
        $this->assertNotSame($rows[0]['case_number'], $rows[1]['case_number']);
        $this->assertSame(2, $this->db->table('counselling_cases')->where('tenant_id', $this->tenant)->countAllResults());
    }

    private function raceCases(array $jobs): array
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for independent-connection case allocation tests.');
        }
        $code = <<<'PHP'
$job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
require $job['bootstrap'];
config('Database')->tests = $job['database'];
config('Database')->defaultGroup = 'tests';
\Tests\Support\InventoryTestDatabase::verify();
\App\Services\CurrentTenant::set($job['tenant']);
$db = \Config\Database::connect();
$db->query('SET SESSION innodb_lock_wait_timeout = 10');
if (!empty($job['snapshot'])) {
    $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->transBegin();
    $db->table('counselling_cases')->where('tenant_id', $job['tenant'])->countAllResults();
}
echo "READY\n";
flush();
fgets(STDIN);
try {
    if ($job['operation'] === 'handoff') {
        $result = (new \App\Services\Referrals\ReferralService())->handoff($job['id'],
            ['mode' => 'create', 'title' => 'Concurrent referral', 'category' => 'personal'], $job['actor']);
        $id = $result['target_id'];
    } else {
        $result = (new \App\Services\Guidance\GuidanceCaseService())->createCase(
            $job['patient'], $job['actor']['id'], 'Concurrent ordinary case');
        $id = (int) $result['case']['id'];
        if (!empty($job['snapshot'])) {
            if (!$db->transStatus() || !$db->transCommit()) throw new \RuntimeException('Worker commit failed.');
        }
    }
    $row = $db->table('counselling_cases')->select('id,case_number')->where('id', $id)->get()->getRowArray();
    echo json_encode(['result' => $row], JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $e) {
    if ($db->transDepth > 0) $db->transRollback();
    echo json_encode(['error' => $e->getMessage(), 'code' => $e->getCode()], JSON_THROW_ON_ERROR) . "\n";
}
PHP;
        $workers = [];
        $this->db->transBegin();
        try {
            $tenants = $this->db->protectIdentifiers($this->db->prefixTable('tenants'));
            $this->db->query("SELECT id FROM {$tenants} WHERE id = ? FOR UPDATE", [$this->tenant]);
            foreach ($jobs as $job) {
                $process = proc_open([PHP_BINARY, '-r', $code],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
                fwrite($pipes[0], json_encode($job + [
                    'bootstrap' => ROOTPATH . 'vendor/codeigniter4/framework/system/Test/bootstrap.php',
                    'database' => config('Database')->tests, 'tenant' => $this->tenant,
                    'patient' => $this->patient, 'actor' => $this->guidance,
                ], JSON_THROW_ON_ERROR) . "\n");
                stream_set_timeout($pipes[1], 20);
                $this->assertSame("READY\n", fgets($pipes[1]));
            }
            foreach ($workers as $index => [, $pipes]) {
                if (empty($jobs[$index]['after_first'])) {
                    fwrite($pipes[0], "GO\n");
                    fclose($pipes[0]);
                }
            }
            usleep(200000);
            $this->assertTrue($this->db->transCommit());
            $results = [];
            foreach ($workers as $index => [$process, $pipes]) {
                if (!empty($jobs[$index]['after_first'])) {
                    // Its RR snapshot predates the first worker's committed case.
                    fwrite($pipes[0], "GO\n");
                    fclose($pipes[0]);
                }
                $line = fgets($pipes[1]);
                $this->assertIsString($line, 'Case allocation worker timed out.');
                $result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('error', $result, $line);
                $results[] = $result['result'];
                $this->assertSame('', stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process));
            }
            $workers = [];
            return $results;
        } finally {
            if ($this->db->transDepth > 0) $this->db->transRollback();
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) fclose($pipe);
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }

    public function testPrematureAndDuplicateActionsConflictAndSourceCannotClose(): void
    {
        $ref = $this->referral();
        $id = (int) $ref['id'];
        $this->rejected(409, fn () => $this->service->handoffOptions($id, $this->guidance));
        $this->rejected(409, fn () => $this->service->close($id, $this->guidance));
        $this->rejected(403, fn () => $this->service->acknowledge($id, $this->clinic['id'], $this->clinic));
        $this->service->acknowledge($id, $this->guidance['id'], $this->guidance);
        $this->rejected(409, fn () => $this->service->acknowledge($id, $this->guidance['id'], $this->guidance));
        $this->service->review($id, $this->guidance['id'], $this->guidance);
        $this->rejected(409, fn () => $this->service->review($id, $this->guidance['id'], $this->guidance));
        $this->rejected(403, fn () => $this->service->close($id, $this->clinic));
        $this->rejected(403, fn () => $this->service->close($id));
        $this->service->close($id, $this->guidance);
        $this->rejected(409, fn () => $this->service->close($id, $this->guidance));
    }
}
