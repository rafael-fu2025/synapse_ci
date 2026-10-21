<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AuditEventModel;
use App\Services\Audit\AuditService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\FreshDatabaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class AuditServiceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private AuditService $auditService;
    private AuditEventModel $auditModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditModel = new AuditEventModel();
        $this->auditService = new AuditService($this->auditModel);
    }

    public function testHashChainIntegrityAndVerification(): void
    {
        $ev1 = $this->auditService->log('user.login', 'user', '101', 'login', ['ip' => '127.0.0.1'], 101);
        $ev2 = $this->auditService->log('encounter.started', 'clinic_encounter', '201', 'create', ['patient' => 'P1'], 101);
        $ev3 = $this->auditService->log('batch.released', 'bmg_batch', '301', 'release', ['yield' => 150], 101);

        // Verify chain links
        $this->assertSame($ev1['curr_hash'], $ev2['prev_hash'], 'Event 2 prev_hash must link to Event 1 curr_hash.');
        $this->assertSame($ev2['curr_hash'], $ev3['prev_hash'], 'Event 3 prev_hash must link to Event 2 curr_hash.');

        // Chain verification must report valid
        $verification = $this->auditService->verifyChain();
        $this->assertTrue($verification['valid']);
        $this->assertGreaterThanOrEqual(3, $verification['verified_count']);
        $this->assertNull($verification['broken_id']);
    }

    public function testDatabaseRejectsAuditPayloadUpdate(): void
    {
        $ev = $this->auditService->log('test.tamper', 'test_entity', '999', 'update', ['authorized' => true], 1);
        $eventId = (int) $ev['id'];
        $db = \Config\Database::connect();
        $original = $db->table('audit_events')->where('id', $eventId)->get()->getRowArray();

        $this->expectException(DatabaseException::class);
        try {
            $db->table('audit_events')
                ->where('id', $eventId)
                ->update(['payload_json' => json_encode(['authorized' => false, 'tampered' => true])]);
        } finally {
            $this->assertSame($original, $db->table('audit_events')->where('id', $eventId)->get()->getRowArray());
        }
    }

    public function testDatabaseRejectsAuditEventDeletion(): void
    {
        $ev = $this->auditService->log('test.delete', 'test_entity', '999', 'delete', ['authorized' => true], 1);
        $eventId = (int) $ev['id'];
        $db = \Config\Database::connect();
        $original = $db->table('audit_events')->where('id', $eventId)->get()->getRowArray();

        $this->expectException(DatabaseException::class);
        try {
            $db->table('audit_events')->where('id', $eventId)->delete();
        } finally {
            $this->assertSame($original, $db->table('audit_events')->where('id', $eventId)->get()->getRowArray());
        }
    }
}
