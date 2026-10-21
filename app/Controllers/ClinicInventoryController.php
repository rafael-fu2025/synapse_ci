<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Clinic\InventoryService;
use App\Services\CurrentTenant;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ClinicInventoryController extends BaseController
{
    private InventoryService $inventory;
    private const BASE = '/clinic/inventory';

    public function __construct()
    {
        $this->inventory = new InventoryService();
    }

    public function index()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store')->setHeader('Vary', 'X-Requested-With');
        $fragment = $this->request->getGet('fragment') === '1';
        try {
            $this->authorize();
            $page = \App\Libraries\TablePage::fromQuery($this->request->getGet());
            $data = $this->inventory->paginateMedicines($page) + [
                'title' => 'Clinic Inventory', 'pageTitle' => 'Medicine Inventory',
                'canManage' => $this->canManage(), 'canWrite' => $this->canWrite(),
                'breadcrumbs' => $this->breadcrumbs(), 'baseUrl' => site_url(self::BASE),
            ];
            $body = $page->fragment
                ? '<div data-table-content>' . view('inventory/_results', $data) . '</div>'
                : view('inventory/index', $data);
            return $this->response->setBody($body);
        } catch (Throwable $e) {
            if (!$fragment && $e->getCode() === 503) {
                return $this->readError($e);
            }
            $status = $e instanceof InvalidArgumentException ? 422 : ($e->getCode() === 403 ? 403 : 503);
            if ($status === 503) {
                log_message('error', 'Inventory directory unavailable: {exception}', ['exception' => $e]);
            }
            $message = $status === 503
                ? ($e->getCode() === 503 ? InventoryService::SETUP_PENDING_MESSAGE : 'Inventory is temporarily unavailable.')
                : $e->getMessage();
            $this->response->setStatusCode($status);
            return $fragment || $this->request->isAJAX()
                ? $this->response->setJSON(['message' => $message])
                : $this->response->setBody(esc($message));
        }
    }

    public function show(int $id)
    {
        try {
            $this->authorize();
            $rawPage = $this->queryString('page', '1');
            if (!preg_match('/\A[1-9][0-9]{0,6}\z/D', $rawPage) || (int) $rawPage > 1000000) {
                throw new InvalidArgumentException('Invalid movement page.', 422);
            }
            $page = (int) $rawPage;
            $details = $this->inventory->details($id, $page);
            $hasMore = count($details['movements']) === $details['movement_page_size']
                && $page < 1000000 && $this->inventory->details($id, $page + 1)['movements'] !== [];
            return view('inventory/show', $details + [
                'title' => $details['medicine']['name'], 'pageTitle' => 'Medicine Details',
                'movementPage' => $page, 'hasMoreMovements' => $hasMore,
                'canManage' => $this->canManage(), 'canWrite' => $this->canWrite(),
                'breadcrumbs' => $this->breadcrumbs($details['medicine']),
            ]);
        } catch (Throwable $e) {
            return $this->readError($e);
        }
    }

    public function create()
    {
        return $this->catalogForm(null);
    }

    public function edit(int $id)
    {
        return $this->catalogForm($id);
    }

    private function catalogForm(?int $id)
    {
        try {
            $this->authorize(true, true);
            $medicine = $id === null ? null : $this->inventory->details($id)['medicine'];
            return $this->form('catalog', $id === null ? 'Add Medicine' : 'Edit Medicine',
                $id === null ? self::BASE . '/store' : self::BASE . '/' . $id . '/update',
                $id === null ? self::BASE : self::BASE . '/' . $id, ['medicine' => $medicine]);
        } catch (Throwable $e) {
            return $this->readError($e);
        }
    }

    public function store()
    {
        return $this->save(null);
    }

    public function update(int $id)
    {
        return $this->save($id);
    }

    private function save(?int $id)
    {
        $form = $id === null ? self::BASE . '/create' : self::BASE . '/' . $id . '/edit';
        try {
            $actor = $this->authorize(true, true);
            $key = $this->catalogKey($id === null);
            $medicine = $this->inventory->saveMedicine($id, $this->postFields([
                'name', 'generic_name', 'category', 'dosage_form', 'strength', 'unit_of_measure',
                'minimum_stock_level', 'is_active', 'version',
            ]), $actor, $key);
            return $this->respond(true, 'Medicine saved.', self::BASE . '/' . $medicine['id'], ['medicine' => $medicine]);
        } catch (Throwable $e) {
            return $this->mutationError($e, $form);
        }
    }

    public function receiveForm(int $id)
    {
        try {
            $this->authorize(true);
            $details = $this->inventory->details($id);
            return $this->form('receive', 'Receive Medicine', self::BASE . '/' . $id . '/receive',
                self::BASE . '/' . $id, ['medicine' => $details['medicine']]);
        } catch (Throwable $e) {
            return $this->readError($e);
        }
    }

    public function receive(int $id)
    {
        $form = self::BASE . '/' . $id . '/receive';
        try {
            $actor = $this->authorize(true);
            $this->inventory->details($id);
            $result = $this->inventory->receive($id, $this->postFields([
                'batch_number', 'expiration_date', 'quantity', 'supplier', 'reference',
            ]), $actor, $this->postString('idempotency_key'));
            return $this->respond(true, 'Medicine received.', self::BASE . '/' . $id, $result);
        } catch (Throwable $e) {
            return $this->mutationError($e, $form);
        }
    }

    public function adjustForm(int $id)
    {
        return $this->batchForm($id, 'adjust', 'Remove Stock');
    }

    public function statusForm(int $id)
    {
        return $this->batchForm($id, 'status', 'Change Batch Status');
    }

    private function batchForm(int $id, string $kind, string $title)
    {
        try {
            $this->authorize(true, true);
            $context = $this->batchContext($id);
            return $this->form($kind, $title, self::BASE . '/batches/' . $id . '/' . $kind,
                self::BASE . '/' . $context['medicine']['id'], $context);
        } catch (Throwable $e) {
            return $this->readError($e);
        }
    }

    public function adjust(int $id)
    {
        $form = self::BASE . '/batches/' . $id . '/adjust';
        try {
            $actor = $this->authorize(true, true);
            $context = $this->batchContext($id);
            $result = $this->inventory->adjust($id, $this->postFields(['quantity', 'reason', 'note']),
                $actor, $this->postString('idempotency_key'));
            return $this->respond(true, 'Stock removed.', self::BASE . '/' . $context['medicine']['id'], $result);
        } catch (Throwable $e) {
            return $this->mutationError($e, $form);
        }
    }

    public function status(int $id)
    {
        $form = self::BASE . '/batches/' . $id . '/status';
        try {
            $actor = $this->authorize(true, true);
            $context = $this->batchContext($id);
            $result = $this->inventory->setBatchStatus($id, $this->postString('status'),
                $this->postString('reason'), $actor, $this->postString('idempotency_key'));
            return $this->respond(true, 'Batch status updated.', self::BASE . '/' . $context['medicine']['id'], $result);
        } catch (Throwable $e) {
            return $this->mutationError($e, $form);
        }
    }

    public function reverseForm(int $id)
    {
        try {
            $this->authorize(true, true);
            $context = $this->operationContext($id);
            return $this->form('reverse', 'Correct Dispensation', self::BASE . '/operations/' . $id . '/reverse',
                self::BASE . '/' . $context['medicine']['id'], $context);
        } catch (Throwable $e) {
            return $this->readError($e);
        }
    }

    public function reverse(int $id)
    {
        $form = self::BASE . '/operations/' . $id . '/reverse';
        try {
            $actor = $this->authorize(true, true);
            $context = $this->operationContext($id);
            if ($this->request->getPost('review_confirmed') !== '1') {
                throw new InvalidArgumentException('Confirm that you reviewed the full dispensation before correcting it.', 422);
            }
            $result = $this->inventory->reverse($id, $this->postString('mode'),
                $this->postString('reason'), $actor, $this->postString('idempotency_key'));
            return $this->respond(true, 'Dispensation corrected.', self::BASE . '/' . $context['medicine']['id'], $result);
        } catch (Throwable $e) {
            return $this->mutationError($e, $form);
        }
    }

    private function batchContext(int $id): array
    {
        $batch = Database::connect()->table('clinic_medicine_batches')->where('tenant_id', CurrentTenant::id())
            ->where('id', $id)->get()->getRowArray();
        if ($batch === null) {
            throw new RuntimeException('Batch not found.', 404);
        }
        $details = $this->inventory->details((int) $batch['medicine_id']);
        return ['medicine' => $details['medicine'], 'batch' => $batch];
    }

    private function operationContext(int $id): array
    {
        $operation = Database::connect()->table('inventory_operations')->where('tenant_id', CurrentTenant::id())
            ->where('id', $id)->get()->getRowArray();
        if ($operation === null) {
            throw new RuntimeException('Operation not found.', 404);
        }
        $details = $this->inventory->details((int) $operation['medicine_id']);
        if ($operation['type'] !== 'dispense') {
            throw new InvalidArgumentException('Only a complete ledger dispensation can be reversed.', 422);
        }
        return ['medicine' => $details['medicine'], 'operation' => $operation];
    }

    private function authorize(bool $write = false, bool $manager = false): int
    {
        $user = session()->get('user');
        $actor = is_array($user) ? ($user['id'] ?? null) : null;
        if ((!is_int($actor) && !is_string($actor)) || !preg_match('/\A[1-9][0-9]*\z/D', (string) $actor)
            || filter_var($actor, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException('Active inventory account required.', 403);
        }
        $this->inventory->assertAccess((int) $actor, $write, $manager);
        $this->inventory->assertInstalled();
        return (int) $actor;
    }

    private function canWrite(): bool
    {
        try {
            $this->authorize(true);
            return true;
        } catch (RuntimeException $e) {
            if ($e->getCode() !== 403) {
                throw $e;
            }
            return false;
        }
    }

    private function canManage(): bool
    {
        try {
            $this->authorize(true, true);
            return true;
        } catch (RuntimeException $e) {
            if ($e->getCode() !== 403) {
                throw $e;
            }
            return false;
        }
    }

    private function breadcrumbs(?array $medicine = null, ?string $action = null): array
    {
        $crumbs = [
            ['label' => 'Clinic', 'url' => site_url('/clinic/encounters')],
            ['label' => 'Medicine Inventory', 'url' => $medicine !== null || $action !== null ? site_url(self::BASE) : ''],
        ];
        if ($medicine !== null) {
            $crumbs[] = ['label' => $medicine['name'], 'url' => $action !== null ? site_url(self::BASE . '/' . $medicine['id']) : ''];
        }
        if ($action !== null) {
            $crumbs[] = ['label' => $action, 'url' => ''];
        }
        return $crumbs;
    }

    private function form(string $view, string $title, string $action, string $cancel, array $data)
    {
        $key = old('idempotency_key', null, false);
        if (!is_string($key) || !preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key)) {
            $key = bin2hex(random_bytes(32));
        }
        return view('inventory/' . $view, $data + [
            'title' => $title, 'pageTitle' => $title, 'formAction' => site_url($action),
            'cancelUrl' => site_url($cancel), 'formModalPage' => true, 'idempotencyKey' => $key,
                        'breadcrumbs' => $this->breadcrumbs($data['medicine'] ?? null, $title),
        ]);
    }

    private function postFields(array $fields): array
    {
        $data = [];
        $post = $this->request->getPost();
        foreach ($fields as $field) {
            if (array_key_exists($field, $post)) {
                $data[$field] = $post[$field];
            }
        }
        return $data;
    }

    private function catalogKey(bool $required): ?string
    {
        $key = $this->request->getPost('idempotency_key');
        if ($key === null && !$required) {
            return null;
        }
        if (!is_string($key) || !preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key)) {
            throw new InvalidArgumentException('A 1–128 character ASCII idempotency key is required.', 422);
        }
        return $key;
    }

    private function postString(string $field): string
    {
        $value = $this->request->getPost($field);
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException('Invalid ' . $field . '.', 422);
        }
        return $value ?? '';
    }

    private function queryString(string $field, string $default): string
    {
        $value = $this->request->getGet($field);
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException('Invalid ' . $field . '.', 422);
        }
        return $value ?? $default;
    }

    private function errorStatus(Throwable $e): int
    {
        return in_array($e->getCode(), [403, 404, 409, 422, 503], true) ? $e->getCode()
            : ($e instanceof InvalidArgumentException ? 422 : 500);
    }

    private function readError(Throwable $e)
    {
        $status = $this->errorStatus($e);
        if ($status === 500) {
            log_message('error', 'Inventory request failed: {exception}', ['exception' => $e]);
        }
        if ($status === 503) {
            return $this->respond(false, InventoryService::SETUP_PENDING_MESSAGE, self::BASE, [], 503);
        }
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
            ->setBody(esc($status === 500 ? 'Inventory request failed.' : $e->getMessage()));
    }

    private function mutationError(Throwable $e, string $form)
    {
        $status = $this->errorStatus($e);
        if ($status === 500) {
            log_message('error', 'Inventory mutation failed: {exception}', ['exception' => $e]);
        }
        return $this->respond(false, $status === 500 ? 'Inventory request failed.' : $e->getMessage(), $form, [], $status);
    }

    private function respond(bool $success, string $message, string $redirect, array $data = [], int $status = 422)
    {
        if ($this->request->isAJAX()) {
            if ($success) {
                session()->setFlashdata('success', $message);
            }
            return $this->response->setStatusCode($success ? 200 : $status)->setHeader('Cache-Control', 'no-store')->setJSON([
                'success' => $success, 'message' => $message, 'redirect' => site_url($redirect),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ] + $data);
        }
        if (!$success && $status === 503) {
            return $this->response->setStatusCode(503)->setHeader('Cache-Control', 'no-store')
                ->setBody(view('inventory/unavailable', [
                    'title' => 'Clinic Inventory', 'pageTitle' => 'Medicine Inventory',
                    'message' => InventoryService::SETUP_PENDING_MESSAGE,
                    'breadcrumbs' => $this->breadcrumbs(),
                ]));
        }
        return $success ? redirect()->to($redirect)->with('success', $message)
            : redirect()->to($redirect)->withInput()->with('error', $message);
    }
}
