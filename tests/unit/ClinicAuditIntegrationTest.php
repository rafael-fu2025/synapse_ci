<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AppointmentModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\Clinic\EncounterService;
use App\Services\CurrentTenant;
use App\Services\Scheduling\QueueService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\FreshDatabaseTestCase;

/** Only run through run-fresh-database-tests.py; all fixtures/triggers are disposable. */
final class ClinicAuditIntegrationTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private int $tenantId;
    private int $patientId;
    private int $actorId;
    private EncounterService $encounters;
    private ?BaseConnection $observer = null;

    protected function setUp(): void
    {
        parent::setUp(); // FreshDatabaseTestCase verifies the runner before any migrations.
        $schema = HUMUS_FRESH_DATABASE_VERIFIED;
        $this->assertSame($schema, $this->db->query('SELECT DATABASE() AS name')->getRowArray()['name']);
        $this->db->query('SET SESSION innodb_lock_wait_timeout=2');
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('clinic_audit_'), 'name' => 'Disposable Clinic audit',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tenantId = (int) $this->db->insertID();
        CurrentTenant::set($this->tenantId);
        $this->actorId = (int) (new UserModel($this->db))->insert([
            'tenant_id' => $this->tenantId, 'username' => uniqid('clinic_audit_'), 'role' => 'superadmin', 'status' => 'active',
        ]);
        $this->patientId = (int) (new PersonModel($this->db))->insert([
            'tenant_id' => $this->tenantId, 'kind' => 'student', 'mis_id' => uniqid('clinic_audit_'),
            'first_name' => 'Disposable', 'last_name' => 'Patient',
        ]);
        $this->encounters = new EncounterService(db: $this->db);
        $this->observer = \Config\Database::connect(config('Database')->tests, false);
        $this->assertSame($schema, $this->observer->query('SELECT DATABASE() AS name')->getRowArray()['name']);
        $this->observer->query('SET SESSION innodb_lock_wait_timeout=2');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->transDepth > 0) { $this->db->transRollback(); }
            $this->db->resetTransStatus();
            $this->db->query('DROP TRIGGER IF EXISTS clinic_audit_fail_queue');
        }
        if ($this->observer !== null) {
            while ($this->observer->transDepth > 0) { $this->observer->transRollback(); }
            $this->observer->close();
        }
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function appointment(array $overrides = []): int
    {
        return (int) (new AppointmentModel($this->db))->insert(array_replace([
            'tenant_id' => $this->tenantId, 'unit' => 'clinic', 'person_id' => $this->patientId,
            'starts_at' => '2020-01-02 09:00:00', 'ends_at' => '2020-01-02 09:30:00',
            'local_date' => '2020-01-02', 'status' => 'checked_in', 'type' => 'routine', 'version' => 1,
        ], $overrides));
    }

    private function ticket(?int $appointmentId = null, array $overrides = []): int
    {
        static $number = 0;
        $number++;
        $this->assertTrue($this->db->table('queue_entries')->insert(array_replace([
            'tenant_id' => $this->tenantId, 'unit' => 'clinic', 'person_id' => $this->patientId,
            'appointment_id' => $appointmentId, 'queue_date' => '2020-01-02',
            'ticket_no' => $number, 'ticket_code' => 'C-' . $number, 'status' => 'called', 'priority' => 'routine',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides)));
        return (int) $this->db->insertID();
    }

    private function row(string $table, int $id, ?BaseConnection $db = null): array
    {
        return ($db ?? $this->db)->table($table)->where('tenant_id', $this->tenantId)->where('id', $id)->get()->getRowArray();
    }

    private function reject(callable $operation, int $code = 409): void
    {
        try { $operation(); } catch (\Throwable $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
            $this->assertSame(0, $this->db->transDepth);
            return;
        }
        $this->fail('Expected Clinic operation rejection.');
    }

    private function assertNoEncounters(): void
    {
        $this->assertSame(0, $this->db->table('clinic_encounters')->where('tenant_id', $this->tenantId)->countAllResults());
    }

    /** Synchronous DBQuery barriers make the two real connections interleave deterministically. */
    private function duringQueries(callable $listener, callable $operation): mixed
    {
        Events::on('DBQuery', $listener);
        try { return $operation(); } finally { Events::removeListener('DBQuery', $listener); }
    }

    public function testAppointmentIsLockedBeforeTicketAndTicketRetryIsIdempotent(): void
    {
        $appointment = $this->appointment();
        $ticket = $this->ticket($appointment);
        $probeRan = false;
        $listener = function ($query) use (&$probeRan, $ticket): void {
            $sql = $query->getQuery();
            if ($probeRan || !str_contains($sql, 'FROM `appointments`') || !str_contains($sql, 'FOR UPDATE')) { return; }
            $probeRan = true;
            // Clinic owns the appointment now. A rival can still lock this ticket:
            // if Clinic had taken the ticket first, this bounded probe would time out.
            $this->observer->transBegin();
            try {
                $locked = $this->observer->query('SELECT id FROM queue_entries WHERE tenant_id=? AND id=? FOR UPDATE', [$this->tenantId, $ticket])->getRowArray();
                $this->assertSame($ticket, (int) $locked['id']);
            } finally { $this->observer->transRollback(); }
        };
        $first = $this->duringQueries($listener, fn () => $this->encounters->startEncounter($this->patientId, $this->actorId, $ticket));
        $this->assertTrue($probeRan, 'The linked appointment must be explicitly locked before the ticket.');
        $retry = $this->encounters->startEncounter($this->patientId, $this->actorId, $ticket);
        $this->assertSame($first['encounter']['id'], $retry['encounter']['id']);
        $this->assertSame('in_consultation', $this->row('queue_entries', $ticket)['status']);
        $this->assertSame(1, $this->db->table('clinic_encounters')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->encounters->completeEncounter((int) $first['encounter']['id'], 'discharged');
        $closedRetry = $this->encounters->startEncounter($this->patientId, $this->actorId, $ticket);
        $this->assertSame($first['encounter']['id'], $closedRetry['encounter']['id']);
        $this->assertSame('completed', $closedRetry['encounter']['status']);
        $this->assertSame('completed', $this->row('appointments', $appointment)['status']);
    }

    public function testDirectRetryResumesOpenVisitButLaterVisitIsAllowed(): void
    {
        $first = $this->encounters->startEncounter($this->patientId, $this->actorId);
        $retry = $this->encounters->startEncounter($this->patientId, $this->actorId);
        $this->assertSame($first['encounter']['id'], $retry['encounter']['id']);
        $this->encounters->completeEncounter((int) $first['encounter']['id'], 'discharged');
        $later = $this->encounters->startEncounter($this->patientId, $this->actorId);
        $this->assertNotSame($first['encounter']['id'], $later['encounter']['id']);
    }

    public function testNoShowWinningAfterLinkageReadCannotLeaveAnOrphanEncounter(): void
    {
        $appointment = $this->appointment();
        $ticket = $this->ticket($appointment);
        $noShowRan = false;
        $listener = function ($query) use (&$noShowRan, $appointment, $ticket): void {
            $sql = $query->getQuery();
            if ($noShowRan || !str_contains($sql, 'FROM `queue_entries`') || str_contains($sql, 'FOR UPDATE')) { return; }
            $noShowRan = true;
            // Scheduling's permission refresh uses the shared connection. DBQuery fires
            // before CI wraps its resultID, so retain the pending candidate read's result.
            $resultProperty = new \ReflectionProperty($this->db, 'resultID');
            $pendingResult = $resultProperty->getValue($this->db);
            try {
                (new QueueService(db: $this->observer))->markAppointmentNoShow('clinic', $appointment, 1,
                    ['id' => $this->actorId, 'role' => 'superadmin'], $ticket);
            } finally { $resultProperty->setValue($this->db, $pendingResult); }
        };
        $this->duringQueries($listener, fn () => $this->reject(fn () => $this->encounters->startEncounter($this->patientId, $this->actorId, $ticket)));
        $this->assertTrue($noShowRan);
        $this->assertSame('no_show', $this->row('appointments', $appointment)['status']);
        $this->assertSame('no_show', $this->row('queue_entries', $ticket)['status']);
        $this->assertNoEncounters();
    }

    public function testChangedLinkageIsRejectedAfterAppointmentLockBeforeInsertion(): void
    {
        $appointment = $this->appointment();
        $replacement = $this->appointment();
        $ticket = $this->ticket($appointment);
        $changed = false;
        $listener = function ($query) use (&$changed, $replacement, $ticket): void {
            $sql = $query->getQuery();
            if ($changed || !str_contains($sql, 'FROM `appointments`') || !str_contains($sql, 'FOR UPDATE')) { return; }
            $changed = true;
            $this->assertTrue($this->observer->table('queue_entries')->where('tenant_id', $this->tenantId)->where('id', $ticket)->update(['appointment_id' => $replacement]));
        };
        $this->duringQueries($listener, fn () => $this->reject(fn () => $this->encounters->startEncounter($this->patientId, $this->actorId, $ticket)));
        $this->assertTrue($changed);
        $this->assertSame($replacement, (int) $this->row('queue_entries', $ticket)['appointment_id']);
        $this->assertNoEncounters();
    }

    public function testInvalidLinkedAppointmentsAreRejected(): void
    {
        $otherPatient = (int) (new PersonModel($this->db))->insert([
            'tenant_id' => $this->tenantId, 'kind' => 'student', 'mis_id' => uniqid('other_'), 'first_name' => 'Other', 'last_name' => 'Patient',
        ]);
        foreach ([['unit' => 'guidance'], ['person_id' => $otherPatient], ['status' => 'no_show'], ['deleted_at' => '2020-01-03 00:00:00']] as $values) {
            $appointment = $this->appointment(array_diff_key($values, ['deleted_at' => true]));
            if (isset($values['deleted_at'])) {
                $this->db->table('appointments')->where('id', $appointment)->update($values);
            }
            $ticket = $this->ticket($appointment);
            $this->reject(fn () => $this->encounters->startEncounter($this->patientId, $this->actorId, $ticket));
            $this->assertSame('called', $this->row('queue_entries', $ticket)['status']);
        }
        $this->assertNoEncounters();
    }

    public function testQueueTransitionFailureRollsBackInsertedEncounterAndAllowsRetry(): void
    {
        $appointment = $this->appointment();
        $ticket = $this->ticket($appointment);
        $this->db->query("CREATE TRIGGER clinic_audit_fail_queue BEFORE UPDATE ON queue_entries FOR EACH ROW
            BEGIN IF NEW.id={$ticket} AND NEW.status='in_consultation' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Clinic audit injected transition failure'; END IF; END");
        try {
            try {
                $this->encounters->startEncounter($this->patientId, $this->actorId, $ticket);
                $this->fail('Expected injected queue failure.');
            } catch (\CodeIgniter\Database\Exceptions\DatabaseException | \RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
            $this->assertSame(0, $this->db->transDepth);
            $this->assertSame('called', $this->row('queue_entries', $ticket)['status']);
            $this->assertSame('checked_in', $this->row('appointments', $appointment)['status']);
            $this->assertNoEncounters();
        } finally { $this->db->query('DROP TRIGGER clinic_audit_fail_queue'); }
        $result = $this->encounters->startEncounter($this->patientId, $this->actorId, $ticket);
        $this->assertSame('in_progress', $result['encounter']['status']);
        $this->assertSame('in_consultation', $this->row('queue_entries', $ticket)['status']);
    }

    public function testQueueServiceJoinsCallerTransactionWithoutCommittingIt(): void
    {
        $ticket = $this->ticket();
        $this->db->transBegin();
        try {
            (new QueueService(db: $this->db))->startConsultation($ticket, 'clinic');
            $this->assertSame(1, $this->db->transDepth);
            $this->assertSame('in_consultation', $this->row('queue_entries', $ticket)['status']);
            $this->assertSame('called', $this->row('queue_entries', $ticket, $this->observer)['status']);
        } finally { $this->db->transRollback(); }
        $this->assertSame('called', $this->row('queue_entries', $ticket)['status']);
    }
}
