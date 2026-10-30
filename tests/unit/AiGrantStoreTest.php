<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AiConfigurationController;
use App\Database\Migrations\AddAiAnalysisScope;
use App\Database\Migrations\CreateAiInsights;
use App\Services\Ai\AiAccess;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AiGrantStore;
use App\Services\Ai\SettingsStore;
use App\Services\Audit\AuditHash;
use App\Services\Audit\AuditService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Response;
use PHPUnit\Framework\TestCase;
use Config\Database;
use Config\Services;
use Psr\Log\NullLogger;
use ReflectionProperty;

/** No dotenv, production settings, operational data, migrations runner, or provider requests. */
final class AiGrantStoreTest extends TestCase
{
    private BaseConnection $db;
    private AiGrantStore $store;
    private array $connections;
    private ReflectionProperty $instances;
    private ?int $previousTenant;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        if (!extension_loaded('sqlite3')) { $this->markTestSkipped('Disposable SQLite requires sqlite3.'); }
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'grant_test_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        require_once APPPATH . 'Database/Migrations/2026-10-28-000001_CreateAiInsights.php';
        require_once APPPATH . 'Database/Migrations/2026-10-29-000001_AddAiAnalysisScope.php';
        (new CreateAiInsights(Database::forge($this->db)))->up();
        (new AddAiAnalysisScope(Database::forge($this->db)))->up();
        $this->auditSchema();
        $this->previousTenant = (new ReflectionProperty(CurrentTenant::class, 'id'))->getValue();
        CurrentTenant::set(17);
        $this->store = new AiGrantStore($this->db);
        // Exercise the exact static runtime entry point against the same disposable connection.
        $this->instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $this->instances->getValue();
        $this->instances->setValue(null, ['tests' => $this->db]);
    }

    protected function tearDown(): void
    {
        if (isset($this->connections)) { $this->instances->setValue(null, $this->connections); }
        if (isset($this->db)) { $this->db->close(); }
        if (isset($this->store)) { CurrentTenant::set($this->previousTenant); }
        parent::tearDown();
    }

    private function auditSchema(): void
    {
        // Disposable equivalents of the current ledger schema; never run the MySQL-only hardening migration.
        $tenants = $this->db->protectIdentifiers($this->db->prefixTable('tenants'));
        $events = $this->db->protectIdentifiers($this->db->prefixTable('audit_events'));
        $this->db->query("CREATE TABLE {$tenants} (id INTEGER PRIMARY KEY)");
        $this->db->table('tenants')->insertBatch([['id' => 17], ['id' => 18]]);
        $this->db->query("CREATE TABLE {$events} (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL DEFAULT 1,
            event_type VARCHAR(64) NOT NULL, entity_type VARCHAR(64) NOT NULL, entity_id VARCHAR(64) NOT NULL,
            actor_user_id INTEGER NULL, actor_role VARCHAR(50) NULL, actor_ip VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
            action VARCHAR(32) NOT NULL, payload_json TEXT NULL, prev_hash CHAR(64) NOT NULL, curr_hash CHAR(64) NOT NULL,
            hash_version INTEGER NOT NULL DEFAULT 1, occurred_at DATETIME NOT NULL,
            FOREIGN KEY (tenant_id) REFERENCES {$tenants}(id) ON DELETE RESTRICT ON UPDATE RESTRICT
        )");
    }

    private function actor(string $role = 'superadmin', int $tenant = 17, array $permissions = ['rbac.manage']): array
    {
        return ['logged_in' => true, 'id' => 23, 'tenant_id' => $tenant, 'role' => $role, 'permissions' => $permissions];
    }

    private function failure(string $reason, callable $action): void
    {
        try { $action(); self::fail('Expected ' . $reason); }
        catch (AiFailure $error) { self::assertSame($reason, $error->reason); self::assertNull($error->getPrevious()); }
    }

    public function testMigrationSeedsNothingAndAllowlistUsesRealDepartmentRoles(): void
    {
        self::assertTrue($this->store->ready());
        self::assertSame(['ready' => true, 'grants' => []], $this->store->summary(17));
        self::assertSame(['guidance_admin', 'guidance_supervisor', 'counsellor', 'superadmin'], AiGrantStore::ROLES['guidance']);
        self::assertSame(['bmg_admin', 'facilities_op', 'superadmin'], AiGrantStore::ROLES['bmg']);
        $this->failure('access_denied', fn () => AiAccess::assertActor($this->actor(), 17, 'ai.insights.read'));
        self::assertFalse($this->db->tableExists('role_permissions'));
    }

    public function testGrantsAreIdempotentTenantRoleAndUnitScopedWithCreatorMetadata(): void
    {
        $this->store->grant($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read');
        $this->store->grant($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read');
        self::assertCount(1, $this->store->summary(17)['grants']);
        $row = $this->db->table('ai_department_access')->get()->getRowArray();
        self::assertSame(23, (int) $row['created_by']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $row['created_at']);
        $staff = $this->actor('clinic_staff', 17, []);
        self::assertContains('ai.insights.read', AiAccess::assertActor($staff, 17, 'ai.insights.read')['permissions']);
        foreach ([$this->actor('clinic_admin', 17, []), $this->actor('clinic_staff', 18, []), $this->actor('student', 17, [])] as $denied) {
            self::assertSame([], AiGrantStore::mergeActor($denied, 'clinic')['permissions']);
        }
        self::assertSame([], AiGrantStore::mergeActor($staff, 'guidance')['permissions']);
        CurrentTenant::set(18);
        $this->store->revoke($this->actor('superadmin', 18), 'clinic', 'clinic_staff', 'ai.insights.read');
        CurrentTenant::set(17);
        self::assertCount(1, $this->store->summary(17)['grants']);
        self::assertSame([], $this->store->summary(18)['grants']);
        self::assertSame(['valid' => true, 'verified_count' => 1, 'broken_id' => null], (new AuditService(null, $this->db))->verifyChain(18));
    }

    public function testReusedActorsRefreshRevocationsAndNeverCarryGrantsAcrossUnits(): void
    {
        $this->store->grant($this->actor(), 'clinic', 'superadmin', 'ai.insights.read');
        $merged = AiGrantStore::mergeActor($this->actor(), 'clinic');
        self::assertContains('ai.insights.read', $merged['permissions']);
        $this->failure('access_denied', fn () => AiAccess::assertActor($merged, 17, 'ai.insights.read', 'guidance'));
        $this->store->revoke($this->actor(), 'clinic', 'superadmin', 'ai.insights.read');
        $this->store->revoke($this->actor(), 'clinic', 'superadmin', 'ai.insights.read');
        $this->failure('access_denied', fn () => AiAccess::assertActor($merged, 17, 'ai.insights.read'));
        self::assertSame(['rbac.manage'], AiGrantStore::mergeActor($merged, 'clinic')['permissions']);
    }

    public function testMissingStorageFailsClosedButPreservesPreexistingExplicitPermissions(): void
    {
        $this->store->grant($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read');
        $scoped = AiGrantStore::mergeActor($this->actor('clinic_staff', 17, []), 'clinic');
        $legacy = AiGrantStore::mergeActor($this->actor('clinic_staff', 17, ['ai.insights.read', 'clinic.encounters.read']), 'clinic');
        Database::forge($this->db)->dropTable('ai_department_access');
        self::assertSame(['ready' => false, 'grants' => []], $this->store->summary(17));
        $this->failure('access_denied', fn () => AiAccess::assertActor($scoped, 17, 'ai.insights.read'));
        self::assertContains('ai.insights.read', AiAccess::assertActor($legacy, 17, 'ai.insights.read')['permissions']);
        $this->failure('store_unavailable', fn () => $this->store->grant($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read'));
    }

    public function testRejectsSourceAndConfigurationPermissionsAndCrossDepartmentRoles(): void
    {
        foreach ([['clinic', 'clinic_staff', 'clinic.encounters.read'], ['guidance', 'counsellor', 'guidance.cases.read_any'],
            ['bmg', 'facilities_op', 'facilities.read'], ['clinic', 'clinic_staff', 'ai.configuration.write'],
            ['guidance', 'clinic_staff', 'ai.insights.read'], ['clinic', 'student', 'ai.insights.read'],
            ['main', 'superadmin', 'ai.insights.read'], ['clinic', 'clinic_staff', '*']] as [$unit, $role, $permission]) {
            $this->failure('invalid_request', fn () => $this->store->grant($this->actor(), $unit, $role, $permission));
        }
        $this->failure('access_denied', fn () => $this->store->grant($this->actor('clinic_admin'), 'clinic', 'clinic_staff', 'ai.insights.read'));
        $this->failure('access_denied', fn () => $this->store->grant($this->actor('superadmin', 17, []), 'clinic', 'clinic_staff', 'ai.insights.read'));
        self::assertSame([], $this->store->summary(17)['grants']);
    }

    public function testEveryDepartmentRequiresReadAndIndependentSourcePermissionToGenerate(): void
    {
        foreach (['clinic' => ['clinic_staff', 'clinic.encounters.read'], 'guidance' => ['counsellor', 'guidance.cases.read_any'],
            'bmg' => ['facilities_op', 'facilities.read']] as $unit => [$role, $source]) {
            $this->store->grant($this->actor(), $unit, $role, 'ai.insights.generate');
            $actor = $this->actor($role, 17, [$source]);
            $this->failure('access_denied', fn () => AiAccess::assertActor($actor, 17, 'ai.insights.generate', $unit));
            $this->store->grant($this->actor(), $unit, $role, 'ai.insights.read');
            $this->failure('access_denied', fn () => AiAccess::assertActor($this->actor($role, 17, []), 17, 'ai.insights.generate', $unit));
            self::assertContains($source, AiAccess::assertActor($actor, 17, 'ai.insights.generate', $unit)['permissions']);
            foreach (['ai.insights.review', 'ai.insights.approve'] as $permission) {
                $this->failure('access_denied', fn () => AiAccess::assertActor($actor, 17, $permission, $unit));
                $this->store->grant($this->actor(), $unit, $role, $permission);
                self::assertContains($permission, AiAccess::assertActor($actor, 17, $permission, $unit)['permissions']);
            }
            $this->store->revoke($this->actor(), $unit, $role, 'ai.insights.read');
            $this->failure('access_denied', fn () => AiAccess::assertActor($actor, 17, 'ai.insights.approve', $unit));
        }
    }

    public function testUnexpectedStoredPermissionsCannotGrantSourceAuthority(): void
    {
        $this->db->table('ai_department_access')->insert(['tenant_id' => 17, 'unit' => 'guidance', 'role' => 'counsellor',
            'permission' => 'guidance.cases.read_any', 'created_by' => 23, 'created_at' => gmdate('Y-m-d H:i:s')]);
        self::assertSame([], AiGrantStore::mergeActor($this->actor('counsellor', 17, []), 'guidance')['permissions']);
        self::assertSame([], $this->store->summary(17)['grants']);
    }

    private function auditRows(): array
    {
        return $this->db->table('audit_events')->orderBy('id')->get()->getResultArray();
    }

    public function testGrantAndRevokeHaveDurableControlledTenantScopedAuditHistory(): void
    {
        $actor = $this->actor() + ['name' => 'private-name-sentinel', 'email' => 'private-email-sentinel', 'notes' => 'private-notes-sentinel'];
        $this->store->grant($actor, 'guidance', 'counsellor', 'ai.insights.read');
        $this->store->grant($actor, 'guidance', 'counsellor', 'ai.insights.read');
        $this->store->revoke($actor, 'guidance', 'counsellor', 'ai.insights.read');
        $this->store->revoke($actor, 'guidance', 'counsellor', 'ai.insights.read');
        $rows = $this->auditRows();
        self::assertCount(4, $rows);
        self::assertSame(['grant', 'grant', 'revoke', 'revoke'], array_column($rows, 'action'));
        foreach ($rows as $index => $row) {
            self::assertSame(17, (int) $row['tenant_id']);
            self::assertSame(23, (int) $row['actor_user_id']);
            self::assertSame('superadmin', $row['actor_role']);
            self::assertSame(2, (int) $row['hash_version']);
            self::assertSame('ai.access.' . $row['action'], $row['event_type']);
            self::assertSame('ai_department_access', $row['entity_type']);
            self::assertSame('guidance:counsellor:ai.insights.read', $row['entity_id']);
            self::assertSame(['unit' => 'guidance', 'role' => 'counsellor', 'permission' => 'ai.insights.read', 'changed' => $index % 2 === 0],
                json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('sentinel', json_encode($row, JSON_THROW_ON_ERROR));
        }
        self::assertSame(['valid' => true, 'verified_count' => 4, 'broken_id' => null], AuditHash::verify($rows, 17));
        self::assertTrue((new AuditService(null, $this->db))->verifyChain(17)['valid']);
        self::assertSame([], $this->store->summary(17)['grants']);
        self::assertSame(0, $this->db->transDepth);
    }

    public function testAuditAppendFailureRollsBackBothGrantAndRevokeAndAllowsRecovery(): void
    {
        $events = $this->db->protectIdentifiers($this->db->prefixTable('audit_events'));
        foreach (['grant', 'revoke'] as $operation) {
            $before = $this->auditRows();
            $this->db->query("CREATE TRIGGER reject_ai_audit BEFORE INSERT ON {$events} BEGIN SELECT RAISE(ABORT, 'synthetic-private-audit-error'); END");
            $this->failure('store_unavailable', fn () => $this->store->{$operation}($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read'));
            self::assertSame($before, $this->auditRows());
            self::assertCount($operation === 'grant' ? 0 : 1, $this->store->summary(17)['grants']);
            self::assertSame(0, $this->db->transDepth);
            self::assertTrue($this->db->transStatus());
            $this->db->query('DROP TRIGGER reject_ai_audit');
            $this->store->{$operation}($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read');
        }
        self::assertCount(2, $this->auditRows());
        self::assertTrue((new AuditService(null, $this->db))->verifyChain(17)['valid']);
    }

    public function testBusinessWriteFailureCannotCreateAnAuditOnlyChange(): void
    {
        $grants = $this->db->protectIdentifiers($this->db->prefixTable('ai_department_access'));
        $this->db->query("CREATE TRIGGER reject_ai_grant BEFORE INSERT ON {$grants} BEGIN SELECT RAISE(ABORT, 'synthetic-private-grant-error'); END");
        $this->failure('store_unavailable', fn () => $this->store->grant($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read'));
        self::assertSame([], $this->auditRows());
        self::assertSame([], $this->store->summary(17)['grants']);
        self::assertTrue($this->db->transStatus());
        self::assertSame(0, $this->db->transDepth);
    }

    public function testPostInsertAuditVerificationFailureRollsBackTheGrantAndEvent(): void
    {
        $events = $this->db->protectIdentifiers($this->db->prefixTable('audit_events'));
        $this->db->query("CREATE TRIGGER alter_ai_audit AFTER INSERT ON {$events} BEGIN UPDATE {$events} SET curr_hash = 'invalid' WHERE id = NEW.id; END");
        $this->failure('store_unavailable', fn () => $this->store->grant($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read'));
        self::assertSame([], $this->store->summary(17)['grants']);
        self::assertSame([], $this->auditRows());
        self::assertSame(0, $this->db->transDepth);
    }

    public function testMissingAuditStorageDisablesChangesWithoutRemovingExistingReadAuthority(): void
    {
        $this->store->grant($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read');
        Database::forge($this->db)->dropTable('audit_events');
        $summary = $this->store->summary(17);
        self::assertFalse($summary['ready']);
        self::assertCount(1, $summary['grants']);
        self::assertContains('ai.insights.read', AiGrantStore::mergeActor($this->actor('clinic_staff', 17, []), 'clinic')['permissions']);
        foreach (['grant', 'revoke'] as $operation) {
            $this->failure('store_unavailable', fn () => $this->store->{$operation}($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read'));
        }
        self::assertCount(1, $this->store->summary(17)['grants']);
    }

    public function testLegacyAuditSchemaCannotAcceptAccessChanges(): void
    {
        $events = $this->db->protectIdentifiers($this->db->prefixTable('audit_events'));
        $this->db->query("ALTER TABLE {$events} DROP COLUMN hash_version");
        unset($this->db->dataCache['field_names']['audit_events']);
        self::assertFalse($this->store->summary(17)['ready']);
        $this->failure('store_unavailable', fn () => $this->store->grant($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read'));
        self::assertSame([], $this->auditRows());
        self::assertSame([], $this->store->summary(17)['grants']);
    }

    public function testMismatchedAuditTenantAndMissingTenantFailBeforeChangingAccess(): void
    {
        $this->failure('access_denied', fn () => $this->store->grant($this->actor('superadmin', 18), 'clinic', 'clinic_staff', 'ai.insights.read'));
        self::assertSame([], $this->auditRows());
        $this->db->table('tenants')->where('id', 17)->delete();
        $this->failure('store_unavailable', fn () => $this->store->grant($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read'));
        self::assertSame([], $this->auditRows());
        self::assertSame([], $this->store->summary(17)['grants']);
    }

    public function testAccessChangeCannotCommitOrRollbackACallerTransaction(): void
    {
        self::assertTrue($this->db->transBegin());
        try {
            self::assertTrue($this->db->table('tenants')->insert(['id' => 19]));
            $this->failure('independent_transaction_required', fn () => $this->store->grant($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read'));
            self::assertSame(1, $this->db->transDepth);
            self::assertTrue($this->db->transStatus());
            self::assertSame(1, $this->db->table('tenants')->where('id', 19)->countAllResults());
            self::assertSame([], $this->auditRows());
        } finally { $this->db->transRollback(); }
        self::assertSame(0, $this->db->table('tenants')->where('id', 19)->countAllResults());
    }

    public function testIncompleteSchemaCannotSupplyGrants(): void
    {
        $this->store->grant($this->actor(), 'clinic', 'clinic_staff', 'ai.insights.read');
        foreach ($this->db->getIndexData('ai_department_access') as $index) {
            if ($index->fields === ['tenant_id', 'unit', 'role', 'permission']) {
                self::assertTrue(Database::forge($this->db)->dropKey('ai_department_access', $index->name, false));
            }
        }
        self::assertFalse($this->store->ready());
        self::assertSame([], AiGrantStore::mergeActor($this->actor('clinic_staff', 17, []), 'clinic')['permissions']);
    }

    public function testSidebarRefreshesEveryDepartmentGrantAndHandlesMissingSchema(): void
    {
        $identity = $this->actor();
        $session = $this->createMock(\CodeIgniter\Session\Session::class);
        $session->method('get')->with('user')->willReturnCallback(static function () use (&$identity): array { return $identity; });
        Services::injectMock('session', $session);
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('server', ['HTTP_HOST' => 'localhost']);
        Services::injectMock('request', $request);
        $render = static function (): string {
            ob_start();
            try { require APPPATH . 'Views/partials/sidebar.php'; return ob_get_contents(); }
            finally { ob_end_clean(); }
        };
        $paths = ['clinic' => 'clinic/insights', 'guidance' => 'guidance/insights', 'bmg' => 'facilities/insights'];
        $html = $render();
        foreach ($paths as $path) { self::assertStringNotContainsString('/' . $path, $html); }
        foreach ($paths as $unit => $path) { $this->store->grant($this->actor(), $unit, 'superadmin', 'ai.insights.read'); }
        $html = $render();
        foreach ($paths as $path) { self::assertStringContainsString('/' . $path, $html); }
        $this->store->revoke($this->actor(), 'clinic', 'superadmin', 'ai.insights.read');
        $html = $render();
        self::assertStringNotContainsString('/clinic/insights', $html);
        self::assertStringContainsString('/guidance/insights', $html);
        self::assertStringContainsString('/facilities/insights', $html);
        Database::forge($this->db)->dropTable('ai_department_access');
        $html = $render();
        foreach ($paths as $path) { self::assertStringNotContainsString('/' . $path, $html); }
        self::assertStringContainsString('/admin/ai', $html);
        $identity['permissions'][] = 'ai.insights.read';
        $html = $render();
        foreach ($paths as $path) { self::assertStringContainsString('/' . $path, $html); }
        Services::resetSingle('session');
        Services::resetSingle('request');
    }

    private function controller(array $post, string $method = 'POST', ?array $identity = null): AiGrantHttpFixtureController
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $request->setGlobal('get', []);
        $request->setGlobal('post', [csrf_token() => csrf_hash()] + $post);
        Services::injectMock('request', $request);
        $response = new Response(config('App'));
        Services::injectMock('response', $response);
        $controller = new AiGrantHttpFixtureController();
        $controller->store = $this->store;
        $controller->identity = $identity ?? $this->actor();
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    public function testAccessHttpActionGrantsAndRevokesWithoutSettingsOrProviderAccess(): void
    {
        $input = ['operation' => 'grant', 'unit' => 'guidance', 'role' => 'counsellor', 'permission' => 'ai.insights.read'];
        $response = $this->controller($input)->access();
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $data = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $data['access']['grants']);
        self::assertArrayNotHasKey('settings', $data);
        self::assertArrayHasKey('csrf', $data);
        self::assertSame(200, $this->controller(array_replace($input, ['operation' => 'revoke']))->access()->getStatusCode());
        self::assertSame([], $this->store->summary(17)['grants']);
    }

    public function testAccessHttpReturnsControlledAuditFailureAndKeepsAccessUnchanged(): void
    {
        $input = ['operation' => 'grant', 'unit' => 'clinic', 'role' => 'clinic_staff', 'permission' => 'ai.insights.read'];
        $events = $this->db->protectIdentifiers($this->db->prefixTable('audit_events'));
        $this->db->query("CREATE TRIGGER reject_http_audit BEFORE INSERT ON {$events} BEGIN SELECT RAISE(ABORT, 'synthetic-private-audit-detail'); END");
        $response = $this->controller($input)->access();
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('synthetic-private-audit-detail', $response->getBody());
        $data = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('store_unavailable', $data['reason']);
        self::assertArrayHasKey('csrf', $data);
        self::assertSame([], $this->store->summary(17)['grants']);
        self::assertSame([], $this->auditRows());
    }

    public function testAccessHttpRejectsUntrustedPayloadsAndNonAdministrators(): void
    {
        $input = ['operation' => 'grant', 'unit' => 'clinic', 'role' => 'clinic_staff', 'permission' => 'ai.insights.read'];
        foreach ([$input + ['tenant_id' => '18'], $input + ['api_key' => 'untrusted'],
            array_replace($input, ['operation' => 'replace']), array_replace($input, ['unit' => ['clinic']]),
            array_replace($input, ['permission' => 'clinic.encounters.read'])] as $bad) {
            self::assertGreaterThanOrEqual(400, $this->controller($bad)->access()->getStatusCode());
        }
        self::assertSame(405, $this->controller($input, 'GET')->access()->getStatusCode());
        self::assertSame(403, $this->controller($input, 'POST', $this->actor('clinic_admin'))->access()->getStatusCode());
        self::assertSame([], $this->store->summary(17)['grants']);
    }
}

final class AiGrantHttpFixtureController extends AiConfigurationController
{
    public AiGrantStore $store;
    public array $identity;
    protected function actor(string $permission): array { return AiAccess::assertActor($this->identity, 17, $permission); }
    protected function grants(): AiGrantStore { return $this->store; }
    protected function settings(): SettingsStore { throw new \LogicException('Access action must not read private settings.'); }
}
