<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceCallSlipService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use Tests\Support\FreshDatabaseTestCase;

/** Production handlers, synthetic fixtures only, no official document issuance. */
final class GuidanceCallSlipFunctionalTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private string $suffix;
    private array $student;
    private array $counselor;
    private array $associate;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);
        $this->db = Database::connect();
        $this->suffix = bin2hex(random_bytes(4));
        (new PermissionsAndRolesSeeder(new Database(), $this->db))->run();
        (new GuidanceFormDefinitionService())->syncManifestToDatabase(1);
        $this->student = $this->fixture('student', 'student');
        $this->counselor = $this->fixture('counselor', 'counsellor', 'licensed_counselor');
        $this->associate = $this->fixture('associate', 'counsellor', 'counselor_associate');
        $this->admin = $this->fixture('admin', 'guidance_admin');
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $_SESSION = [];
        CurrentTenant::reset();
        parent::tearDown();
    }

    public function testAttendanceSavePersistsAndReturnsJsonFeedback(): void
    {
        $slip = $this->createSlip($this->counselor);
        $uri = 'guidance/call-slips/' . $slip['id'] . '/status';
        $result = $this->postAs($this->counselor, $uri, ['attendance_status' => 'attended']);
        $result->assertStatus(200);
        $json = json_decode((string) $result->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($json['success']);
        $this->assertSame('Call slip status updated to Attended.', $json['message']);
        $this->assertSame(site_url('/guidance/call-slips'), $json['redirect']);
        $this->assertNotEmpty($json['csrf']['name']);
        $this->assertNotEmpty($json['csrf']['hash']);
        $this->assertSame('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $row = $this->db->table('guidance_call_slips')->where('id', $slip['id'])->get()->getRowArray();
        $this->assertSame('attended', $row['attendance_status']);
        $this->assertNotEmpty($row['acknowledged_at']);
        $this->assertNotEmpty($row['attended_at']);

        $invalid = $this->postAs($this->counselor, $uri, ['attendance_status' => 'not-a-status']);
        $invalid->assertStatus(422);
        $this->assertFalse(json_decode((string) $invalid->getJSON(), true)['success']);
        $after = $this->db->table('guidance_call_slips')->where('id', $slip['id'])->get()->getRowArray();
        $this->assertSame($row['attendance_status'], $after['attendance_status']);
        $this->assertSame($row['attended_at'], $after['attended_at']);
        $this->assertNoOfficialIssuance();
    }

    public function testSingleA4PreviewReturnsCompletePdf(): void
    {
        $this->assertPreview('single');
    }

    public function testQuadTearOffPreviewReturnsCompletePdf(): void
    {
        $this->assertPreview('quad');
    }

    public function testBothOfficialPrintChoicesRequireAssociateCosignWithoutIssuing(): void
    {
        foreach (['single', 'quad'] as $layout) {
            $slip = $this->createSlip($this->associate);
            $result = $this->withSession($this->sessionFor($this->associate))
                ->get('guidance/call-slips/' . $slip['id'] . '/pdf?layout=' . $layout . '&mode=issue');
            $result->assertStatus(409);
            $this->assertStringContainsString('supervisor co-signature', $result->response()->getBody());
            $this->assertSame('private, no-store', $result->response()->getHeaderLine('Cache-Control'));
            $row = $this->db->table('guidance_form_instances')->where('id', $slip['form_instance_id'])->get()->getRowArray();
            $this->assertSame('pending_cosign', $row['status']);
            $this->assertNoOfficialIssuance();
        }
    }

    public function testBothOfficialPrintChoicesRejectUncredentialedAdminWithoutIssuing(): void
    {
        $slip = $this->createSlip($this->counselor);
        foreach (['single', 'quad'] as $layout) {
            $result = $this->withSession($this->sessionFor($this->admin))
                ->get('guidance/call-slips/' . $slip['id'] . '/pdf?layout=' . $layout . '&mode=issue');
            $result->assertStatus(403);
            $this->assertNoOfficialIssuance();
        }
        $row = $this->db->table('guidance_form_instances')->where('id', $slip['form_instance_id'])->get()->getRowArray();
        $this->assertSame('draft', $row['status']);
    }

    private function assertPreview(string $layout): void
    {
        $slip = $this->createSlip($this->counselor);
        $result = $this->withSession($this->sessionFor($this->counselor))
            ->get('guidance/call-slips/' . $slip['id'] . '/pdf?layout=' . $layout . '&mode=preview');
        $result->assertStatus(200);
        $response = $result->response();
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertSame('inline; filename="call-slip-' . $slip['call_slip_number'] . '-' . $layout . '.pdf"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertNoOfficialIssuance();
        $row = $this->db->table('guidance_form_instances')->where('id', $slip['form_instance_id'])->get()->getRowArray();
        $this->assertSame('draft', $row['status']);
        $pdf = (string) $response->getBody();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1024, strlen($pdf), $layout . ' preview must contain a complete PDF, not the header/hash fallback. Actual bytes: ' . strlen($pdf));
        $this->assertMatchesRegularExpression('/startxref\s+\d+\s+%%EOF\s*$/s', $pdf, $layout . ' PDF must have a cross-reference pointer and EOF marker.');
    }

    private function assertNoOfficialIssuance(): void
    {
        $this->assertSame(0, $this->db->table('guidance_document_issuances')->countAllResults());
        $this->assertSame(0, $this->db->table('guidance_document_jobs')->where('job_type', 'final_issuance')->countAllResults());
    }

    private function createSlip(array $user): array
    {
        AuthenticatedUser::clear();
        $_SESSION = $this->sessionFor($user);
        try {
            return (new GuidanceCallSlipService(db: $this->db))->issueCallSlip([
                'person_id' => $this->student['person_id'],
                'call_date' => date('Y-m-d', strtotime('+1 day')),
                'call_time' => '09:30',
                'purpose' => 'routine_interview',
                'actor' => $user,
            ], 1);
        } finally {
            $_SESSION = [];
            AuthenticatedUser::clear();
        }
    }

    private function postAs(array $user, string $uri, array $params)
    {
        $hash = Services::security()->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
        Services::resetSingle('security');
        $params[config('Security')->tokenName] = $hash;
        return $this->withSession($this->sessionFor($user))->withHeaders([
            'X-CSRF-TOKEN' => $hash,
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Synapse-Feedback' => 'toast',
        ])->post($uri, $params);
    }

    private function sessionFor(array $user): array
    {
        $permissions = array_column($this->db->table('role_permissions rp')->select('p.code')
            ->join('permissions p', 'p.id = rp.permission_id')->where('rp.role', $user['role'])
            ->get()->getResultArray(), 'code');
        return [
            'user' => $user + ['permissions' => $permissions, 'logged_in' => true],
            'tenant_id' => 1,
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()],
        ];
    }

    private function fixture(string $label, string $role, ?string $tier = null): array
    {
        $tag = 'cs_functional_' . $label . '_' . $this->suffix;
        $now = date('Y-m-d H:i:s');
        $this->db->table('people')->insert([
            'tenant_id' => 1, 'kind' => $role === 'student' ? 'student' : 'employee',
            'mis_id' => strtoupper($tag), 'first_name' => 'Synthetic', 'last_name' => $tag,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $personId = (int) $this->db->insertID();
        if ($role === 'student') {
            $this->db->table('student_profiles')->insert([
                'tenant_id' => 1, 'person_id' => $personId, 'program' => 'BSCS', 'year_level' => 2,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->db->table('users')->insert([
            'tenant_id' => 1, 'person_id' => $personId, 'username' => $tag,
            'role' => $role, 'status' => 'active', 'auth_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $userId = (int) $this->db->insertID();
        if ($tier !== null) {
            $this->db->table('guidance_practitioner_profiles')->insert([
                'tenant_id' => 1, 'person_id' => $personId, 'user_id' => $userId,
                'credential_tier' => $tier, 'license_number' => 'SYNTHETIC-' . $tag,
                'credential_expiry' => date('Y-m-d', strtotime('+1 year')), 'professional_standing' => 'active',
                'service_scope_json' => json_encode(['counseling', 'psychosocial']), 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        return ['id' => $userId, 'tenant_id' => 1, 'person_id' => $personId, 'username' => $tag, 'role' => $role, 'auth_version' => 1];
    }
}
