<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FacilitiesController;
use App\Database\Migrations\AddBmgBatchNotes;
use App\Services\AuthenticatedUser;
use App\Services\Facilities\BmgService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Private SQLite only; never migrates or connects to an operational database. */
final class FacilitiesAuditTest extends CIUnitTestCase
{
    private BaseConnection $auditDb;
    private array $connections;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) { $this->markTestSkipped('Private SQLite fixtures require sqlite3.'); }
        $this->auditDb = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $instances->getValue();
        $instances->setValue(null, ['tests' => $this->auditDb]);
        foreach ([
            'CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, role TEXT, username TEXT, email TEXT, status TEXT, deleted_at TEXT, auth_version INTEGER DEFAULT 1)',
            'CREATE TABLE people (id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, deleted_at TEXT)',
            'CREATE TABLE employee_profiles (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, is_teaching INTEGER)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY, code TEXT)',
            'CREATE TABLE role_permissions (role TEXT, permission_id INTEGER)',
            'CREATE TABLE bmg_units (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_code TEXT, name TEXT, status TEXT, drum_capacity_kg TEXT, deleted_at TEXT)',
            'CREATE TABLE bmg_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, unit_id INTEGER, batch_code TEXT, status TEXT, total_input_weight_kg TEXT, output_weight_kg TEXT, quality_grade TEXT, started_at TEXT, completed_at TEXT, release_notes TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER DEFAULT 1, occurred_at TEXT)',
            'INSERT INTO tenants (id, is_active) VALUES (1, 1)',
            "INSERT INTO people (id, tenant_id, first_name, last_name) VALUES (7, 1, 'Audit', 'Operator')",
            "INSERT INTO users (id, tenant_id, person_id, role, username, status) VALUES (7, 1, 7, 'facilities_op', 'audit-op', 'active')",
            "INSERT INTO permissions (id, code) VALUES (1, 'facilities.read'), (2, 'facilities.manage'), (3, 'facilities.configure'), (4, 'facilities.hardware.manage')",
            "INSERT INTO role_permissions (role, permission_id) VALUES ('facilities_op', 1), ('facilities_op', 2)",
            "INSERT INTO bmg_units (id, tenant_id, unit_code, name, status, drum_capacity_kg) VALUES (1, 1, 'audit', 'Audit drum', 'active', '500.00')",
        ] as $sql) { $this->auditDb->query($sql); }
        session()->set('_auth', ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]);
        session()->set(['tenant_id' => 1, 'user' => ['id' => 7, 'tenant_id' => 1, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['facilities.configure', 'facilities.hardware.manage']]]);
        service('request')->removeHeader('X-Device-Token');
        service('request')->removeHeader('Authorization');
        require_once APPPATH . 'Database/Migrations/2026-10-19-000007_AddBmgBatchNotes.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->auditDb)) {
            AuthenticatedUser::clear();
            $this->auditDb->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        }
        parent::tearDown();
    }

    public function testNotesMigrationRefusesUnsupportedSqliteInsteadOfPretendingItIsSafe(): void
    {
        try {
            $this->migrateNotes();
            $this->fail('Production migration must require verified MySQL/InnoDB metadata.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('MySQL/MariaDB with InnoDB', $error->getMessage());
        }
        $this->assertFalse($this->auditDb->fieldExists('notes', 'bmg_batches'));
    }

    public function testAddingOptionalColumnToFixturePreservesRowsWithoutAuditBackfill(): void
    {
        $this->auditDb->table('bmg_batches')->insert(['id' => 90, 'tenant_id' => 1, 'batch_code' => 'old',
            'status' => 'released', 'release_notes' => 'Original completion']);
        $this->auditDb->table('audit_events')->insert(['event_type' => 'bmg.batch.created', 'entity_id' => '90',
            'payload_json' => '{"notes":"Audit-only old note"}']);
        $before = $this->auditDb->table('bmg_batches')->get()->getRowArray();
        $audit = $this->auditDb->table('audit_events')->get()->getResultArray();
        $this->addNotesFixtureColumn();
        $after = $this->auditDb->table('bmg_batches')->get()->getRowArray();
        $this->assertNull($after['notes']);
        unset($after['notes']);
        $this->assertSame($before, $after);
        $this->assertSame($audit, $this->auditDb->table('audit_events')->get()->getResultArray());
    }

    public function testCreationNotesPersistIndependentlyThroughCompletionAndCancellation(): void
    {
        $this->addNotesFixtureColumn();
        $service = new BmgService($this->auditDb);
        foreach (['released', 'voided'] as $status) {
            $batch = $service->createBatch(1, '10.00', "  Initial <load>\nSecond line  ");
            $this->assertSame("Initial <load>\nSecond line", $batch['notes']);
            $this->assertSame($batch['notes'], $service->batchOverview(1)['activeBatch']['notes']);
            $finished = $status === 'released' ? $service->releaseBatch((int) $batch['id'], null, 'unclassified', 'Completion')
                : $service->cancelBatch((int) $batch['id'], 'Cancellation');
            $this->assertSame($batch['notes'], $finished['notes']);
            $this->assertNotSame($finished['notes'], $finished['release_notes']);
            $this->assertSame($batch['notes'], $service->batchOverview(1)['pastBatches'][0]['notes']);
        }
    }

    public function testNotesBoundIsEnforcedBeforeAnyWrite(): void
    {
        $this->addNotesFixtureColumn();
        $service = new BmgService($this->auditDb);
        try {
            $service->createBatch(1, '10', str_repeat('界', 2001));
            $this->fail('Oversized notes must be rejected.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('2000', $error->getMessage());
        }
        $this->assertSame(0, $this->auditDb->table('bmg_batches')->countAllResults());
        $this->assertSame(0, $this->auditDb->table('audit_events')->countAllResults());
        $batch = $service->createBatch(1, '10', str_repeat('界', 2000));
        $this->assertSame(2000, mb_strlen($batch['notes']));
        $service->cancelBatch((int) $batch['id'], 'Next');
        $this->assertNull($service->createBatch(1, '10', '  ')['notes']);
    }

    public function testUsbAccessUsesFreshReadManageConfigureGrantsNotCachedRole(): void
    {
        $controller = (new ReflectionClass(FacilitiesController::class))->newInstanceWithoutConstructor();
        $access = new ReflectionMethod(FacilitiesController::class, 'access');
        $this->assertFalse($access->invoke($controller)['canUsb']);
        $this->auditDb->table('role_permissions')->insert(['role' => 'facilities_op', 'permission_id' => 3]);
        $this->assertTrue($access->invoke($controller)['canUsb']);
        foreach ([1, 2, 3] as $permission) {
            $this->auditDb->table('role_permissions')->where('permission_id', $permission)->delete();
            $this->assertFalse($access->invoke($controller)['canUsb']);
            $this->auditDb->table('role_permissions')->insert(['role' => 'facilities_op', 'permission_id' => $permission]);
        }
    }

    public function testScheduleEditorsRequireHardwareAuthorityAndBothGateSignals(): void
    {
        // Non-configure users cannot see the schedule editor; configuration admins always can (zynapse model).
        $html = $this->render('unit_detail', ['canConfigure' => false]);
        $xpath = $this->xpath($html);
        $this->assertCount(0, $xpath->query('//*[@id="scheduleRotations" or @id="schedulePwm" or @id="scheduleEnabled"]'));
        $this->assertCount(0, $xpath->query('//*[@data-bmg-add-time or @data-bmg-remove-time]'));
        $this->assertCount(0, $xpath->query('//input[@name="times[]" and @type!="hidden"]'));
        $this->assertCount(1, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " bmg-workspace ") and @data-bmg-base-url]'));

        foreach ([[], ['canHardware' => false], ['hardwareEnabled' => false], ['hardware_enabled' => false]] as $allowed) {
            $xpath = $this->xpath($this->render('unit_detail', $allowed));
            $this->assertCount(3, $xpath->query('//*[@id="scheduleRotations" or @id="schedulePwm" or @id="scheduleEnabled"]'));
        }
    }

    public function testDisableFormRetainsTimesAndDurationButDoesNotSubmitPwm(): void
    {
        // Configuration admins retain full schedule controls even when hardwareEnabled is false.
        $xpath = $this->xpath($this->render('unit_detail', ['hardwareEnabled' => false]));
        $form = '//form[@data-bmg-schedule]';
        $this->assertSame('3', $xpath->evaluate('string(' . $form . '/input[@name="expected_version"]/@value)'));
        $this->assertSame('08:00', $xpath->evaluate('string(' . $form . '//input[@name="times[]"]/@value)'));
        $this->assertSame('7', $xpath->evaluate('string(' . $form . '//input[@name="rotations"]/@value)'));
        // Empty schedule pre-seeds 3 dynamic 3x7 draft time slots so operators immediately see time pickers.
        $empty = $this->schedule();
        $empty['times'] = [];
        $empty['enabled'] = false;
        $emptyXpath = $this->xpath($this->render('unit_detail', ['hardwareEnabled' => false, 'schedule' => $empty]));
        $this->assertCount(3, $emptyXpath->query('//form[@data-bmg-schedule]//input[@name="times[]"]'));
    }

    public function testReadOnlyDisablePayloadIsAcceptedByServiceWithoutHardwareAuthority(): void
    {
        $this->auditDb->query('CREATE TABLE bmg_devices (id INTEGER PRIMARY KEY)');
        require_once APPPATH . 'Database/Migrations/2026-10-17-000005_CreateBmgRotationOperations.php';
        (new \App\Database\Migrations\CreateBmgRotationOperations(Database::forge($this->auditDb)))->up();
        require_once APPPATH . 'Database/Migrations/2026-11-24-000001_ReplaceBmgDurationWithRotations.php';
        (new \App\Database\Migrations\ReplaceBmgDurationWithRotations(Database::forge($this->auditDb)))->up();
        $this->auditDb->table('role_permissions')->insert(['role' => 'facilities_op', 'permission_id' => 3]);
        $this->auditDb->table('bmg_rotation_schedules')->insert([
            'tenant_id' => 1, 'unit_id' => 1, 'enabled' => 1, 'version' => 3,
            'times_json' => '["08:00"]', 'rotations' => 7, 'pwm' => 120, 'updated_by_user_id' => 7,
            'created_at' => '2026-10-19 00:00:00', 'updated_at' => '2026-10-19 00:00:00',
        ]);
        $payload = [
            'enabled' => '0', 'expected_version' => '3',
            'times' => ['08:00', '12:00', '16:00'], 'rotations' => '7',
        ];
        $rotation = new \App\Services\Facilities\BmgRotationService($this->auditDb);
        $saved = $rotation->saveSchedule(1, $payload);
        $this->assertFalse($saved['enabled']);
        $this->assertSame(['08:00', '12:00', '16:00'], $saved['times']);
        $this->assertSame(7, $saved['rotations']);
        $this->assertNull($saved['pwm']);
        $this->assertSame(4, $saved['version']);
        try {
            $rotation->saveSchedule(1, $payload);
            $this->fail('Stale disable action must not replace newer intent.');
        } catch (\RuntimeException $error) { $this->assertSame(409, $error->getCode()); }
    }

    public function testRegionalOwnersAreStableAndResponseDescriptorsAreAllowlisted(): void
    {
        $list = $this->xpath($this->render('index', [
            'archivedView' => false,
            'stats' => ['total' => 1, 'active' => 1, 'maintenance' => 0, 'bound' => 1],
            'pagination' => ['page' => 1, 'pages' => 1, 'per_page' => 25, 'total' => 1, 'filtered' => 1, 'from' => 1, 'to' => 1],
        ]));
        $this->assertCount(1, $list->query('//*[@data-refresh-owner="facilities.list"]'));
        $this->assertCount(1, $list->query('//*[@data-refresh-owner="facilities.list"]//*[@data-refresh-region="stats"]'));
        $this->assertCount(1, $list->query('//*[@data-refresh-owner="facilities.list"]//*[@data-refresh-region="units"]'));

        $detail = $this->xpath($this->render('unit_detail'));
        $this->assertCount(1, $detail->query('//*[@data-refresh-owner="facilities.unit.1"]'));
        $this->assertCount(1, $detail->query('//*[@data-refresh-owner="facilities.unit.1"]/*[@data-refresh-region="unit"]'));

        $controller = (new ReflectionClass(FacilitiesController::class))->newInstanceWithoutConstructor();
        $request = service('request');
        $controller->initController($request, service('response'), service('logger'));
        $previousEnabled = config(\Config\RefreshRegions::class)->enabled;
        $previousOwners = config(\Config\RefreshRegions::class)->owners;
        try {
            config(\Config\RefreshRegions::class)->enabled = true;
            config(\Config\RefreshRegions::class)->owners = ['facilities.list', 'facilities.unit.1'];
            $refresh = new ReflectionMethod(FacilitiesController::class, 'successRefresh');
            $request->setHeader('X-Synapse-Refresh-Owner', 'facilities.list');
            $this->assertSame([
                'mode' => 'regions', 'owner' => 'facilities.list', 'regions' => ['stats', 'units'],
            ], $refresh->invoke($controller, 1, 'list', site_url('/facilities')));
            $request->removeHeader('X-Synapse-Refresh-Owner');
            $this->assertSame([
                'mode' => 'navigate', 'url' => site_url('/facilities/unit/1'), 'history' => 'push',
            ], $refresh->invoke($controller, 1, 'list', site_url('/facilities/unit/1')));
            $this->assertSame([
                'mode' => 'regions', 'owner' => 'facilities.unit.1', 'regions' => ['unit'],
                'url' => site_url('/facilities/unit/1'),
            ], $refresh->invoke($controller, 1, 'unit', site_url('/facilities/unit/1')));
            $this->assertNull($refresh->invoke($controller, 2, 'unit', site_url('/facilities/unit/2')));
            $this->assertNull($refresh->invoke($controller, 0, 'none', site_url('/facilities')));
        } finally {
            config(\Config\RefreshRegions::class)->enabled = $previousEnabled;
            config(\Config\RefreshRegions::class)->owners = $previousOwners;
        }
    }

    public function testUsbLinksAreHiddenAndCreationAndCompletionNotesBothEscaped(): void
    {
        foreach (['dashboard', 'unit_detail'] as $view) {
            $this->assertCount(0, $this->xpath($this->render($view, ['canUsb' => false]))->query('//a[contains(@href, "/usb")]'));
            $this->assertCount(1, $this->xpath($this->render($view))->query('//a[contains(@href, "/usb")]'));
        }
        $batch = ['id' => 1, 'batch_code' => 'Test', 'status' => 'released', 'started_at' => '2026-10-19',
            'total_input_weight_kg' => '10.00', 'notes' => '<script>creation</script>', 'release_notes' => '<b>completion</b>'];
        $html = $this->render('unit_detail', ['activeBatch' => $batch, 'pastBatches' => [$batch]]);
        $this->assertStringContainsString('&lt;script&gt;creation&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;completion&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<script>creation', $html);
    }

    public function testDashboardAuthorizesBeforeCacheInvalidatesOnWriteAndRevokeRedirectsToUnit(): void
    {
        $this->addNotesFixtureColumn();
        foreach ([
            'CREATE TABLE bmg_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, unit_id INTEGER, chip_mac TEXT, device_token_hash TEXT, status TEXT, revoked_at TEXT, rotation_bound_at TEXT, reported_firmware_version TEXT, rtc_valid INTEGER, applied_schedule_version INTEGER, last_seen_at TEXT, firmware_reported_at TEXT, rtc_reported_at TEXT, schedule_reported_at TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE bmg_rotation_schedules (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, unit_id INTEGER, enabled INTEGER, version INTEGER, times_json TEXT, rotations INTEGER, pwm INTEGER, updated_by_user_id INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE bmg_rotation_commands (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, unit_id INTEGER, device_id INTEGER, command TEXT, rotations INTEGER, pwm INTEGER, status TEXT, ack_status TEXT, ack_message TEXT, created_by_user_id INTEGER, created_at TEXT, expires_at TEXT, acknowledged_at TEXT)',
            'CREATE TABLE bmg_rotation_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, unit_id INTEGER, device_id INTEGER, run_uid TEXT, motor_state TEXT, rotations INTEGER, occurred_at TEXT, created_at TEXT)',
        ] as $sql) {
            $this->auditDb->query($sql);
        }
        $this->auditDb->table('role_permissions')->insert(['role' => 'facilities_op', 'permission_id' => 3]);
        $this->auditDb->table('bmg_devices')->insert([
            'id' => 5, 'tenant_id' => 1, 'unit_id' => 1, 'chip_mac' => 'aa:bb:cc:dd:ee:01',
            'device_token_hash' => hash('sha256', 'secret-token'), 'status' => 'active',
            'rotation_bound_at' => '2026-10-17 08:00:00',
            'created_at' => '2026-10-17 08:00:00', 'updated_at' => '2026-10-17 08:00:00',
        ]);

        $renderer = new class(config('View'), APPPATH . 'Views/', service('locator'), false) extends \CodeIgniter\View\View {
            public array $captured = [];
            public function setData(array $data = [], ?string $context = null): \CodeIgniter\View\RendererInterface
            {
                $this->captured = $data;
                return parent::setData($data, $context);
            }
            public function render(string $view, ?array $options = null, ?bool $saveData = null): string
            {
                return $view;
            }
        };
        \Config\Services::injectMock('renderer', $renderer);

        $request = service('request');
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        $controller = new FacilitiesController();
        $controller->initController($request, service('response'), service('logger'));

        \App\Services\CurrentTenant::set(1);
        \App\Services\DashboardCache::enable(true);
        \App\Services\DashboardCache::forget('facilities.dashboard');
        try {
            $this->assertSame('facilities/dashboard', $controller->dashboard());
            $this->assertSame(0, $renderer->captured['totalBatches']);
            $this->assertSame(1, $renderer->captured['boundDevices']);
            $this->assertNotNull(cache()->get(\App\Services\DashboardCache::key('facilities.dashboard')));

            $request->setHeader('X-Device-Token', 'secret-token');
            $deniedToken = $controller->dashboard();
            $this->assertSame(403, $deniedToken->getStatusCode());
            $request->removeHeader('X-Device-Token');

            $request->setHeader('Authorization', 'Bearer token');
            $deniedBearer = $controller->dashboard();
            $this->assertSame(403, $deniedBearer->getStatusCode());
            $request->removeHeader('Authorization');

            $this->auditDb->table('users')->where('id', 7)->update(['role' => 'superadmin']);
            $deniedRole = $controller->dashboard();
            $this->assertSame(403, $deniedRole->getStatusCode());

            $this->auditDb->table('users')->where('id', 7)->update(['role' => 'facilities_op']);
            $request->setGlobal('post', ['input_weight_kg' => '25.00']);
            $started = json_decode($controller->startBatch(1)->getBody(), true);
            $this->assertTrue($started['success']);
            $this->assertNull(cache()->get(\App\Services\DashboardCache::key('facilities.dashboard')));

            $this->assertSame('facilities/dashboard', $controller->dashboard());
            $this->assertSame(1, $renderer->captured['totalBatches']);

            $request->setGlobal('post', ['expected_version' => '0']);
            $revoked = json_decode($controller->revokeDevice(5)->getBody(), true);
            $this->assertTrue($revoked['success']);
            $this->assertSame(site_url('/facilities/unit/1'), $revoked['redirect']);
            $this->assertNull(cache()->get(\App\Services\DashboardCache::key('facilities.dashboard')));

            $this->assertSame('facilities/dashboard', $controller->dashboard());
            $this->assertSame(0, $renderer->captured['boundDevices']);
        } finally {
            \App\Services\DashboardCache::forget('facilities.dashboard');
            \App\Services\DashboardCache::enable(null);
            \App\Services\CurrentTenant::reset();
            \Config\Services::resetSingle('renderer');
            $request->removeHeader('X-Device-Token');
            $request->removeHeader('Authorization');
        }
    }

    public function testDashboardWordingAndUnitDetailBatchTimestampsAreAccurate(): void
    {
        $offlineUnit = ['id' => 2, 'unit_code' => 'off-2', 'name' => 'Offline drum', 'status' => 'offline', 'drum_capacity_kg' => '300.00'];
        $html = $this->render('dashboard', [
            'totalUnits' => 2, 'activeUnits' => 1, 'maintenanceUnits' => 0, 'boundDevices' => 1,
            'totalTreatedKg' => 42.5,
            'units' => [
                ['unit' => ['id' => 1, 'unit_code' => 'audit', 'name' => 'Audit drum', 'status' => 'active', 'drum_capacity_kg' => '500.00'],
                    'schedule' => $this->schedule(), 'device' => ['id' => 1, 'chip_mac' => 'aa:bb:cc:dd:ee:01']],
                ['unit' => $offlineUnit, 'schedule' => ['enabled' => false, 'times' => []], 'device' => null],
            ],
        ]);
        foreach ([
            'Facilities · BMG',
            \App\Services\Scheduling\SchedulingClock::now()->format('D, M j, Y'),
            'Released batch input',
            'Input weight, not measured output',
            'Not a connectivity measurement',
            'Saved enabled (1 times)',
            'Saved disabled',
            'Active',
            'Offline',
            'USB commissioning',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        foreach (['Bioreactor Mechanized Gearing', 'Organic Waste Treated', 'Composted output', 'WebUSB', 'Active (1 runs)', 'Idle / Unconfigured'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }

        $detailHtml = $this->render('unit_detail', [
            'activeBatch' => ['id' => 1, 'batch_code' => 'BATCH-UTC', 'status' => 'processing',
                'started_at' => '2026-10-17 07:00:00', 'total_input_weight_kg' => '10.00', 'notes' => 'Morning'],
            'pastBatches' => [
                ['id' => 2, 'batch_code' => 'BATCH-PAST', 'status' => 'released', 'started_at' => '2026-10-17 07:00:00',
                    'completed_at' => '2026-10-17 09:00:00', 'total_input_weight_kg' => '10.00', 'output_weight_kg' => '8.00'],
                ['id' => 3, 'batch_code' => 'BATCH-NONSTD', 'status' => 'voided', 'started_at' => '2026-10-19',
                    'completed_at' => null, 'total_input_weight_kg' => '10.00'],
            ],
        ]);
        $this->assertStringContainsString('Started (Asia/Manila)', $detailHtml);
        $this->assertStringContainsString('Completed (Asia/Manila)', $detailHtml);
        $this->assertStringContainsString('2026-10-17 15:00:00', $detailHtml);
        $this->assertStringContainsString('2026-10-17 17:00:00', $detailHtml);
        $this->assertStringContainsString('2026-10-19', $detailHtml);
        $this->assertStringNotContainsString('2026-10-17 07:00:00', $detailHtml);
        $this->assertStringNotContainsString('2026-10-17 09:00:00', $detailHtml);

        $maintXpath = $this->xpath($this->render('unit_detail', [
            'unit' => ['id' => 1, 'unit_code' => 'audit', 'name' => 'Audit drum', 'status' => 'maintenance', 'drum_capacity_kg' => '500.00'],
        ]));
        $this->assertSame(1, $maintXpath->query('//button[contains(., "Request run now") and @disabled]')->length);
        $this->assertSame(0, $maintXpath->query('//button[contains(., "Request web stop") and @disabled]')->length);

        $opHwXpath = $this->xpath($this->render('unit_detail', ['canConfigure' => false, 'canManage' => true, 'canHardware' => true]));
        $this->assertSame(0, $opHwXpath->query('//button[contains(., "Request run now") and @disabled]')->length);
        $this->assertSame(0, $opHwXpath->query('//button[contains(., "Request web stop") and @disabled]')->length);

        $cfgOnlyXpath = $this->xpath($this->render('unit_detail', ['canConfigure' => true, 'canManage' => false, 'canHardware' => true]));
        $this->assertSame(1, $cfgOnlyXpath->query('//button[contains(., "Request run now") and @disabled]')->length);
        $this->assertSame(1, $cfgOnlyXpath->query('//button[contains(., "Request web stop") and @disabled]')->length);
    }

    private function migrateNotes(): void
    {
        (new AddBmgBatchNotes(Database::forge($this->auditDb)))->up();
    }

    private function addNotesFixtureColumn(): void
    {
        $this->auditDb->query('ALTER TABLE bmg_batches ADD COLUMN notes TEXT NULL');
    }

    private function schedule(): array
    {
        return ['enabled' => true, 'version' => 3, 'times' => ['08:00'], 'rotations' => 7, 'pwm' => 120];
    }

    private function render(string $view, array $overrides = []): string
    {
        $unit = ['id' => 1, 'unit_code' => 'audit', 'name' => 'Audit drum', 'status' => 'active', 'drum_capacity_kg' => '500.00'];
        $schedule = $this->schedule();
        $device = ['id' => 1, 'chip_mac' => 'aa:bb:cc:dd:ee:01'];
        $data = array_replace(['unit' => $unit, 'schedule' => $schedule, 'device' => $device,
            'canManage' => true, 'canConfigure' => true, 'canUsb' => true, 'canHardware' => true,
            'hardwareEnabled' => true, 'hardware_enabled' => true, 'commands' => [], 'runs' => [],
            'activeBatch' => null, 'pastBatches' => [], 'units' => [compact('unit', 'schedule', 'device')],
            'user' => [], 'totalUnits' => 1, 'activeUnits' => 1, 'maintenanceUnits' => 0, 'boundDevices' => 1,
            'totalBatches' => 0, 'activeBatches' => 0, 'totalTreatedKg' => 0, 'chartSeries' => [], 'chartDays' => []], $overrides);
        // Exercise the real view without shared layouts, whose navigation loads unrelated services.
        $renderer = new class {
            public function extend(string $layout): string { return ''; }
            public function section(string $name): string { return ''; }
            public function endSection(): string { return ''; }
            public function render(string $path, array $data): string
            {
                extract($data);
                ob_start();
                try { include $path; return ob_get_contents(); }
                finally { ob_end_clean(); }
            }
        };
        return $renderer->render(APPPATH . 'Views/facilities/' . $view . '.php', $data);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $document->loadHTML($html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new DOMXPath($document);
    }
}
