<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\InventoryDatabaseTestCase;

/** Run only behind the explicit isolated-database guard inherited from the base. */
final class WebSecurityTest extends InventoryDatabaseTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('users')->where('id', $this->actorId)->update(['person_id' => $this->patientId]);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        Services::resetSingle('security');
        service('superglobals')->setCookieArray([]);
        parent::tearDown();
    }

    private function identity(?int $id = null): array
    {
        return ['tenant_id' => $this->tenantId, 'theme' => 'dark', 'user' => [
            'id' => $id ?? $this->actorId, 'tenant_id' => $this->tenantId,
            'role' => 'superadmin', 'permissions' => ['audit.read','rbac.manage'], 'logged_in' => true,
        ]];
    }

    private function token(): array
    {
        $name = csrf_token();
        $hash = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
        Services::resetSingle('security');
        return [$name => $hash];
    }

    private function postAs(string $path, array $data = [], ?array $identity = null)
    {
        return $this->withSession($identity ?? $this->identity())->withHeaders(['X-Requested-With'=>'XMLHttpRequest'])
            ->post($path, $data + $this->token());
    }

    public function testEveryBrowserPostRejectsMissingCsrfBeforeAnyMutation(): void
    {
        $before = [];
        foreach (['clinic_vitals','clinic_treatments','clinic_encounters','counselling_cases','counselling_notes','referrals','bmg_process_logs','carousel_slides'] as $table) {
            $before[$table] = $this->db->table($table)->countAllResults();
        }
        foreach (['auth/login','auth/logout','admin/audit/verify','clinic/encounters/'.$this->encounterId.'/vitals',
            'clinic/encounters/'.$this->encounterId.'/assessment','clinic/encounters/start-direct/'.$this->patientId,
            'clinic/patients/'.$this->patientId.'/health','clinic/queue/call-next','guidance/queue/call-next',
            'guidance/cases/store','guidance/cases/start-for-ticket/123','referrals/store','facilities/unit/123/batch',
            'admin/carousel/store'] as $path) {
            $this->token();
            $res = $this->withSession($this->identity())->withHeaders(['X-Requested-With'=>'XMLHttpRequest'])->post($path, []);
            $this->assertContains($res->response()->getStatusCode(), [403,404], $path);
        }
        foreach ($before as $table => $count) $this->assertSame($count, $this->db->table($table)->countAllResults(), $table);
    }

    public function testValidTokenCanWriteVitalsAndResponseRotatesSharedToken(): void
    {
        $res = $this->postAs('clinic/encounters/'.$this->encounterId.'/vitals', ['weight_kg'=>'60','height_cm'=>'165']);
        $this->assertContains($res->response()->getStatusCode(), [200,302]);
        $this->assertNotEmpty($res->response()->getHeaderLine('X-CSRF-TOKEN'));
        $this->assertSame(csrf_token(), $res->response()->getHeaderLine('X-CSRF-NAME'));
        $this->assertSame(1, $this->db->table('clinic_vitals')->where('encounter_id',$this->encounterId)->countAllResults());
    }

    public function testWrongTenantAndWrongUnitRecordsAreRejectedWithoutChanges(): void
    {
        $other = $this->db->table('tenants');
        $now = date('Y-m-d H:i:s');
        $other->insert(['slug'=>uniqid('security_other_'),'name'=>'Other tenant','is_active'=>1,'created_at'=>$now,'updated_at'=>$now]);
        $otherTenant = (int) $this->db->insertID();
        $person = (int) (new PersonModel())->insert(['tenant_id'=>$otherTenant,'kind'=>'student','mis_id'=>uniqid('other_'),'first_name'=>'Other','last_name'=>'Tenant']);
        $this->postAs('clinic/patients/'.$person.'/health',['allergies'=>'must not persist'])->assertStatus(404);
        $this->assertSame(0,$this->db->table('health_profiles')->where('person_id',$person)->countAllResults());
        $ticket = (new \App\Services\Scheduling\QueueService())->enqueueWalkIn('guidance',$this->patientId,'routine');
        $this->postAs('clinic/queue/call/'.$ticket['id'])->assertStatus(404);
        $this->assertSame('waiting',$this->db->table('queue_entries')->where('id',$ticket['id'])->get()->getRow()->status);
    }

    public function testSuspensionRevocationAndDeletedTenantInvalidateCachedPrivileges(): void
    {
        $this->db->table('users')->where('id',$this->actorId)->update(['status'=>'suspended']);
        $this->withSession($this->identity())->withHeaders(['Accept'=>'application/json'])->get('admin/audit')->assertStatus(401);
        $this->assertNull(session('user'));
        $this->assertSame('dark',session('theme'));
        $this->db->table('users')->where('id',$this->actorId)->update(['status'=>'active','role'=>'employee']);
        $this->withSession($this->identity())->withHeaders(['Accept'=>'application/json'])->get('admin/audit')->assertStatus(403);
        $this->db->table('tenants')->where('id',$this->tenantId)->update(['is_active'=>0]);
        $this->withSession($this->identity())->withHeaders(['Accept'=>'application/json'])->get('portal')->assertStatus(401);
    }

    public function testBothTicketStartAndLogoutGetsCannotMutate(): void
    {
        foreach (['auth/logout','clinic/encounters/start-for-ticket/123','guidance/cases/start-for-ticket/123'] as $path) {
            $this->withSession($this->identity())->withHeaders([])->get($path)->assertStatus(405);
        }
        $this->assertNotNull(session('user'));
        $this->postAs('auth/logout')->assertRedirectTo('/auth/login');
        $this->assertNull(session('user'));
    }

    public function testCounselorCannotReadOrMutateAnotherCounselorsCase(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('users')->where('id',$this->actorId)->update(['role'=>'counsellor']);
        foreach (['guidance.cases.read','guidance.cases.write'] as $code) {
            $grant = $this->db->table('permissions')->where('code',$code)->get()->getRowArray();
            $this->assertNotNull($grant);
        }
        $other = $this->user('superadmin');
        $this->db->table('counselling_cases')->insert(['tenant_id'=>$this->tenantId,'person_id'=>$this->patientId,
            'counsellor_user_id'=>$other,'case_number'=>uniqid('SEC_CASE_'),'title'=>'PRIVATE CASE OTHER COUNSELOR',
            'category'=>'personal','status'=>'open','opened_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        $id = (int) $this->db->insertID();
        $this->withSession($this->identity())->withHeaders(['Accept'=>'application/json'])->get('guidance/cases/'.$id)->assertStatus(403);
        $this->postAs('guidance/cases/'.$id.'/followups',['due_date'=>date('Y-m-d'),'action_plan'=>'must not persist'])->assertStatus(403);
        $this->assertSame(0,$this->db->table('guidance_followups')->where('case_id',$id)->countAllResults());
        $list = $this->withSession($this->identity())->withHeaders([])->get('guidance/cases');
        $list->assertStatus(200);
        $this->assertStringNotContainsString('PRIVATE CASE OTHER COUNSELOR',$list->getBody());
    }

    public function testDepartmentHomePagesRemainAvailableToTheirActualStaff(): void
    {
        foreach (['clinic_staff'=>'clinic/queue','counsellor'=>'guidance/cases','facilities_op'=>'facilities'] as $role => $path) {
            $this->db->table('users')->where('id',$this->actorId)->update(['role'=>$role]);
            $this->withSession($this->identity())->withHeaders([])->get($path)->assertStatus(200);
            $this->assertSame($role,session('user')['role']);
        }
    }

    public function testHardwarePostsRetainDeviceAuthenticationRatherThanBrowserCsrf(): void
    {
        foreach (['heartbeat','runs'] as $action) {
            $result = $this->withSession([])->withHeaders([])->post('api/v1/devices/bmg/'.$action,[]);
            $result->assertStatus(401);
            $this->assertStringNotContainsString('security token',$result->getBody());
        }
    }

    public function testPersonalPortalRemainsAvailableButStaffDirectoriesDoNot(): void
    {
        foreach (['student','employee','clinic_staff','counsellor','facilities_op'] as $role) {
            $this->db->table('users')->where('id',$this->actorId)->update(['role'=>$role]);
            $this->withSession($this->identity())->withHeaders([])->get('portal')->assertStatus(200);
            if (in_array($role,['student','employee','facilities_op'],true)) {
                $this->withSession($this->identity())->withHeaders(['Accept'=>'application/json'])->get('api/patients/search?q=Test')->assertStatus(403);
            }
        }
    }
}
