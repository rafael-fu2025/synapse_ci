<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audit\AuditService;
use App\Services\AuthenticatedUser;
use App\Services\Facilities\BmgService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use InvalidArgumentException;
use ReflectionProperty;
use Throwable;

/** Disposable SQLite fixtures only: no DatabaseTestTrait, migrations, or configured MySQL. */
final class BmgServiceTest extends CIUnitTestCase
{
    private BaseConnection $batchDb;
    private BmgService $service;
    private array $connections;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('SQLite3 is required for isolated batch fixtures.');
        }
        $this->batchDb = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        $property = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $property->getValue();
        // Fresh authentication and its permission model must use this same disposable DB.
        $property->setValue(null, ['tests' => $this->batchDb]);
        $this->fixture();
        $this->service = new BmgService($this->batchDb);
        $this->signIn();
    }

    protected function tearDown(): void
    {
        if (isset($this->batchDb)) {
            AuthenticatedUser::clear();
            $this->batchDb->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        }
        parent::tearDown();
    }

    public function testCreateUsesExactWeightsAndAuditsActorAndCreationNotes(): void
    {
        $batch = $this->service->createBatch(1, '350.10', ' Initial load ');
        $this->assertSame('processing', $batch['status']);
        $this->assertSame('350.10', $batch['total_input_weight_kg']);
        $this->assertSame('unclassified', $batch['quality_grade']);
        $this->assertNull($batch['release_notes']);
        $this->assertNull($batch['output_weight_kg']);
        $this->assertNotNull($batch['started_at']);
        $this->assertMatchesRegularExpression('/^BATCH-' . date('Y') . '-[a-f0-9]{20}$/', $batch['batch_code']);
        $this->assertLessThanOrEqual(32, strlen($batch['batch_code']));
        $audit = $this->batchDb->table('audit_events')->get()->getRowArray();
        $this->assertSame(7, (int) $audit['actor_user_id']);
        $this->assertSame('bmg_admin', $audit['actor_role']);
        $this->assertSame(1, (int) $audit['tenant_id']);
        $this->assertSame('bmg.batch.created', $audit['event_type']);
        $this->assertSame((string) $batch['id'], $audit['entity_id']);
        $this->assertSame('Initial load', json_decode($audit['payload_json'], true)['notes']);
    }

    public function testCapacityAndSingleActiveBatchIncludingLegacyActiveStates(): void
    {
        $this->invalid(fn () => $this->service->createBatch(1, '500.01'));
        $batch = $this->service->createBatch(1, '500.00');
        foreach (['processing', 'awaiting_output', 'curing'] as $status) {
            $this->batchDb->table('bmg_batches')->where('id', $batch['id'])->update(['status' => $status]);
            $this->reject(fn () => $this->service->createBatch(1, 1), 409);
        }
        $this->assertSame(1, $this->batchDb->table('bmg_batches')->countAllResults());
        $this->assertSame(1, $this->batchDb->table('audit_events')->countAllResults());
    }

    public function testCreationRequiresActiveUnitWithoutWritingBatchOrAudit(): void
    {
        foreach (['maintenance', 'offline'] as $status) {
            $this->batchDb->table('bmg_units')->where('id', 1)->update(['status' => $status]);
            $this->reject(fn () => $this->service->createBatch(1, 10), 409);
            $this->assertSame(0, $this->batchDb->table('bmg_batches')->countAllResults());
            $this->assertSame(0, $this->batchDb->table('audit_events')->countAllResults());
        }
        $this->batchDb->table('bmg_units')->where('id', 1)->update(['status' => 'active']);
        $this->assertSame('processing', $this->service->createBatch(1, 10)['status']);
    }

    public function testExistingBatchCanCompleteOrCancelWhileUnitIsUnavailable(): void
    {
        foreach (['maintenance', 'offline'] as $status) {
            foreach (['released', 'voided'] as $terminalStatus) {
                $this->batchDb->table('bmg_units')->where('id', 1)->update(['status' => 'active']);
                $batch = $this->service->createBatch(1, 10);
                $this->batchDb->table('bmg_units')->where('id', 1)->update(['status' => $status]);
                $finished = $terminalStatus === 'released'
                    ? $this->service->releaseBatch((int) $batch['id'], null)
                    : $this->service->cancelBatch((int) $batch['id'], 'Unit unavailable');
                $this->assertSame($terminalStatus, $finished['status']);
                $this->assertNotNull($finished['completed_at']);
                $this->assertSame($status, $this->batchDb->table('bmg_units')->where('id', 1)->get()->getRowArray()['status']);
            }
        }
    }

    public function testInvalidInputWeightsAreNeverCoercedOrRounded(): void
    {
        foreach ([null, '', ' ', 0, '0.00', -1, '-0.01', NAN, INF, -INF, 1.25, true, [], new \stdClass(),
            'NaN', 'INF', '1e2', '+1', '1,000', '1.001', '.5', '1.', ' 1 ', '100000000', '1\n'] as $weight) {
            $this->invalid(fn () => $this->service->createBatch(1, $weight));
        }
        $this->assertSame(0, $this->batchDb->table('bmg_batches')->countAllResults());
        $this->assertSame('0.01', $this->service->createBatch(1, '0.01')['total_input_weight_kg']);
    }

    public function testCompletionAcceptsExactNonnegativeOutputWithoutQualityClaims(): void
    {
        foreach (['0', '300.10'] as $output) {
            $batch = $this->service->createBatch(1, '300.10');
            $released = $this->service->releaseBatch((int) $batch['id'], $output, notes: ' Finished ');
            $this->assertSame('released', $released['status']);
            $this->assertSame($output === '0' ? '0.00' : $output, $released['output_weight_kg']);
            $this->assertSame('unclassified', $released['quality_grade']);
            $this->assertSame('Finished', $released['release_notes']);
            $this->assertNotNull($released['completed_at']);
            $this->reject(fn () => $this->service->releaseBatch((int) $batch['id'], 0), 409);
            $this->reject(fn () => $this->service->cancelBatch((int) $batch['id'], 'Too late'), 409);
        }
        $this->assertTrue((new AuditService())->verifyChain()['valid']);
    }

    public function testUnknownOutputRemainsNull(): void
    {
        foreach ([null, '', '  '] as $output) {
            $batch = $this->service->createBatch(1, 100);
            $released = $this->service->releaseBatch((int) $batch['id'], $output);
            $this->assertNull($released['output_weight_kg']);
            $this->assertSame('released', $released['status']);
        }
    }

    public function testLegacyActiveStatesCanFinishButIdleCannot(): void
    {
        foreach (['awaiting_output', 'curing'] as $status) {
            $batch = $this->service->createBatch(1, 10);
            $this->batchDb->table('bmg_batches')->where('id', $batch['id'])->update(['status' => $status]);
            $this->assertSame('released', $this->service->releaseBatch((int) $batch['id'], null)['status']);
        }
        $batch = $this->service->createBatch(1, 10);
        $this->batchDb->table('bmg_batches')->where('id', $batch['id'])->update(['status' => 'idle']);
        $this->reject(fn () => $this->service->releaseBatch((int) $batch['id'], null), 409);
        $this->reject(fn () => $this->service->cancelBatch((int) $batch['id'], 'No'), 409);
        $this->assertNull($this->service->batchOverview(1)['activeBatch']);
    }

    public function testBatchCodesAreDistinctAcrossUnitsWithoutSequentialCounter(): void
    {
        $this->batchDb->table('bmg_units')->insert(['id' => 4, 'tenant_id' => 1, 'unit_code' => 'second', 'status' => 'active', 'drum_capacity_kg' => '500.00']);
        $first = $this->service->createBatch(1, 10);
        $second = $this->service->createBatch(4, 10);
        $this->assertNotSame($first['batch_code'], $second['batch_code']);
        $this->assertCount(2, $this->service->getUnitsOverview());
        $this->assertTrue((new AuditService())->verifyChain()['valid']);
    }

    public function testInvalidOutputAndClaimedGradesLeaveBatchActive(): void
    {
        $batch = $this->service->createBatch(1, '10.01');
        foreach ([-1, '-0.01', NAN, INF, -INF, 1.25, true, [], '1e1', '0.001', '10.02', 'NaN', '100000000'] as $output) {
            $this->invalid(fn () => $this->service->releaseBatch((int) $batch['id'], $output));
        }
        foreach (['grade_a', 'grade_b', '', 'UNCLASSIFIED', 'sanitized'] as $grade) {
            $this->invalid(fn () => $this->service->releaseBatch((int) $batch['id'], null, $grade));
        }
        $this->assertSame('processing', $this->service->batchOverview(1)['activeBatch']['status']);
        $this->assertSame(1, $this->batchDb->table('audit_events')->countAllResults());
    }

    public function testCancellationPersistsLegacyVoidedStatusAndReason(): void
    {
        $batch = $this->service->createBatch(1, 100);
        $this->invalid(fn () => $this->service->cancelBatch((int) $batch['id'], '  '));
        $cancelled = $this->service->cancelBatch((int) $batch['id'], ' Contaminated load ');
        $this->assertSame('voided', $cancelled['status']);
        $this->assertSame('Contaminated load', $cancelled['release_notes']);
        $this->assertSame('unclassified', $cancelled['quality_grade']);
        $this->assertNull($cancelled['output_weight_kg']);
        $this->assertNotNull($cancelled['completed_at']);
        $this->reject(fn () => $this->service->cancelBatch((int) $batch['id'], 'Again'), 409);
        $this->reject(fn () => $this->service->releaseBatch((int) $batch['id'], null), 409);
        $next = $this->service->createBatch(1, 1);
        $this->assertNotSame($batch['batch_code'], $next['batch_code']);
        $audit = $this->batchDb->table('audit_events')->where('event_type', 'bmg.batch.cancelled')->get()->getRowArray();
        $this->assertSame(7, (int) $audit['actor_user_id']);
        $this->assertSame('cancel', $audit['action']);
    }

    public function testNotesHaveCharacterLimitAcrossOperations(): void
    {
        $long = str_repeat('é', 2001);
        $this->invalid(fn () => $this->service->createBatch(1, 1, $long));
        $batch = $this->service->createBatch(1, 1, str_repeat('é', 2000));
        $this->invalid(fn () => $this->service->releaseBatch((int) $batch['id'], null, notes: $long));
        $this->invalid(fn () => $this->service->cancelBatch((int) $batch['id'], $long));
        $this->assertSame('released', $this->service->releaseBatch((int) $batch['id'], null, notes: str_repeat('é', 2000))['status']);
    }

    public function testOverviewsSeparateHistoryAndDoNotExposeForeignOrDeletedUnits(): void
    {
        $first = $this->service->createBatch(1, 100);
        $this->service->releaseBatch((int) $first['id'], null);
        $second = $this->service->createBatch(1, 100);
        $this->service->cancelBatch((int) $second['id'], 'Cancelled');
        $third = $this->service->createBatch(1, 100);
        $view = $this->service->batchOverview(1);
        $this->assertSame((int) $third['id'], (int) $view['activeBatch']['id']);
        $this->assertSame([(int) $second['id'], (int) $first['id']], array_map('intval', array_column($view['pastBatches'], 'id')));
        $units = $this->service->getUnitsOverview();
        $this->assertCount(1, $units);
        $this->assertSame((int) $third['id'], (int) $units[0]['active_batch']['id']);
    }

    public function testHistoryIsLimitedToLatestFiftyWithoutHidingActiveBatch(): void
    {
        for ($id = 1; $id <= 55; $id++) {
            $this->batchDb->table('bmg_batches')->insert([
                'id' => $id, 'tenant_id' => 1, 'unit_id' => 1, 'batch_code' => 'history-' . $id,
                'status' => $id % 2 === 0 ? 'released' : 'voided', 'total_input_weight_kg' => '10.00',
                'started_at' => $id === 1 ? '2026-10-17 12:00:00' : '2026-10-16 12:00:00',
            ]);
        }
        $active = $this->service->createBatch(1, 10);
        $view = $this->service->batchOverview(1);
        $this->assertSame((int) $active['id'], (int) $view['activeBatch']['id']);
        $this->assertCount(50, $view['pastBatches']);
        $this->assertSame(array_merge([1], range(55, 7)), array_map('intval', array_column($view['pastBatches'], 'id')));
    }

    public function testAllIdentifiersAreTenantScopedAndUnitSoftDeletesAreRespected(): void
    {
        $this->batchDb->query("INSERT INTO bmg_batches (id, tenant_id, unit_id, batch_code, status, total_input_weight_kg) VALUES (900, 2, 2, 'foreign', 'processing', '10.00')");
        foreach ([2, 3, 999] as $unit) {
            $this->reject(fn () => $this->service->batchOverview($unit), 404);
            $this->reject(fn () => $this->service->createBatch($unit, 1), 404);
        }
        foreach ([900, 999] as $id) {
            $this->reject(fn () => $this->service->releaseBatch($id, null), 404);
            $this->reject(fn () => $this->service->cancelBatch($id, 'No'), 404);
        }
        $batch = $this->service->createBatch(1, 100);
        $this->batchDb->table('bmg_units')->where('id', 1)->update(['deleted_at' => '2026-10-17 00:00:00']);
        $this->reject(fn () => $this->service->releaseBatch((int) $batch['id'], null), 404);
        $this->reject(fn () => $this->service->cancelBatch((int) $batch['id'], 'No'), 404);
        $this->assertSame([], $this->service->getUnitsOverview());
        $this->assertSame('processing', $this->batchDb->table('bmg_batches')->where('id', 900)->get()->getRowArray()['status']);
    }

    public function testForeignBatchCannotBlockOrAppearOnLocalUnit(): void
    {
        // Defense in depth for historical rows whose unit and batch tenant disagree.
        $this->batchDb->query("INSERT INTO bmg_batches (tenant_id, unit_id, batch_code, status, total_input_weight_kg) VALUES (2, 1, 'foreign-mismatch', 'processing', '10.00')");
        $this->assertNull($this->service->batchOverview(1)['activeBatch']);
        $this->assertNull($this->service->getUnitsOverview()[0]['active_batch']);
        $batch = $this->service->createBatch(1, 10);
        $this->assertSame((int) $batch['id'], (int) $this->service->batchOverview(1)['activeBatch']['id']);
    }

    public function testPermissionsAreRefreshedAndSeparateReadsFromWrites(): void
    {
        $batch = $this->service->createBatch(1, 10);
        $this->batchDb->table('role_permissions')->where('role', 'bmg_admin')->where('permission_id', 2)->delete();
        $this->signIn(); // Forged session grants cannot restore a revoked DB grant.
        $this->reject(fn () => $this->service->createBatch(1, 1), 403);
        $this->reject(fn () => $this->service->releaseBatch((int) $batch['id'], null), 403);
        $this->reject(fn () => $this->service->cancelBatch((int) $batch['id'], 'No'), 403);
        $this->assertNotNull($this->service->batchOverview(1)['activeBatch']);
        $this->batchDb->table('role_permissions')->where('role', 'bmg_admin')->delete();
        $this->reject(fn () => $this->service->getUnitsOverview(), 403);
        $this->reject(fn () => $this->service->batchOverview(1), 403);
    }

    public function testReadPermissionIsRequiredEvenForManager(): void
    {
        $this->batchDb->table('role_permissions')->where('role', 'bmg_admin')->where('permission_id', 1)->delete();
        $this->reject(fn () => $this->service->getUnitsOverview(), 403);
        $this->reject(fn () => $this->service->batchOverview(1), 403);
        $this->assertSame('processing', $this->service->createBatch(1, 10)['status']);
    }

    public function testAllowedRolesComeFromDatabaseNotSession(): void
    {
        foreach (['facilities_op', 'superadmin'] as $role) {
            $this->batchDb->table('users')->where('id', 7)->update(['role' => $role]);
            $batch = $this->service->createBatch(1, 10);
            $this->service->cancelBatch((int) $batch['id'], 'Next role');
            $audit = $this->batchDb->table('audit_events')->orderBy('id', 'DESC')->get()->getRowArray();
            $this->assertSame($role, $audit['actor_role']);
        }
        // Even a valid non-facilities role with DB grants is not a facilities actor.
        $this->batchDb->table('users')->where('id', 7)->update(['role' => 'employee']);
        $this->signIn();
        $this->reject(fn () => $this->service->createBatch(1, 10), 403);
        $this->reject(fn () => $this->service->batchOverview(1), 403);
    }

    public function testTokenHeadersCannotActAsHumanEvenWithValidSession(): void
    {
        $batch = $this->service->createBatch(1, 10);
        $request = service('request');
        foreach (['X-Device-Token' => 'device-token', 'Authorization' => 'Bearer human-token'] as $header => $value) {
            $request->setHeader($header, $value);
            try {
                foreach ([true, false] as $signedIn) {
                    if ($signedIn) { $this->signIn(); }
                    else { AuthenticatedUser::clear(); }
                    $this->reject(fn () => $this->service->createBatch(1, 10), 403);
                    $this->reject(fn () => $this->service->releaseBatch((int) $batch['id'], null), 403);
                    $this->reject(fn () => $this->service->cancelBatch((int) $batch['id'], 'No'), 403);
                    $this->reject(fn () => $this->service->batchOverview(1), 403);
                    $this->reject(fn () => $this->service->getUnitsOverview(), 403);
                }
                $this->assertSame(1, $this->batchDb->table('bmg_batches')->countAllResults());
                $this->assertSame(1, $this->batchDb->table('audit_events')->countAllResults());
            } finally {
                $request->removeHeader($header);
            }
        }
        $this->signIn();
        $this->assertSame('released', $this->service->releaseBatch((int) $batch['id'], null)['status']);
    }

    public function testMissingSuspendedAndMismatchedAuthenticationIsRejected(): void
    {
        AuthenticatedUser::clear();
        $this->reject(fn () => $this->service->createBatch(1, 1), 401);
        $this->signIn();
        session()->set('tenant_id', 2);
        $this->reject(fn () => $this->service->getUnitsOverview(), 401);
        $this->signIn();
        $this->batchDb->table('users')->where('id', 7)->update(['status' => 'suspended']);
        $this->reject(fn () => $this->service->createBatch(1, 1), 401);
        $this->assertSame(0, $this->batchDb->table('bmg_batches')->countAllResults());
    }

    public function testInactiveTenantCannotReadOrWrite(): void
    {
        $this->batchDb->table('tenants')->where('id', 1)->update(['is_active' => 0]);
        $this->reject(fn () => $this->service->batchOverview(1), 401);
        $this->signIn();
        $this->reject(fn () => $this->service->createBatch(1, 10), 401);
    }

    public function testAuditFailureRollsBackCreationAndBothTerminalTransitions(): void
    {
        $batch = $this->service->createBatch(1, 100);
        $this->batchDb->query("CREATE TRIGGER reject_batch_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
        foreach ([fn () => $this->service->releaseBatch((int) $batch['id'], 10),
            fn () => $this->service->cancelBatch((int) $batch['id'], 'Cancel')] as $operation) {
            $this->fails($operation);
            $stored = $this->service->batchOverview(1)['activeBatch'];
            $this->assertSame('processing', $stored['status']);
            $this->assertNull($stored['completed_at']);
            $this->assertNull($stored['release_notes']);
            $this->assertNull($stored['output_weight_kg']);
            // Each failure is independent, not just CI's sticky strict-mode failure flag.
            $this->batchDb->resetTransStatus();
        }
        $this->batchDb->table('bmg_batches')->delete(['id' => $batch['id']]);
        $this->fails(fn () => $this->service->createBatch(1, 10));
        $this->assertSame(0, $this->batchDb->table('bmg_batches')->countAllResults());
        $this->assertSame(1, $this->batchDb->table('audit_events')->countAllResults());
        $this->batchDb->query('DROP TRIGGER reject_batch_audit');
        $this->batchDb->resetTransStatus();
        $this->assertSame('processing', $this->service->createBatch(1, 10)['status']);
    }

    public function testSensorRecordsAreUntouchedAndSensorApisAreAbsent(): void
    {
        $batch = $this->service->createBatch(1, 100);
        $this->service->releaseBatch((int) $batch['id'], null);
        $this->assertSame([['id' => 1, 'notes' => 'historical sensor record']], $this->batchDb->table('bmg_process_logs')->get()->getResultArray());
        $this->assertFalse(method_exists($this->service, 'recordLog'));
        $this->assertFalse(method_exists($this->service, 'checkEpaSanitation'));
    }

    private function signIn(): void
    {
        session()->set('_auth', ['started_at' => time(), 'last_seen_at' => time(),
            'version' => 1, 'session_id' => session_id()]);
        session()->set(['tenant_id' => 1, 'user' => ['id' => 7, 'tenant_id' => 1, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['facilities.read', 'facilities.manage']]]);
    }

    private function reject(callable $operation, int $code): void
    {
        try { $operation(); } catch (Throwable $error) {
            $this->assertSame($code, $error->getCode(), $error->getMessage());
            return;
        }
        $this->fail('Expected rejection with code ' . $code);
    }

    private function invalid(callable $operation): void
    {
        try { $operation(); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); return; }
        $this->fail('Expected validation rejection.');
    }

    private function fails(callable $operation): void
    {
        try { $operation(); } catch (Throwable) { $this->addToAssertionCount(1); return; }
        $this->fail('Expected persistence failure.');
    }

    private function fixture(): void
    {
        $statements = [
            'CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)',
            'CREATE TABLE users (auth_version INTEGER NOT NULL DEFAULT 1, id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, role TEXT, username TEXT, email TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE people (id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, deleted_at TEXT)',
            'CREATE TABLE employee_profiles (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, is_teaching INTEGER)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY, code TEXT)',
            'CREATE TABLE role_permissions (role TEXT, permission_id INTEGER)',
            'CREATE TABLE bmg_units (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_code TEXT, name TEXT, status TEXT, drum_capacity_kg TEXT, deleted_at TEXT)',
            "CREATE TABLE bmg_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, unit_id INTEGER, batch_code TEXT, status TEXT, total_input_weight_kg TEXT, output_weight_kg TEXT, quality_grade TEXT DEFAULT 'unclassified', started_at TEXT, completed_at TEXT, notes TEXT, release_notes TEXT, created_at TEXT, updated_at TEXT, UNIQUE(tenant_id, batch_code))",
            'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER NOT NULL, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER NOT NULL DEFAULT 1, occurred_at TEXT)',
            'CREATE TABLE bmg_process_logs (id INTEGER PRIMARY KEY, notes TEXT)',
            'INSERT INTO tenants (id, is_active) VALUES (1, 1), (2, 1)',
            "INSERT INTO people (id, tenant_id, first_name, last_name) VALUES (7, 1, 'Batch', 'Admin')",
            "INSERT INTO users (id, tenant_id, person_id, role, username, status) VALUES (7, 1, 7, 'bmg_admin', 'batch-admin', 'active')",
            "INSERT INTO permissions (id, code) VALUES (1, 'facilities.read'), (2, 'facilities.manage')",
            "INSERT INTO bmg_units (id, tenant_id, unit_code, status, drum_capacity_kg) VALUES (1, 1, 'local', 'active', '500.00'), (2, 2, 'foreign', 'active', '500.00')",
            "INSERT INTO bmg_units (id, tenant_id, unit_code, drum_capacity_kg, deleted_at) VALUES (3, 1, 'deleted', '500.00', '2026-10-17 00:00:00')",
            "INSERT INTO bmg_process_logs (id, notes) VALUES (1, 'historical sensor record')",
        ];
        foreach ($statements as $sql) { $this->batchDb->query($sql); }
        foreach (['bmg_admin', 'facilities_op', 'superadmin', 'employee'] as $role) {
            foreach ([1, 2] as $permission) {
                $this->batchDb->table('role_permissions')->insert(['role' => $role, 'permission_id' => $permission]);
            }
        }
    }
}
