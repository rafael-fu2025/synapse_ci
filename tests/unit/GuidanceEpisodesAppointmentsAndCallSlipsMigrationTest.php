<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\CreateGuidanceEpisodesAppointmentsAndCallSlipsKernel;
use App\Models\GuidanceAppointmentModel;
use App\Models\GuidanceCallSlipModel;
use App\Models\GuidanceEpisodeModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceEpisodesAppointmentsAndCallSlipsMigrationTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        $this->db->table('guidance_call_slips')->emptyTable();
        $this->db->table('guidance_appointments')->emptyTable();
        $this->db->table('guidance_episodes')->emptyTable();
    }

    public function testTablesExistInDatabase(): void
    {
        $tables = $this->db->listTables();
        self::assertContains('guidance_episodes', $tables);
        self::assertContains('guidance_appointments', $tables);
        self::assertContains('guidance_call_slips', $tables);
    }

    public function testPermissionsInstalled(): void
    {
        $aptPerm = $this->db->table('permissions')->where('code', 'guidance.appointments.manage')->get()->getRowArray();
        self::assertNotNull($aptPerm);
        self::assertSame('guidance', $aptPerm['module']);

        $intakePerm = $this->db->table('permissions')->where('code', 'guidance.intake.manage')->get()->getRowArray();
        self::assertNotNull($intakePerm);
        self::assertSame('guidance', $intakePerm['module']);

        // Check role mappings
        $aptRoles = $this->db->table('role_permissions')
            ->where('permission_id', (int) $aptPerm['id'])
            ->get()->getResultArray();
        $mappedRoles = array_column($aptRoles, 'role');
        self::assertContains('counsellor', $mappedRoles);
        self::assertContains('guidance_supervisor', $mappedRoles);
        self::assertContains('guidance_admin', $mappedRoles);
        self::assertContains('superadmin', $mappedRoles);
    }

    public function testGuidanceEpisodeModelCrudAndScopes(): void
    {
        $model = new GuidanceEpisodeModel();

        $data = [
            'tenant_id' => 1,
            'person_id' => 101,
            'intake_referral_id' => null,
            'case_id' => null,
            'status' => 'open',
            'opened_at' => '2026-11-13 14:00:00',
            'primary_counselor_user_id' => 201,
            'closure_reason' => null,
            'closure_notes_cipher' => null,
        ];

        $id = $model->insert($data, true);
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);

        $row = $model->find($id);
        self::assertNotNull($row);
        self::assertSame('open', $row['status']);
        self::assertSame(101, (int) $row['person_id']);
        self::assertSame(201, (int) $row['primary_counselor_user_id']);
        self::assertNotEmpty($row['created_at']);
        self::assertNotEmpty($row['updated_at']);
        self::assertNull($row['deleted_at']);

        // Helper methods
        $byPerson = $model->getByPersonId(101);
        self::assertCount(1, $byPerson);
        self::assertSame($id, (int) $byPerson[0]['id']);

        $active = $model->findActiveByPersonId(101);
        self::assertNotNull($active);
        self::assertSame($id, (int) $active['id']);

        $byCounselor = $model->getByCounselor(201);
        self::assertCount(1, $byCounselor);

        // Update to closed with closure notes
        $model->update($id, [
            'status' => 'closed',
            'closed_at' => '2026-11-13 15:00:00',
            'closure_reason' => 'Goals achieved',
            'closure_notes_cipher' => 'encrypted_closure_notes_blob',
        ]);
        $closed = $model->find($id);
        self::assertSame('closed', $closed['status']);
        self::assertSame('Goals achieved', $closed['closure_reason']);
        self::assertSame('encrypted_closure_notes_blob', $closed['closure_notes_cipher']);
        self::assertNotNull($closed['closed_at']);

        // Active should now be null since it's closed
        self::assertNull($model->findActiveByPersonId(101));

        // Soft delete
        $model->delete($id);
        self::assertNull($model->find($id));
        $raw = $this->db->table('guidance_episodes')->where('id', $id)->get()->getRowArray();
        self::assertNotNull($raw);
        self::assertNotNull($raw['deleted_at']);

        // Multi-tenant isolation
        CurrentTenant::set(2);
        self::assertCount(0, $model->getByPersonId(101));
        self::assertNull($model->findActiveByPersonId(101));
    }

    public function testGuidanceAppointmentModelCrudAndScopes(): void
    {
        $model = new GuidanceAppointmentModel();

        $data = [
            'tenant_id' => 1,
            'episode_id' => 10,
            'person_id' => 102,
            'counselor_user_id' => 202,
            'appointment_number' => 'APT-20261113-0001',
            'scheduled_date' => '2026-11-15',
            'scheduled_time' => '10:00:00',
            'purpose' => 'routine_interview',
            'purpose_other' => null,
            'status' => 'scheduled',
            'form_instance_id' => null,
            'queue_ticket_id' => 55,
            'notes_cipher' => 'encrypted_notes_sample',
        ];

        $id = $model->insert($data, true);
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);

        $row = $model->findByAppointmentNumber('APT-20261113-0001');
        self::assertNotNull($row);
        self::assertSame('routine_interview', $row['purpose']);
        self::assertSame('scheduled', $row['status']);
        self::assertSame(55, (int) $row['queue_ticket_id']);
        self::assertSame('encrypted_notes_sample', $row['notes_cipher']);

        // Helper methods
        $byEpisode = $model->getByEpisodeId(10);
        self::assertCount(1, $byEpisode);

        $byPerson = $model->getByPersonId(102);
        self::assertCount(1, $byPerson);

        $byCounselor = $model->getByCounselor(202, '2026-11-15');
        self::assertCount(1, $byCounselor);

        // Status transition
        $model->update($id, ['status' => 'attended']);
        $updated = $model->find($id);
        self::assertSame('attended', $updated['status']);

        // Soft delete
        $model->delete($id);
        self::assertNull($model->find($id));
        $raw = $this->db->table('guidance_appointments')->where('id', $id)->get()->getRowArray();
        self::assertNotNull($raw['deleted_at']);

        // Multi-tenant scoping
        CurrentTenant::set(2);
        self::assertNull($model->findByAppointmentNumber('APT-20261113-0001'));
    }

    public function testGuidanceCallSlipModelCrudAndScopes(): void
    {
        $model = new GuidanceCallSlipModel();

        $data = [
            'tenant_id' => 1,
            'episode_id' => 10,
            'person_id' => 103,
            'issuing_counselor_user_id' => 203,
            'call_slip_number' => 'CS-20261113-0001',
            'call_date' => '2026-11-16',
            'call_time' => '14:30:00',
            'purpose' => 'academic_counseling',
            'purpose_other' => null,
            'attendance_status' => 'pending',
            'parent_guardian_delivery' => 1,
            'delivery_notes' => 'Handed to guardian during campus visit.',
            'form_instance_id' => null,
        ];

        $id = $model->insert($data, true);
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);

        $row = $model->findByCallSlipNumber('CS-20261113-0001');
        self::assertNotNull($row);
        self::assertSame('academic_counseling', $row['purpose']);
        self::assertSame('pending', $row['attendance_status']);
        self::assertSame(1, (int) $row['parent_guardian_delivery']);
        self::assertSame('Handed to guardian during campus visit.', $row['delivery_notes']);

        // Helper methods
        $byEpisode = $model->getByEpisodeId(10);
        self::assertCount(1, $byEpisode);

        $byPerson = $model->getByPersonId(103);
        self::assertCount(1, $byPerson);

        $byCounselor = $model->getByCounselor(203, '2026-11-16');
        self::assertCount(1, $byCounselor);

        // Acknowledge and Attend updates
        $model->update($id, [
            'attendance_status' => 'acknowledged',
            'acknowledged_at' => '2026-11-14 09:00:00',
        ]);
        $rowAck = $model->find($id);
        self::assertSame('acknowledged', $rowAck['attendance_status']);
        self::assertNotNull($rowAck['acknowledged_at']);

        $model->update($id, [
            'attendance_status' => 'attended',
            'attended_at' => '2026-11-16 14:35:00',
        ]);
        $rowAtt = $model->find($id);
        self::assertSame('attended', $rowAtt['attendance_status']);
        self::assertNotNull($rowAtt['attended_at']);

        // Soft delete
        $model->delete($id);
        self::assertNull($model->find($id));
        $raw = $this->db->table('guidance_call_slips')->where('id', $id)->get()->getRowArray();
        self::assertNotNull($raw['deleted_at']);

        // Multi-tenant scoping
        CurrentTenant::set(2);
        self::assertNull($model->findByCallSlipNumber('CS-20261113-0001'));
    }

    public function testDownMigrationDropsTablesAndPermissions(): void
    {
        $migration = new CreateGuidanceEpisodesAppointmentsAndCallSlipsKernel();
        $migration->down();

        $tables = $this->db->listTables();
        self::assertNotContains('guidance_call_slips', $tables);
        self::assertNotContains('guidance_appointments', $tables);
        self::assertNotContains('guidance_episodes', $tables);

        $aptPerm = $this->db->table('permissions')->where('code', 'guidance.appointments.manage')->get()->getRowArray();
        self::assertNull($aptPerm);

        // Re-run up to leave database in clean state
        $migration->up();
        $tables = $this->db->listTables();
        self::assertContains('guidance_call_slips', $tables);
        self::assertContains('guidance_appointments', $tables);
        self::assertContains('guidance_episodes', $tables);
    }
}
