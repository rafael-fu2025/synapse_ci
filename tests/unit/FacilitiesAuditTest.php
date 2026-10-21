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
        foreach ([
            ['canConfigure' => false], ['canHardware' => false], ['hardwareEnabled' => false], ['hardware_enabled' => false],
        ] as $denied) {
            $html = $this->render('unit_detail', $denied);
            $xpath = $this->xpath($html);
            $this->assertCount(0, $xpath->query('//*[@id="scheduleDuration" or @id="schedulePwm" or @id="scheduleEnabled"]'));
            $this->assertCount(0, $xpath->query('//*[@data-bmg-add-time or @data-bmg-remove-time]'));
            $this->assertCount(0, $xpath->query('//input[@name="times[]" and @type!="hidden"]'));
            $this->assertCount(empty($denied['canConfigure']) && array_key_exists('canConfigure', $denied) ? 0 : 1,
                $xpath->query('//button[contains(., "Disable saved schedule intent")]'));
            $this->assertCount(1, $xpath->query('//*[@class="bmg-workspace" and @data-bmg-base-url]'));
        }
        $xpath = $this->xpath($this->render('unit_detail'));
        $this->assertCount(3, $xpath->query('//*[@id="scheduleDuration" or @id="schedulePwm" or @id="scheduleEnabled"]'));

    }

    public function testDisableFormRetainsTimesAndDurationButDoesNotSubmitPwm(): void
    {
        $xpath = $this->xpath($this->render('unit_detail', ['hardwareEnabled' => false]));
        $form = '//form[button[contains(., "Disable saved schedule intent")]]';
        $this->assertSame('0', $xpath->evaluate('string(' . $form . '/input[@name="enabled"]/@value)'));
        $this->assertSame('3', $xpath->evaluate('string(' . $form . '/input[@name="expected_version"]/@value)'));
        $this->assertSame('08:00', $xpath->evaluate('string(' . $form . '/input[@name="times[]"]/@value)'));
        $this->assertSame('60', $xpath->evaluate('string(' . $form . '/input[@name="duration_seconds"]/@value)'));
        $this->assertCount(0, $xpath->query($form . '//*[@name="pwm"]'));
        $disabled = $this->schedule();
        $disabled['enabled'] = false;
        $this->assertCount(0, $this->xpath($this->render('unit_detail', ['hardwareEnabled' => false, 'schedule' => $disabled]))
            ->query('//form[contains(@action, "/schedule")]'));
    }

    public function testReadOnlyDisablePayloadIsAcceptedByServiceWithoutHardwareAuthority(): void
    {
        $this->auditDb->query('CREATE TABLE bmg_devices (id INTEGER PRIMARY KEY)');
        require_once APPPATH . 'Database/Migrations/2026-10-17-000005_CreateBmgRotationOperations.php';
        (new \App\Database\Migrations\CreateBmgRotationOperations(Database::forge($this->auditDb)))->up();
        $this->auditDb->table('role_permissions')->insert(['role' => 'facilities_op', 'permission_id' => 3]);
        $this->auditDb->table('bmg_rotation_schedules')->insert([
            'tenant_id' => 1, 'unit_id' => 1, 'enabled' => 1, 'version' => 3,
            'times_json' => '["08:00"]', 'duration_seconds' => 60, 'pwm' => 120, 'updated_by_user_id' => 7,
                        'created_at' => '2026-10-19 00:00:00', 'updated_at' => '2026-10-19 00:00:00',
        ]);
        $xpath = $this->xpath($this->render('unit_detail', ['canHardware' => false]));
        $payload = [];
        foreach ($xpath->query('//form[button[contains(., "Disable saved schedule intent")]]/input') as $input) {
            $name = $input->getAttribute('name');
            if ($name === 'times[]') { $payload['times'][] = $input->getAttribute('value'); }
            elseif (in_array($name, ['enabled', 'expected_version', 'duration_seconds'], true)) {
                $payload[$name] = $input->getAttribute('value');
            }
        }
        $rotation = new \App\Services\Facilities\BmgRotationService($this->auditDb);
        foreach ([['times' => ['09:00']], ['duration_seconds' => '61'], ['pwm' => '100'], ['enabled' => '1']] as $edit) {
            try {
                $rotation->saveSchedule(1, array_replace($payload, $edit));
                $this->fail('Hardware edit must be rejected.');
            } catch (\RuntimeException $error) { $this->assertSame(403, $error->getCode()); }
        }
        $saved = $rotation->saveSchedule(1, $payload);
        $this->assertFalse($saved['enabled']);
        $this->assertSame(['08:00'], $saved['times']);
        $this->assertSame(60, $saved['duration_seconds']);
        $this->assertNull($saved['pwm']);
        $this->assertSame(4, $saved['version']);
        try {
            $rotation->saveSchedule(1, $payload);
            $this->fail('Stale disable action must not replace newer intent.');
        } catch (\RuntimeException $error) { $this->assertSame(409, $error->getCode()); }
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
        return ['enabled' => true, 'version' => 3, 'times' => ['08:00'], 'duration_seconds' => 60, 'pwm' => 120];
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
