<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CurrentTenant;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\View\View;
use Config\Services;
use Tests\Support\InventoryDatabaseTestCase;

/** Runs only after InventoryTestDatabase verifies the isolated MySQL test schema. */
final class InventoryRoutesTest extends InventoryDatabaseTestCase
{
    use FeatureTestTrait;

    private array $grants = [];
    private array $viewData = [];
    private string $viewName = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['clinic_staff', 'clinic_admin'] as $role) {
            foreach (['clinic.inventory.read', 'clinic.inventory.write', 'clinic.encounters.read', 'clinic.encounters.write'] as $code) {
                $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
                if ($permission === null) {
                    $this->db->table('permissions')->insert(['code' => $code, 'name' => $code, 'module' => 'clinic']);
                    $permission = ['id' => $this->db->insertID()];
                }
                $id = (int) $permission['id'];
                $existed = $this->db->table('role_permissions')->where('role', $role)->where('permission_id', $id)->countAllResults() > 0;
                $this->grants[] = [$role, $id, $existed];
                if (!$existed) {
                    $this->db->table('role_permissions')->insert(['role' => $role, 'permission_id' => $id]);
                }
            }
        }
        // Assert the HTTP view contracts without requiring or writing the separately owned templates.
        $renderer = $this->createMock(View::class);
        $renderer->method('setData')->willReturnCallback(function (array $data) use ($renderer) {
            $this->viewData = $data;
            return $renderer;
        });
        $renderer->method('render')->willReturnCallback(function (string $view): string {
            $this->viewName = $view;
            return 'Inventory view contract';
        });
        Services::injectMock('renderer', $renderer);
    }

    protected function tearDown(): void
    {
        foreach ($this->grants as [$role, $id, $existed]) {
            $this->db->table('role_permissions')->where('role', $role)->where('permission_id', $id)->delete();
            if ($existed) {
                $this->db->table('role_permissions')->insert(['role' => $role, 'permission_id' => $id]);
            }
        }
        Services::resetSingle('security');
        Services::resetSingle('renderer');
        service('superglobals')->setCookieArray([]);
        parent::tearDown();
    }

    private function actorSession(?int $id = null, string $role = 'superadmin', ?int $tenant = null): array
    {
        return ['tenant_id' => $tenant ?? $this->tenantId, 'user' => [
            'id' => $id ?? $this->actorId, 'role' => $role, 'name' => 'Inventory HTTP tester',
            'logged_in' => true, 'permissions' => ['clinic.inventory.read', 'clinic.inventory.write'],
        ]];
    }

    private function submit(string $path, array $data, ?array $session = null, bool $ajax = true)
    {
        $token = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $token);
        Services::resetSingle('security');
        return $this->withSession($session ?? $this->actorSession())
            ->withHeaders($ajax ? ['X-Requested-With' => 'XMLHttpRequest'] : [])
            ->post($path, $data + [config('Security')->tokenName => $token]);
    }

    private function json($response, int $status = 200): array
    {

        $response->assertStatus($status);
        $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($status === 200, $json['success']);
        $this->assertSame(csrf_token(), $json['csrf']['name']);
        $this->assertNotEmpty($json['csrf']['hash']);
        return $json;
    }

    private function catalogData(string $key = 'http-catalog'): array
    {
        return ['name' => 'HTTP catalog', 'generic_name' => 'Generic', 'category' => 'Analgesic',
            'dosage_form' => 'Tablet', 'strength' => '500mg', 'unit_of_measure' => 'tablet',
            'minimum_stock_level' => '2', 'is_active' => '1', 'idempotency_key' => $key];
    }

    private function receiptData(string $key = 'http-receipt'): array
    {
        return ['batch_number' => 'HTTP-LOT', 'expiration_date' => date('Y-m-d', strtotime('+1 year')),
            'quantity' => '10', 'supplier' => 'Supplier', 'reference' => 'Reference', 'idempotency_key' => $key];
    }

    public function testIndexAndShowContractsAndFreshDatabaseManagerRole(): void
    {
        $staff = $this->user('clinic_staff');
        $session = $this->actorSession($staff); // Deliberately stale superadmin session role.
        $this->withSession($session)->get('clinic/inventory?q=Ledger&status=active')->assertStatus(200);
        $this->assertSame('inventory/index', $this->viewName);
        $this->assertSame(['q' => 'Ledger', 'status' => 'active', 'per_page' => 25], $this->viewData['filters']);
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 1, 'filtered' => 1, 'pages' => 1, 'from' => 1, 'to' => 1], $this->viewData['pagination']);
        $this->assertCount(1, $this->viewData['medicines']);
        $this->assertFalse($this->viewData['canManage']);
        $this->assertTrue($this->viewData['canWrite']);
        $this->withSession($this->actorSession())->get('clinic/inventory/' . $this->medicineId)->assertStatus(200);
        $this->assertSame('inventory/show', $this->viewName);
        foreach (['medicine', 'batches', 'movements', 'operations', 'movementPagination', 'canManage', 'canWrite'] as $key) {
            $this->assertArrayHasKey($key, $this->viewData);
        }
        $this->assertSame(1, $this->viewData['movementPagination']['page']);
        $this->assertSame(1, $this->viewData['movementPagination']['pages']);
        $this->assertTrue($this->viewData['canManage']);
        $this->assertTrue($this->viewData['canWrite']);
        $admin = $this->user('clinic_admin');
        $this->withSession($this->actorSession($admin, 'clinic_staff'))->get('clinic/inventory')->assertStatus(200);
        $this->assertTrue($this->viewData['canManage']);
        $this->assertTrue($this->viewData['canWrite']);
        $write = $this->db->table('permissions')->where('code', 'clinic.inventory.write')->get()->getRow()->id;
        $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $write)->delete();
        foreach (['clinic/inventory', 'clinic/inventory/' . $this->medicineId] as $path) {
            $this->withSession($session)->get($path)->assertStatus(200);
            $this->assertFalse($this->viewData['canWrite']);
            $this->assertFalse($this->viewData['canManage']);
        }
    }

    public function testAllFormContractsAndValidationRetainsKey(): void
    {
        $this->receipt();
        $batch = $this->inventory->details($this->medicineId)['batches'][0];
        $operation = $this->dispense()['operation'];
        $base = 'clinic/inventory';
        foreach ([
            [$base . '/create', 'catalog', $base . '/store', 'medicine'],
            [$base . '/' . $this->medicineId . '/edit', 'catalog', $base . '/' . $this->medicineId . '/update', 'medicine'],
            [$base . '/' . $this->medicineId . '/receive', 'receive', $base . '/' . $this->medicineId . '/receive', 'medicine'],
            [$base . '/batches/' . $batch['id'] . '/adjust', 'adjust', $base . '/batches/' . $batch['id'] . '/adjust', 'batch'],
            [$base . '/batches/' . $batch['id'] . '/status', 'status', $base . '/batches/' . $batch['id'] . '/status', 'batch'],
            [$base . '/operations/' . $operation['id'] . '/reverse', 'reverse', $base . '/operations/' . $operation['id'] . '/reverse', 'operation'],
        ] as [$path, $view, $action, $entity]) {
            $this->withSession($this->actorSession())->get($path)->assertStatus(200);
            $this->assertSame('inventory/' . $view, $this->viewName);
            $this->assertTrue($this->viewData['formModalPage']);
            $this->assertSame(site_url('/' . $action), $this->viewData['formAction']);
            $this->assertArrayHasKey($entity, $this->viewData);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $this->viewData['idempotencyKey']);
            $this->assertNotEmpty($this->viewData['title']);
            $this->assertNotEmpty($this->viewData['cancelUrl']);
        }
        $key = $this->viewData['idempotencyKey'];
        $path = $base . '/' . $this->medicineId . '/receive';
        $response = $this->submit($path, ['quantity' => '1.5', 'idempotency_key' => $key], null, false);
        $response->assertRedirectTo('/' . $path);
        $this->assertSame($key, old('idempotency_key', null, false));
        $this->withSession($_SESSION)->withHeaders([])->get($path)->assertStatus(200);
        $this->assertSame($key, $this->viewData['idempotencyKey']);
    }

    public function testCatalogSaveAndOptimisticConflict(): void
    {
        $json = $this->json($this->submit('clinic/inventory/store', $this->catalogData()));
        $id = $json['medicine']['id'];
        $this->json($this->submit('clinic/inventory/' . $id . '/update', ['name' => 'Renamed', 'version' => '1', 'idempotency_key' => 'http-catalog']));
        $this->json($this->submit('clinic/inventory/' . $id . '/update', ['name' => 'Stale', 'version' => '1', 'idempotency_key' => 'http-catalog']), 409);
    }

    public function testCatalogCreateRejectsMissingAndMalformedKeysWithoutCreatingMedicine(): void
    {
        $before = $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults();
        $beforeOperations = $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->countAllResults();
        $data = $this->catalogData();
        unset($data['idempotency_key']);
        $json = $this->json($this->submit('clinic/inventory/store', $data), 422);
        $this->assertStringContainsString('ASCII idempotency key', $json['message']);
        foreach (['', ' ', 'with space', 'slash/key', "line\nbreak", "null\0byte", 'nonascii-é', str_repeat('x', 129), ['key']] as $key) {
            $this->json($this->submit('clinic/inventory/store', $data + ['idempotency_key' => $key]), 422);
        }
        $this->assertSame($before, $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertSame($beforeOperations, $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->countAllResults());
    }

    public function testCatalogRetryRetainsFormKeyAndLostResponseReplayCreatesOnlyOneMedicine(): void
    {
        $this->withSession($this->actorSession())->get('clinic/inventory/create')->assertStatus(200);
        $key = $this->viewData['idempotencyKey'];
        $payload = $this->catalogData($key);
        $before = $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults();
        $this->submit('clinic/inventory/store', array_replace($payload, ['name' => '']), null, false)
            ->assertRedirectTo('/clinic/inventory/create');
        $this->assertSame($key, old('idempotency_key', null, false));
        $this->withSession($_SESSION)->withHeaders([])->get('clinic/inventory/create')->assertStatus(200);
        $this->assertSame($key, $this->viewData['idempotencyKey']);
        $first = $this->json($this->submit('clinic/inventory/store', $payload));
        // Retry the identical form after an unobserved successful response, with a fresh CSRF token.
        $replay = $this->json($this->submit('clinic/inventory/store', $payload));
        $this->assertSame($first['medicine']['id'], $replay['medicine']['id']);
        $this->assertSame($first['redirect'], $replay['redirect']);
        $this->assertSame($before + 1, $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults());
        $operation = $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->where('idempotency_key', $key)->get()->getResultArray();
        $this->assertCount(1, $operation);
        $this->assertSame('catalog', $operation[0]['type']);
        $this->assertSame((int) $first['medicine']['id'], (int) $operation[0]['medicine_id']);
        $this->assertSame(0, $this->db->table('stock_movements')->where('tenant_id', $this->tenantId)->where('medicine_id', $first['medicine']['id'])->countAllResults());
        $this->json($this->submit('clinic/inventory/store', array_replace($payload, ['name' => 'Different medicine'])), 409);
        $other = $this->user('superadmin');
        $this->json($this->submit('clinic/inventory/store', $payload, $this->actorSession($other)), 409);
        $this->assertSame($before + 1, $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults());
    }

    public function testReceiptReplayConflictAndRawQuantityValidationRotateTokens(): void
    {
        $path = 'clinic/inventory/' . $this->medicineId . '/receive';
        $response = $this->submit($path, $this->receiptData());
        $json = $this->json($response);
        $this->assertFalse($json['replayed']);
        $next = $json['csrf']['hash'];
        $json = $this->json($this->submit($path, $this->receiptData()));
        $this->assertTrue($json['replayed']);
        $this->assertNotSame($next, $json['csrf']['hash']);
        $this->json($this->submit($path, array_replace($this->receiptData(), ['quantity' => '11'])), 409);
        foreach (['1.5', '1e2', '-1', '0', '01', ['10']] as $quantity) {
            $this->json($this->submit($path, array_replace($this->receiptData(uniqid('invalid-')), ['quantity' => $quantity])), 422);
        }
        $this->assertBalanced(10);
    }

    public function testManagerAdjustQuarantineReleaseAndFullCorrection(): void
    {
        $this->receipt();
        $batchId = $this->inventory->details($this->medicineId)['batches'][0]['id'];
        $base = 'clinic/inventory/batches/' . $batchId;
        $this->json($this->submit($base . '/adjust', ['quantity' => '2', 'reason' => 'damaged', 'note' => 'Broken seal', 'idempotency_key' => 'http-adjust']));
        $this->assertBalanced(8);
        $this->json($this->submit($base . '/status', ['status' => 'quarantined', 'reason' => 'Inspection', 'idempotency_key' => 'http-quarantine']));
        $this->json($this->submit($base . '/status', ['status' => 'active', 'reason' => 'Passed inspection', 'idempotency_key' => 'http-release']));
        $dispense = $this->dispense(3);
        $path = 'clinic/inventory/operations/' . $dispense['operation']['id'] . '/reverse';
        $this->json($this->submit($path, ['mode' => 'patient_return', 'reason' => 'Not permitted', 'idempotency_key' => 'http-return', 'review_confirmed' => '1']), 422);
        $payload = ['mode' => 'restore_unissued', 'reason' => 'Never left clinic custody', 'idempotency_key' => 'http-reverse', 'review_confirmed' => '1'];
        $this->json($this->submit($path, $payload));
        $this->assertTrue($this->json($this->submit($path, $payload))['replayed']);
        $this->json($this->submit($path, array_replace($payload, ['idempotency_key' => 'other-reverse'])), 409);
        $this->assertBalanced(8);
        $dispense = $this->dispense(1);
        $this->json($this->submit('clinic/inventory/operations/' . $dispense['operation']['id'] . '/reverse', [
            'mode' => 'record_only', 'reason' => 'Correct record only', 'idempotency_key' => 'http-record-only', 'review_confirmed' => '1',
        ]));
        $this->assertBalanced(7);
    }

    public function testReversalRequiresExactReviewConfirmationAndRetainsFailedFormInput(): void
    {
        $this->receipt();
        $operation = $this->dispense()['operation'];
        $path = 'clinic/inventory/operations/' . $operation['id'] . '/reverse';
        $before = $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->countAllResults();
        foreach (['record_only', 'restore_unissued'] as $mode) {
            $payload = ['mode' => $mode, 'reason' => 'Review correction', 'idempotency_key' => 'http-reviewed-correction'];
            $this->json($this->submit($path, $payload), 422);
            foreach (['', '0', 'true', 'on', '01', ' 1', '1 ', ['1']] as $flag) {
                $this->json($this->submit($path, $payload + ['review_confirmed' => $flag]), 422);
            }
        }
        $response = $this->submit($path, $payload, null, false);
        $response->assertRedirectTo('/' . $path);
        $this->assertSame($payload['idempotency_key'], old('idempotency_key', null, false));
        $this->assertSame($payload['mode'], old('mode', null, false));
        $this->assertSame($payload['reason'], old('reason', null, false));
        $this->withSession($_SESSION)->withHeaders([])->get($path)->assertStatus(200);
        $this->assertSame($payload['idempotency_key'], $this->viewData['idempotencyKey']);
        $this->assertSame($before, $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertBalanced(7);
        $this->json($this->submit($path, $payload + ['review_confirmed' => '1']));
        $this->assertBalanced(10);
    }

    public function testStaffReceivesAndDispensesButCannotManageEvenWithStaleSessionRole(): void
    {
        $staff = $this->user('clinic_staff');
        $session = $this->actorSession($staff);
        $this->json($this->submit('clinic/inventory/' . $this->medicineId . '/receive', $this->receiptData(), $session));
        $batch = $this->inventory->details($this->medicineId)['batches'][0];
        $dispense = $this->dispense(1);
        foreach ([
            ['clinic/inventory/store', []],
            ['clinic/inventory/' . $this->medicineId . '/update', ['name' => 'Denied', 'version' => '1']],
            ['clinic/inventory/batches/' . $batch['id'] . '/adjust', []],
            ['clinic/inventory/batches/' . $batch['id'] . '/status', []],
            ['clinic/inventory/operations/' . $dispense['operation']['id'] . '/reverse', []],
        ] as [$path, $payload]) {
            $this->json($this->submit($path, $payload, $session), 403);
        }
        $path = 'clinic/encounters/' . $this->encounterId . '/dispense';
        $payload = ['medicine_id' => (string) $this->medicineId, 'quantity' => '2', 'instructions' => 'After meals', 'idempotency_key' => 'http-staff-dispense'];
        $this->json($this->submit($path, $payload, $session));
        $write = $this->db->table('permissions')->where('code', 'clinic.inventory.write')->get()->getRow()->id;
        $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $write)->delete();
        $this->json($this->submit($path, $payload, $session), 403); // Even replay must reauthorize.
        $this->json($this->submit('clinic/inventory/' . $this->medicineId . '/receive', $this->receiptData('denied-receipt'), $session), 403);
        $this->withSession($session)->withHeaders([])->get('clinic/inventory')->assertStatus(200);
        $this->assertFalse($this->viewData['canManage']);
        $this->assertFalse($this->viewData['canWrite']);
        $read = $this->db->table('permissions')->where('code', 'clinic.inventory.read')->get()->getRow()->id;
        $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $read)->delete();
        $this->withSession($session)->get('clinic/inventory')->assertStatus(403);
        $this->assertBalanced(7);
    }

    public function testStaleAccountStateRejectsAllReadSurfacesBeforeLookup(): void
    {
        foreach (['demoted', 'suspended', 'deleted', 'missing'] as $state) {
            $id = $this->user('clinic_staff');
            match ($state) {
                'demoted' => $this->db->table('users')->where('id', $id)->update(['role' => 'student']),
                'suspended' => $this->db->table('users')->where('id', $id)->update(['status' => 'suspended']),
                'deleted' => $this->db->table('users')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]),
                'missing' => $this->db->table('users')->where('id', $id)->delete(),
            };
            foreach (['clinic/inventory', 'clinic/inventory/create', 'clinic/inventory/' . $this->medicineId,
                'clinic/inventory/' . $this->medicineId . '/receive', 'clinic/inventory/batches/1/adjust',
                'clinic/inventory/batches/1/status', 'clinic/inventory/operations/1/reverse',
                'clinic/encounters/' . $this->encounterId] as $path) {
                $response = $this->withSession($this->actorSession($id))->withHeaders([])->get($path);
                $response->assertStatus(403);
                $this->assertFalse($response->isRedirect());
            }
            $this->json($this->submit('clinic/encounters/' . $this->encounterId . '/complete',
                ['disposition' => 'discharged'], $this->actorSession($id)), 403);
            $this->assertSame('in_progress', $this->db->table('clinic_encounters')->where('id', $this->encounterId)->get()->getRow()->status);
        }
    }

    public function testTenantScopedMedicineBatchAndOperationLookupsReturn404(): void
    {
        $this->receipt();
        $batch = $this->inventory->details($this->medicineId)['batches'][0];
        $operation = $this->dispense()['operation'];
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('http_other_'), 'name' => 'Other tenant', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $tenant = (int) $this->db->insertID();
        $other = $this->user('superadmin', $tenant);
        $session = $this->actorSession($other, 'superadmin', $tenant);
        foreach (['clinic/inventory/' . $this->medicineId, 'clinic/inventory/' . $this->medicineId . '/edit',
            'clinic/inventory/' . $this->medicineId . '/receive', 'clinic/inventory/batches/' . $batch['id'] . '/adjust',
            'clinic/inventory/batches/' . $batch['id'] . '/status', 'clinic/inventory/operations/' . $operation['id'] . '/reverse'] as $path) {
            $this->withSession($session)->withHeaders([])->get($path)->assertStatus(404);
        }
        foreach (['clinic/inventory/' . $this->medicineId . '/receive', 'clinic/inventory/batches/' . $batch['id'] . '/adjust',
            'clinic/inventory/batches/' . $batch['id'] . '/status', 'clinic/inventory/operations/' . $operation['id'] . '/reverse'] as $path) {
            $this->json($this->submit($path, [], $session), 404);
        }
        CurrentTenant::set($this->tenantId);
        $this->assertBalanced(7);
    }

    public function testEncounterViewSuppliesWriteCapabilityAndClosedHistoryNeverAllowsDispensing(): void
    {
        $staff = $this->user('clinic_staff');
        $session = $this->actorSession($staff, 'clinic_staff');
        $path = 'clinic/encounters/' . $this->encounterId;
        $this->withSession($session)->get($path)->assertStatus(200);
        $this->assertSame('clinic/encounters/stepper', $this->viewName);
        $this->assertTrue($this->viewData['canDispense']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $this->viewData['dispenseKey']);
        $write = $this->db->table('permissions')->where('code', 'clinic.inventory.write')->get()->getRow()->id;
        $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $write)->delete();
        $this->withSession($session)->get($path)->assertStatus(200);
        $this->assertFalse($this->viewData['canDispense']);
        $this->assertSame([], $this->viewData['availableMedicines']);
        $inventoryRead = $this->db->table('permissions')->where('code', 'clinic.inventory.read')->get()->getRow()->id;
        $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $inventoryRead)->delete();
        $this->withSession($session)->get($path)->assertStatus(200);
        $this->assertFalse($this->viewData['canDispense']);
        $this->assertSame([], $this->viewData['availableMedicines']);
        $encounterRead = $this->db->table('permissions')->where('code', 'clinic.encounters.read')->get()->getRow()->id;
        $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $encounterRead)->delete();
        $this->withSession($session)->get($path)->assertStatus(403);
        $this->db->table('clinic_encounters')->where('id', $this->encounterId)->update(['status' => 'completed']);
        $this->withSession($this->actorSession())->get($path)->assertStatus(200);
        $this->assertFalse($this->viewData['canDispense']);
    }

    public function testCompletionUsesFreshEncounterWriteNotInventoryGrantsOrSessionClaims(): void
    {
        $staff = $this->user('clinic_staff');
        $session = $this->actorSession($staff); // Cached superadmin cannot bypass authoritative grants.
        $path = 'clinic/encounters/' . $this->encounterId . '/complete';
        $payload = ['disposition' => 'discharged', 'disposition_notes' => 'Reviewed'];
        $write = $this->db->table('permissions')->where('code', 'clinic.encounters.write')->get()->getRow()->id;
        $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $write)->delete();
        $this->json($this->submit($path, $payload, $session), 403);
        $response = $this->submit($path, $payload, $session, false);
        $response->assertRedirectTo('/clinic/encounters/' . $this->encounterId);
        $this->assertSame($payload['disposition_notes'], old('disposition_notes', null, false));
        $this->assertSame('in_progress', $this->db->table('clinic_encounters')->where('id', $this->encounterId)->get()->getRow()->status);
        $this->json($this->submit($path, $payload, $this->actorSession($staff, 'superadmin', $this->tenantId + 1)), 403);
        $this->db->table('role_permissions')->insert(['role' => 'clinic_staff', 'permission_id' => $write]);
        foreach (['clinic.inventory.read', 'clinic.inventory.write'] as $code) {
            $permission = $this->db->table('permissions')->where('code', $code)->get()->getRow()->id;
            $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $permission)->delete();
        }
        $this->withSession($session)->withHeaders([])->get('clinic/encounters/' . $this->encounterId)->assertStatus(200);
        $this->assertFalse($this->viewData['canDispense']);
        $this->json($this->submit($path, $payload, $session));
        $this->assertSame('completed', $this->db->table('clinic_encounters')->where('id', $this->encounterId)->get()->getRow()->status);
    }

    public function testDispenseStrictInputsReplayAndCompletionConflict(): void
    {
        $this->receipt();
        $path = 'clinic/encounters/' . $this->encounterId . '/dispense';
        $payload = ['medicine_id' => (string) $this->medicineId, 'quantity' => '2', 'idempotency_key' => 'http-dispense'];
        foreach ([[], '0', '-1', '1.5', '1e2', '01', '999999999999999999999999'] as $invalid) {
            $this->json($this->submit($path, array_replace($payload, ['medicine_id' => $invalid])), 422);
        }
        foreach ([[], '0', '-1', '1.5', '1e2', '01'] as $invalid) {
            $this->json($this->submit($path, array_replace($payload, ['quantity' => $invalid])), 422);
        }
        $this->json($this->submit($path, array_diff_key($payload, ['idempotency_key' => true])), 422);
        $this->json($this->submit($path, $payload));
        $this->assertTrue($this->json($this->submit($path, $payload))['replayed']);
        $this->json($this->submit('clinic/encounters/' . $this->encounterId . '/complete', ['disposition' => 'discharged']));
        $this->assertTrue($this->json($this->submit($path, $payload))['replayed']);
        $this->json($this->submit($path, array_replace($payload, ['idempotency_key' => 'after-close'])), 409);
        $this->json($this->submit('clinic/encounters/' . $this->encounterId . '/complete', ['disposition' => 'discharged']), 409);
        $this->assertBalanced(8);
    }
}
