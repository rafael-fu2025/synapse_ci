<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FacilitiesController;
use App\Services\AuthenticatedUser;
use App\Services\Clinic\InventoryService;
use App\Services\CurrentTenant;
use App\Services\Facilities\BmgRotationService;
use App\Services\Facilities\BmgService;
use App\Services\TeamAccess;
use App\Services\TeamTaskService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/** Guarded, private SQLite reads only; no migrations, dotenv or operational database. */
final class ResourceHistoryPaginationTest extends CIUnitTestCase
{
    private BaseConnection $historyDb;
    private array $connections;
    private TeamTaskService $tasks;
    private BmgRotationService $rotation;
    private BmgService $batches;
    private InventoryService $inventory;

    protected function setUp(): void
    {
        if (!defined('PAGINATION_ISOLATED_TESTS') || PAGINATION_ISOLATED_TESTS !== true) {
            $this->markTestSkipped('Use the guarded pagination-isolated-bootstrap.php.');
        }
        parent::setUp();
        if (!extension_loaded('sqlite3')) { $this->markTestSkipped('Private history fixtures require SQLite3.'); }
        $this->historyDb = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $instances->getValue();
        $instances->setValue(null, ['tests' => $this->historyDb, 'default' => $this->historyDb]);
        foreach ([
            'CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY, code TEXT)',
            'CREATE TABLE role_permissions (role TEXT, permission_id INTEGER)',
            'CREATE TABLE team_tasks (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit TEXT, title TEXT, description TEXT, status TEXT, priority TEXT, assignee_user_id INTEGER, created_by_user_id INTEGER, due_date TEXT, version INTEGER, checklist TEXT, created_at TEXT, updated_at TEXT, api_client_nonce TEXT, api_request_hash TEXT)',
            'CREATE TABLE team_task_comments (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit TEXT, task_id INTEGER, actor_user_id INTEGER, body TEXT, created_at TEXT, api_client_nonce TEXT, api_request_hash TEXT)',
            'CREATE TABLE team_task_activity (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit TEXT, task_id INTEGER, actor_user_id INTEGER, event TEXT, old_status TEXT, new_status TEXT, created_at TEXT, api_client_nonce TEXT, api_request_hash TEXT)',
            'CREATE TABLE bmg_units (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_code TEXT, name TEXT, status TEXT, drum_capacity_kg TEXT, deleted_at TEXT)',
            'CREATE TABLE bmg_devices (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, status TEXT, revoked_at TEXT, rotation_bound_at TEXT)',
            'CREATE TABLE bmg_rotation_schedules (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER)',
            'CREATE TABLE bmg_rotation_commands (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, command TEXT, status TEXT, created_at TEXT, expires_at TEXT)',
            'CREATE TABLE bmg_rotation_runs (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, motor_state TEXT, rotations INTEGER, occurred_at TEXT, created_at TEXT)',
            'CREATE TABLE bmg_batches (id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, batch_code TEXT, status TEXT, started_at TEXT, completed_at TEXT, total_input_weight_kg TEXT, output_weight_kg TEXT, notes TEXT, release_notes TEXT)',
            'CREATE TABLE clinic_medicines (id INTEGER PRIMARY KEY, tenant_id INTEGER, is_active INTEGER, minimum_stock_level INTEGER)',
            'CREATE TABLE clinic_medicine_batches (id INTEGER PRIMARY KEY, tenant_id INTEGER, medicine_id INTEGER, remaining_quantity INTEGER, status TEXT, expiration_date TEXT)',
            'CREATE TABLE stock_movements (id INTEGER PRIMARY KEY, tenant_id INTEGER, medicine_id INTEGER)',
            'CREATE TABLE inventory_operations (id INTEGER PRIMARY KEY, tenant_id INTEGER, medicine_id INTEGER, actor_id INTEGER, reversal_of INTEGER, reversal_mode TEXT)',
        ] as $sql) { $this->historyDb->query($sql); }
        $this->historyDb->table('tenants')->insertBatch([['id' => 7, 'is_active' => 1], ['id' => 8, 'is_active' => 1]]);
        $this->historyDb->table('users')->insertBatch([
            ['id' => 1, 'tenant_id' => 7, 'person_id' => null, 'username' => 'History reader', 'role' => 'superadmin', 'status' => 'active'],
            ['id' => 2, 'tenant_id' => 8, 'person_id' => null, 'username' => 'Foreign reader', 'role' => 'superadmin', 'status' => 'active'],
        ]);
        $this->historyDb->table('permissions')->insert(['id' => 1, 'code' => 'facilities.read']);
        $this->historyDb->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => 1]);
        foreach ([[11, 7, 'clinic'], [12, 7, 'clinic'], [13, 8, 'clinic'], [14, 7, 'guidance']] as [$id, $tenant, $unit]) {
            $this->historyDb->table('team_tasks')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'unit' => $unit, 'title' => 'Task ' . $id,
                'description' => '', 'status' => 'todo', 'priority' => 'normal', 'created_by_user_id' => 1,
                'version' => 1, 'checklist' => '[]', 'created_at' => '2026-11-01 00:00:00',
            ]);
        }
        foreach ([[11, 7, null], [12, 7, null], [13, 8, null], [14, 7, '2026-11-01 00:00:00']] as [$id, $tenant, $deleted]) {
            $this->historyDb->table('bmg_units')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'unit_code' => 'DRUM-' . $id,
                'name' => 'Drum ' . $id, 'status' => 'active', 'drum_capacity_kg' => '80.00', 'deleted_at' => $deleted,
            ]);
        }
        $this->historyDb->table('clinic_medicines')->insertBatch([
            ['id' => 11, 'tenant_id' => 7, 'is_active' => 1, 'minimum_stock_level' => 0],
            ['id' => 12, 'tenant_id' => 7, 'is_active' => 1, 'minimum_stock_level' => 0],
            ['id' => 13, 'tenant_id' => 8, 'is_active' => 1, 'minimum_stock_level' => 0],
        ]);
        service('request')->removeHeader('Authorization');
        service('request')->removeHeader('X-Device-Token');
        service('request')->removeHeader('X-Synapse-Refresh-Owner');
        service('request')->removeHeader('X-Synapse-Refresh-Url');
        $this->signIn(7);
        $this->tasks = new TeamTaskService(new TeamAccess(static fn (): array => ['id' => 1, 'tenant_id' => 7], $this->historyDb), $this->historyDb);
        $this->rotation = new BmgRotationService($this->historyDb);
        $this->batches = new BmgService($this->historyDb);
        $this->inventory = new InventoryService($this->historyDb);
    }

    protected function tearDown(): void
    {
        if (isset($this->historyDb)) {
            AuthenticatedUser::clear();
            service('request')->removeHeader('Authorization');
            service('request')->removeHeader('X-Device-Token');
            service('request')->removeHeader('X-Synapse-Refresh-Owner');
            service('request')->removeHeader('X-Synapse-Refresh-Url');
            $this->historyDb->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        }
        parent::tearDown();
    }

    private function signIn(int $tenant): void
    {
        AuthenticatedUser::clear();
        session()->set(['tenant_id' => $tenant, 'user' => ['id' => $tenant === 7 ? 1 : 2, 'tenant_id' => $tenant, 'logged_in' => true],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
        CurrentTenant::set($tenant);
    }

    private function seedTaskHistory(): void
    {
        foreach (['comments' => 121, 'activity' => 111] as $kind => $count) {
            for ($id = 1; $id <= $count; $id++) {
                $this->historyDb->table('team_task_' . $kind)->insert([
                    'id' => $id, 'tenant_id' => 7, 'unit' => 'clinic', 'task_id' => 11,
                    'actor_user_id' => 1, 'created_at' => '2026-11-01 00:00:00',
                    'api_client_nonce' => 'not-in-view', 'api_request_hash' => 'not-in-view',
                ] + ($kind === 'comments' ? ['body' => 'Comment ' . $id] : ['event' => 'updated']));
            }
            foreach ([[7, 'clinic', 12], [8, 'clinic', 11], [7, 'guidance', 11]] as $offset => [$tenant, $unit, $task]) {
                $this->historyDb->table('team_task_' . $kind)->insert([
                    'id' => 1000 + $offset, 'tenant_id' => $tenant, 'unit' => $unit, 'task_id' => $task, 'actor_user_id' => 1,
                ]);
            }
        }
    }

    private function ids(array $rows): array
    {
        return array_map('intval', array_column($rows, 'id'));
    }

    private function assertPage(array $metadata, int $page, int $size, int $total): void
    {
        $this->assertSame($page, $metadata['page']);
        $this->assertSame($size, $metadata['per_page']);
        $this->assertSame($total, $metadata['total']);
        $this->assertSame($total, $metadata['filtered']);
        $this->assertSame(max(1, (int) ceil($total / $size)), $metadata['pages']);
        $this->assertSame($total === 0 ? 0 : ($page - 1) * $size + 1, $metadata['from']);
        $this->assertSame(min($page * $size, $total), $metadata['to']);
    }

    public function testTaskHistoriesReachBeyondCapsIndependentlyAndKeepApiEnvelope(): void
    {
        $this->seedTaskHistory();
        $first = $this->tasks->detailPage('clinic', 11);
        $this->assertPage($first['commentsPagination'], 1, 50, 121);
        $this->assertPage($first['activityPagination'], 1, 50, 111);
        $this->assertSame(range(121, 72), $this->ids($first['comments']));
        $this->assertSame(range(111, 62), $this->ids($first['activity']));
        $paged = $this->tasks->detailPage('clinic', 11, ['comments_page' => '3', 'activity_page' => '2']);
        $this->assertSame(range(21, 1), $this->ids($paged['comments']));
        $this->assertSame(range(61, 12), $this->ids($paged['activity']));
        $this->assertSame(121, $paged['comments_total']);
        foreach (array_merge($paged['comments'], $paged['activity']) as $entry) {
            $this->assertIsInt($entry['id']);
            $this->assertArrayNotHasKey('api_client_nonce', $entry);
            $this->assertArrayNotHasKey('api_request_hash', $entry);
        }
        $legacy = $this->tasks->detail('clinic', 11);
        $this->assertArrayNotHasKey('commentsPagination', $legacy);
        $this->assertArrayNotHasKey('activityPagination', $legacy);
        $this->assertCount(50, $legacy['comments']);
        $this->assertCount(50, $legacy['activity']);
        $this->assertSame(50, $legacy['comments_limit']);
        $this->assertSame(121, $legacy['comments_total']);
        $last = $this->tasks->detailPage('clinic', 11, ['comments_page' => '1000000', 'activity_page' => '1000000']);
        $this->assertPage($last['commentsPagination'], 3, 50, 121);
        $this->assertPage($last['activityPagination'], 3, 50, 111);
        $empty = $this->tasks->detailPage('guidance', 14, ['comments_page' => '1000000', 'activity_page' => '1000000']);
        $this->assertPage($empty['commentsPagination'], 1, 50, 0);
        $this->assertPage($empty['activityPagination'], 1, 50, 0);
        $this->reject(fn () => $this->tasks->detailPage('clinic', 13), 404);
        $this->reject(fn () => $this->tasks->detailPage('guidance', 11), 404);
    }

    public function testNamedTaskSizesDoNotChangeSiblingPage(): void
    {
        $this->seedTaskHistory();
        foreach ([20, 25, 50, 100] as $size) {
            $detail = $this->tasks->detailPage('clinic', 11, ['comments_page' => 2, 'comments_per_page' => $size, 'activity_page' => 2]);
            $this->assertPage($detail['commentsPagination'], 2, $size, 121);
            $this->assertPage($detail['activityPagination'], 2, 50, 111);
            $this->assertSame(121 - $size, (int) $detail['comments'][0]['id']);
            $this->assertSame(61, (int) $detail['activity'][0]['id']);
        }
    }

    public function testDrumHistoryCountsAllScopedRowsAndDoesNotAlterPendingCommands(): void
    {
        foreach (['commands' => 45, 'runs' => 43] as $kind => $count) {
            for ($id = 1; $id <= $count; $id++) {
                $this->historyDb->table('bmg_rotation_' . $kind)->insert([
                    'id' => $id, 'tenant_id' => 7, 'unit_id' => 11,
                ] + ($kind === 'commands' ? ['command' => 'stop', 'status' => 'pending', 'expires_at' => '2020-01-01 00:00:00'] : ['motor_state' => 'stopped']));
            }
            $this->historyDb->table('bmg_rotation_' . $kind)->insertBatch([
                ['id' => 1000, 'tenant_id' => 7, 'unit_id' => 12],
                ['id' => 1001, 'tenant_id' => 8, 'unit_id' => 11],
            ]);
        }
        $first = $this->rotation->unitPage(11);
        $this->assertPage($first['commandsPagination'], 1, 20, 45);
        $this->assertPage($first['runsPagination'], 1, 20, 43);
        $paged = $this->rotation->unitPage(11, ['commands_page' => 3, 'runs_page' => 2]);
        $this->assertSame(range(5, 1), $this->ids($paged['commands']));
        $this->assertSame(range(23, 4), $this->ids($paged['runs']));
        $sizes = $this->rotation->unitPage(11, ['commands_per_page' => 25, 'commands_page' => 2, 'runs_per_page' => 50, 'runs_page' => 2]);
        $this->assertPage($sizes['commandsPagination'], 2, 25, 45);
        $this->assertPage($sizes['runsPagination'], 1, 50, 43);
        $legacy = $this->rotation->unit(11);
        $this->assertCount(20, $legacy['commands']);
        $this->assertCount(20, $legacy['runs']);
        $this->assertArrayNotHasKey('commandsPagination', $legacy);
        $this->assertSame(45, $this->historyDb->table('bmg_rotation_commands')->where('tenant_id', 7)->where('unit_id', 11)->where('status', 'pending')->countAllResults());
        $this->reject(fn () => $this->rotation->unitPage(13), 404);
        $this->reject(fn () => $this->rotation->unitPage(14), 404);
        $this->signIn(8);
        $empty = $this->rotation->unitPage(13, ['commands_page' => 999, 'runs_page' => 999]);
        $this->assertPage($empty['commandsPagination'], 1, 20, 0);
        $this->assertPage($empty['runsPagination'], 1, 20, 0);
    }

    public function testPastBatchPagingKeepsStableStartOrderAndIndependentActiveBatch(): void
    {
        for ($id = 1; $id <= 111; $id++) {
            $this->historyDb->table('bmg_batches')->insert([
                'id' => $id, 'tenant_id' => 7, 'unit_id' => 11, 'batch_code' => 'BATCH-' . $id,
                'status' => $id % 2 === 0 ? 'released' : 'voided',
                'started_at' => $id === 1 ? '2026-11-02 00:00:00' : '2026-11-01 00:00:00',
            ]);
        }
        $this->historyDb->table('bmg_batches')->insertBatch([
            ['id' => 200, 'tenant_id' => 7, 'unit_id' => 11, 'status' => 'processing'],
            ['id' => 201, 'tenant_id' => 7, 'unit_id' => 12, 'status' => 'released'],
            ['id' => 202, 'tenant_id' => 8, 'unit_id' => 11, 'status' => 'released'],
        ]);
        $first = $this->batches->batchOverviewPage(11);
        $this->assertPage($first['batchesPagination'], 1, 50, 111);
        $this->assertSame(array_merge([1], range(111, 63)), $this->ids($first['pastBatches']));
        $last = $this->batches->batchOverviewPage(11, ['batches_page' => 1000000, 'commands_page' => 2, 'runs_page' => 3]);
        $this->assertPage($last['batchesPagination'], 3, 50, 111);
        $this->assertSame(range(12, 2), $this->ids($last['pastBatches']));
        $this->assertSame($first['activeBatch'], $last['activeBatch']);
        $this->assertSame(200, (int) $last['activeBatch']['id']);
        $legacy = $this->batches->batchOverview(11);
        $this->assertSame(['activeBatch', 'pastBatches'], array_keys($legacy));
        $this->assertCount(50, $legacy['pastBatches']);
        $this->reject(fn () => $this->batches->batchOverviewPage(13), 404);
        $this->reject(fn () => $this->batches->batchOverviewPage(14), 404);
        $this->signIn(8);
        $empty = $this->batches->batchOverviewPage(13, ['batches_page' => 999]);
        $this->assertNull($empty['activeBatch']);
        $this->assertPage($empty['batchesPagination'], 1, 50, 0);
    }

    public function testInventoryOperationPagingIsIndependentFromExistingMovementCursor(): void
    {
        for ($id = 1; $id <= 231; $id++) {
            $this->historyDb->table('inventory_operations')->insert(['id' => $id, 'tenant_id' => 7, 'medicine_id' => 11, 'actor_id' => 1]);
        }
        for ($id = 1; $id <= 141; $id++) {
            $this->historyDb->table('stock_movements')->insert(['id' => $id, 'tenant_id' => 7, 'medicine_id' => 11]);
        }
        $this->historyDb->table('inventory_operations')->insertBatch([
            ['id' => 1000, 'tenant_id' => 7, 'medicine_id' => 12, 'actor_id' => 1],
            ['id' => 1001, 'tenant_id' => 8, 'medicine_id' => 11, 'actor_id' => 2],
        ]);
        $this->historyDb->table('stock_movements')->insertBatch([
            ['id' => 1000, 'tenant_id' => 7, 'medicine_id' => 12],
            ['id' => 1001, 'tenant_id' => 8, 'medicine_id' => 11],
        ]);
        $first = $this->inventory->detailsPage(11);
        $this->assertPage($first['operationsPagination'], 1, 100, 231);
        $paged = $this->inventory->detailsPage(11, ['operations_page' => 3, 'page' => 2, 'per_page' => 50]);
        $this->assertPage($paged['operationsPagination'], 3, 100, 231);
        $this->assertSame(range(31, 1), $this->ids($paged['operations']));
        $this->assertSame(range(91, 42), $this->ids($paged['movements']));
        $this->assertSame(141, $paged['movementPagination']['total']);
        $this->assertSame(2, $paged['movement_page']);
        $this->assertSame(50, $paged['movement_page_size']);
        $this->assertSame('History reader', $paged['operations'][0]['actor_name']);
        $sizes = $this->inventory->detailsPage(11, ['operations_page' => 2, 'operations_per_page' => 20, 'page' => 2, 'per_page' => 25]);
        $this->assertPage($sizes['operationsPagination'], 2, 20, 231);
        $this->assertSame(range(211, 192), $this->ids($sizes['operations']));
        $this->assertSame(116, (int) $sizes['movements'][0]['id']);
        $legacy = $this->inventory->details(11, 2, 50);
        $this->assertArrayNotHasKey('operationsPagination', $legacy);
        $this->assertCount(100, $legacy['operations']);
        $this->assertSame($legacy['movements'], $paged['movements']);
        $this->reject(fn () => $this->inventory->detailsPage(13), 404);
        CurrentTenant::set(8);
        $empty = $this->inventory->detailsPage(13, ['operations_page' => 1000000, 'page' => 1000000]);
        $this->assertPage($empty['operationsPagination'], 1, 100, 0);
        $this->assertSame(1, $empty['movementPagination']['page']);
        $this->assertSame([], $empty['operations']);
    }

    public function testEveryWebReaderRejectsMalformedNamedCursorsAndSizes(): void
    {
        foreach ([
            fn () => $this->tasks->detailPage('clinic', 11, ['comments_page' => []]),
            fn () => $this->tasks->detailPage('clinic', 11, ['activity_page' => '0']),
            fn () => $this->tasks->detailPage('clinic', 11, ['comments_per_page' => '51']),
            fn () => $this->rotation->unitPage(11, ['commands_page' => '1000001']),
            fn () => $this->rotation->unitPage(11, ['runs_per_page' => false]),
            fn () => $this->batches->batchOverviewPage(11, ['batches_page' => '1e2']),
            fn () => $this->batches->batchOverviewPage(11, ['batches_per_page' => '0']),
            fn () => $this->inventory->detailsPage(11, ['operations_page' => '-1']),
            fn () => $this->inventory->detailsPage(11, ['operations_per_page' => '101']),
            fn () => $this->inventory->detailsPage(11, ['per_page' => '10']),
        ] as $read) { $this->reject($read, 422, InvalidArgumentException::class); }
    }

    public function testFacilitiesRefreshRetainsOnlyValidatedSameOwnerReadCursors(): void
    {
        $controller = (new ReflectionClass(FacilitiesController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(\CodeIgniter\Controller::class, 'request'))->setValue($controller, service('request'));
        $target = 'https://example.test/subdir/index.php/facilities/unit/11';
        $method = new ReflectionMethod(FacilitiesController::class, 'unitHistoryTarget');
        $query = 'commands_page=3&commands_per_page=20&runs_page=2&runs_per_page=25&batches_page=4&batches_per_page=50';
        service('request')->setHeader('X-Synapse-Refresh-Owner', 'facilities.unit.11');
        service('request')->setHeader('X-Synapse-Refresh-Url', $target . '?' . $query . '&tenant_id=8&command=run_now&token=discarded');
        $this->assertSame($target . '?' . $query, $method->invoke($controller, 11, $target));
        foreach ([
            str_replace('/unit/11', '/unit/12', $target) . '?' . $query,
            str_replace('example.test', 'foreign.test', $target) . '?' . $query,
            str_replace('https:', 'http:', $target) . '?' . $query,
            str_replace('example.test', 'user@example.test', $target) . '?' . $query,
            $target . '?commands_page[]=2',
            $target . '?runs_page=0',
            $target . '?batches_per_page=51',
            $target . '?' . $query . '#foreign',
            $target . '?tenant_id=8',
        ] as $hint) {
            service('request')->setHeader('X-Synapse-Refresh-Url', $hint);
            $this->assertSame($target, $method->invoke($controller, 11, $target));
        }
        service('request')->setHeader('X-Synapse-Refresh-Url', $target . '?' . $query);
        service('request')->setHeader('X-Synapse-Refresh-Owner', 'facilities.unit.12');
        $this->assertSame($target, $method->invoke($controller, 11, $target));
    }

    private function reject(callable $read, int $code, string $class = \RuntimeException::class): void
    {
        try { $read(); } catch (Throwable $error) {
            $this->assertInstanceOf($class, $error);
            $this->assertSame($code, $error->getCode(), $error->getMessage());
            return;
        }
        $this->fail('Expected scoped read rejection.');
    }
}
