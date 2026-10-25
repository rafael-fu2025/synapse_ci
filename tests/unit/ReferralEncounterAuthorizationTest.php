<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceCaseService;
use App\Services\Referrals\ReferralService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/** Private SQLite and real grants only; successful writes/locking require guarded MySQL tests. */
final class ReferralEncounterAuthorizationTest extends CIUnitTestCase
{
    private ?BaseConnection $fixture = null;
    private array $savedConnections = [];
    private ?int $savedTenant = null;
    private ReferralService $service;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', ENVIRONMENT);
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('Private SQLite fixtures require sqlite3.');
        }
        $this->savedTenant = CurrentTenant::id();
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->savedConnections = $instances->getValue();
        $this->fixture = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        $instances->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        CurrentTenant::set(1);
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, mis_id TEXT, first_name TEXT, last_name TEXT, email TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, is_teaching INTEGER',
            'permissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
        (new PermissionsAndRolesSeeder(config('Database'), $this->fixture))->run();
        $this->fixture->table('tenants')->insert(['id' => 1, 'is_active' => 1]);
        $this->fixture->table('people')->insert(['id' => 1, 'tenant_id' => 1, 'mis_id' => 'AUTH04',
            'first_name' => 'Synthetic', 'last_name' => 'Staff']);
        $this->fixture->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'person_id' => 1,
            'username' => 'auth04', 'role' => 'clinic_staff', 'status' => 'active']);
        $this->service = new ReferralService(caseService: new GuidanceCaseService(
            encryptionService: new EncryptionService(str_repeat('ab', 32)), db: $this->fixture));
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->savedConnections);
            CurrentTenant::reset();
            if ($this->savedTenant !== null) CurrentTenant::set($this->savedTenant);
        }
        parent::tearDown();
    }

    private function data(): array
    {
        return ['issuer_user_id' => 1, 'patient_person_id' => 2, 'source_module' => 'clinic',
            'target_module' => 'guidance', 'source_encounter_id' => 11,
            'reason_code' => 'academic_distress', 'reason_description' => 'Synthetic support request.',
            'request_key' => str_repeat('a', 64)];
    }

    private function identity(): array
    {
        return ['id' => 1, 'tenant_id' => 1];
    }

    private function revoke(string $permission, string $role = 'clinic_staff'): void
    {
        $row = $this->fixture->table('permissions')->where('code', $permission)->get()->getRowArray();
        self::assertNotNull($row);
        $this->fixture->table('role_permissions')->where('role', $role)->where('permission_id', $row['id'])->delete();
    }

    private function assertRejected(int $code, array $data, ?array $actor = null, ?string $message = null): void
    {
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = (string) $query; };
        Events::on('DBQuery', $listener);
        try {
            try {
                $this->service->createReferral($data, $actor);
                self::fail('Expected referral rejection.');
            } catch (RuntimeException $exception) {
                self::assertSame($code, $exception->getCode(), $exception->getMessage());
                if ($message !== null) self::assertSame($message, $exception->getMessage());
            }
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        self::assertNotEmpty($queries, 'Observe the real identity/grant queries.');
        foreach ($queries as $query) {
            foreach (['FOR UPDATE', 'SET TRANSACTION', 'referrals', 'clinic_encounters'] as $forbidden) {
                self::assertStringNotContainsString(strtolower($forbidden), strtolower($query));
            }
        }
    }

    private function assertReachesTransaction(array $data, ?array $actor = null): void
    {
        // An actual caller transaction is a portable boundary, not a simulated successful write.
        self::assertTrue($this->fixture->transBegin());
        try {
            $this->assertRejected(409, $data, $actor, 'Referral operations require an independent transaction.');
        } finally {
            $this->fixture->transRollback();
        }
    }

    public function testDirectServiceDeniesRevokedReadBeforeMutexOrRequestKeyLookup(): void
    {
        $this->revoke('clinic.encounters.read');
        $fresh = $this->service->authorize($this->identity(), 'referrals.create');
        self::assertContains('clinic.encounters.write', $fresh['permissions']);
        self::assertNotContains('clinic.encounters.read', $fresh['permissions']);
        foreach ([11, 999999] as $encounter) {
            foreach ([str_repeat('a', 64), str_repeat('b', 64), null] as $key) {
                $data = array_replace($this->data(), ['source_encounter_id' => $encounter]);
                if ($key === null) unset($data['request_key']);
                else $data['request_key'] = $key;
                $this->assertRejected(403, $data);
            }
        }
    }

    public function testForgedCachedSuperadminCannotReplaceCurrentReadGrant(): void
    {
        $cached = $this->service->authorize($this->identity(), 'clinic.encounters.read', 'clinic');
        $cached['role'] = 'superadmin';
        $cached['permissions'][] = '*';
        $this->revoke('clinic.encounters.read');
        $this->assertRejected(403, $this->data(), $cached);
    }

    public function testReadGrantIsRefreshedAfterAnEarlierSuccessfulGuard(): void
    {
        $fresh = $this->service->authorize($this->identity(), 'clinic.encounters.read', 'clinic');
        $this->revoke('clinic.encounters.read');
        $this->assertRejected(403, $this->data(), $fresh);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize($fresh, 'clinic.encounters.read', 'clinic');
    }

    public function testPlainReferralDoesNotAcquireEncounterReadDependency(): void
    {
        $this->revoke('clinic.encounters.read');
        foreach ([null, ''] as $empty) {
            $this->assertReachesTransaction(array_replace($this->data(), ['source_encounter_id' => $empty]));
        }
        $data = $this->data();
        unset($data['source_encounter_id']);
        $this->assertReachesTransaction($data);
        $this->fixture->table('users')->where('id', 1)->update(['role' => 'counsellor']);
        $this->assertReachesTransaction(array_replace($data, ['source_module' => 'guidance',
            'target_module' => 'clinic', 'reason_code' => 'medical_assessment']));
    }

    public function testReadAndCreateAreEnoughToAdvanceWithAnAttachment(): void
    {
        $this->revoke('clinic.encounters.create');
        $this->revoke('clinic.encounters.write');
        $this->assertReachesTransaction($this->data());
    }

    public function testRealSuperadminKeepsExplicitClinicWorkstationPolicy(): void
    {
        $this->fixture->table('users')->where('id', 1)->update(['role' => 'superadmin']);
        $this->fixture->table('role_permissions')->where('role', 'superadmin')->delete();
        $this->assertReachesTransaction($this->data() + ['workstation' => 'clinic']);
        $this->assertRejected(422, $this->data());
    }

    public function testWrongSourceStillFailsValidationEvenWithAnExplicitReadGrant(): void
    {
        $this->fixture->table('users')->where('id', 1)->update(['role' => 'counsellor']);
        $permission = $this->fixture->table('permissions')->where('code', 'clinic.encounters.read')->get()->getRowArray();
        $this->fixture->table('role_permissions')->insert(['role' => 'counsellor', 'permission_id' => $permission['id']]);
        $this->assertRejected(422, array_replace($this->data(), ['source_module' => 'guidance',
            'target_module' => 'clinic', 'reason_code' => 'medical_assessment']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize($this->identity(), 'clinic.encounters.read', 'clinic');
    }

    public function testExistingInputValidationStillPrecedesTheAttachmentGuard(): void
    {
        $this->revoke('clinic.encounters.read');
        foreach (['issuer_user_id', 'patient_person_id', 'source_encounter_id'] as $field) {
            foreach ([0, -1, '1junk', '1.0', true, [], '999999999999999999999999'] as $invalid) {
                // Invalid issuer IDs fail even before the first identity query.
                if ($field === 'issuer_user_id') {
                    try {
                        $this->service->createReferral(array_replace($this->data(), [$field => $invalid]));
                        self::fail('Expected positive ID validation.');
                    } catch (RuntimeException $exception) {
                        self::assertSame(422, $exception->getCode());
                    }
                } else {
                    $this->assertRejected(422, array_replace($this->data(), [$field => $invalid]));
                }
            }
        }
        foreach (['request_key' => 'invalid', 'target_module' => 'clinic', 'reason_code' => 'invalid'] as $field => $invalid) {
            $this->assertRejected(422, array_replace($this->data(), [$field => $invalid]));
        }
        $this->assertRejected(403, array_replace($this->data(), ['source_module' => 'guidance']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->createReferral($this->data(), ['id' => 1, 'tenant_id' => 2]);
    }

    public function testTransactionRechecksFreshAuthorityAfterMutexBeforeAnyRecordLookup(): void
    {
        // SQLite cannot run READ COMMITTED/FOR UPDATE: pin ordering, not concurrent MySQL behavior.
        $method = new ReflectionMethod(ReferralService::class, 'createReferral');
        $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1));
        $guard = '$this->authorize($fresh, \'clinic.encounters.read\', \'clinic\');';
        self::assertSame(2, substr_count($source, $guard));
        $upfront = strpos($source, $guard);
        $transaction = strpos($source, 'return $this->transaction(');
        $mutex = strpos($source, '$this->referralModel->lockTenant();');
        $actor = strpos($source, '$fresh = $this->actor(', $mutex);
        $recheck = strpos($source, $guard, $mutex);
        $lookup = strpos($source, 'SELECT * FROM');
        foreach ([$upfront, $transaction, $mutex, $actor, $recheck, $lookup] as $position) self::assertNotFalse($position);
        self::assertTrue($upfront < $transaction && $transaction < $mutex && $mutex < $actor
            && $actor < $recheck && $recheck < $lookup);
    }
}
