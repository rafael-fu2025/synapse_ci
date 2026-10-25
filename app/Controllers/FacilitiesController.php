<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\Facilities\BmgRotationService;
use App\Services\Facilities\BmgService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class FacilitiesController extends BaseController
{
    private BmgRotationService $rotation;
    private BmgService $batches;

    public function __construct()
    {
        $this->rotation = new BmgRotationService();
        $this->batches = new BmgService();
    }

    public function dashboard()
    {
        return $this->respond(function () {
            $tenantId = \App\Services\CurrentTenant::id();
            $db = \Config\Database::connect();

            $overview = $this->rotation->overview();
            $totalUnits = count($overview);
            $activeUnits = count(array_filter($overview, static fn (array $i): bool => $i['unit']['status'] === 'active'));
            $maintenanceUnits = count(array_filter($overview, static fn (array $i): bool => $i['unit']['status'] === 'maintenance'));
            $boundDevices = count(array_filter($overview, static fn (array $i): bool => $i['device'] !== null));

            $totalBatches = $db->table('bmg_batches')
                ->where('tenant_id', $tenantId)->countAllResults();
            $activeBatches = $db->table('bmg_batches')
                ->where('tenant_id', $tenantId)->whereIn('status', ['processing', 'awaiting_output', 'curing'])->countAllResults();

            $totalWeightRow = $db->table('bmg_batches')
                ->where('tenant_id', $tenantId)->where('status', 'released')
                ->selectSum('total_input_weight_kg', 'total_kg')->get()->getRowArray();
            $totalTreatedKg = (float) ($totalWeightRow['total_kg'] ?? 0);

            $days = [];
            $batchRuns = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $days[] = date('M j', strtotime($d));
                $batchRuns[] = $db->table('bmg_batches')
                    ->where('tenant_id', $tenantId)->where('DATE(created_at)', $d)->countAllResults();
            }

            $user = session()->get('user') ?? [];

            return view('facilities/dashboard', $this->access() + [
                'title'             => 'Facilities Dashboard',
                'pageTitle'         => 'Facilities & BMG Dashboard',
                'user'              => $user,
                'totalUnits'        => $totalUnits,
                'activeUnits'       => $activeUnits,
                'maintenanceUnits'  => $maintenanceUnits,
                'boundDevices'      => $boundDevices,
                'totalBatches'      => $totalBatches,
                'activeBatches'     => $activeBatches,
                'totalTreatedKg'    => $totalTreatedKg,
                'units'             => $overview,
                'chartDays'         => $days,
                'chartSeries'       => $batchRuns,
                'breadcrumbs'       => [
                    ['label' => 'Facilities', 'url' => site_url('/facilities/dashboard')],
                    ['label' => 'Dashboard', 'url' => ''],
                ],
            ]);
        });
    }

    public function index()
    {
        return $this->respond(function () {
            $archived = $this->request->getGet('view') === 'archived';
            return view('facilities/index', $this->access() + [
                'units' => $this->rotation->overview($archived), 'title' => 'Drum Rotation',
                'pageTitle' => 'Drum Rotation', 'archivedView' => $archived,
                'openCreate' => !$archived && $this->request->getGet('create') === '1',
            ]);
        });
    }

    public function unitView(int $id)
    {
        return $this->respond(fn () => view('facilities/unit_detail', $this->rotation->unit($id)
            + $this->batches->batchOverview($id) + $this->access()
            + ['title' => 'Drum Operations', 'pageTitle' => 'Drum Operations']));
    }

    public function storeUnit()
    {
        return $this->respond(function () {
            $result = $this->rotation->saveUnit(null, $this->input(['unit_code', 'name', 'status', 'drum_capacity_kg']));
            return $this->success('Drum created. Automated operation remains disabled.', (int) $result['unit']['id']);
        });
    }

    public function updateUnit(int $id)
    {
        return $this->respond(function () use ($id) {
            $this->rotation->saveUnit($id, $this->input(['unit_code', 'name', 'status', 'drum_capacity_kg', 'expected_version']));
            return $this->success('Drum settings saved. Availability does not confirm physical motor state.', $id);
        });
    }

    public function archiveUnit(int $id)
    {
        return $this->respond(function () use ($id) {
            $this->rotation->archiveUnit($id, $this->input(['expected_version', 'retirement_confirmed']));
            return $this->success('Drum archived. History is retained, controller credentials revoked and saved schedule intent disabled. This does not confirm a physical motor stop.', 0);
        });
    }

    public function restoreUnit(int $id)
    {
        return $this->respond(function () use ($id) {
            $this->rotation->restoreUnit($id, $this->input(['expected_version']));
            return $this->success('Drum restored offline. Review its setup and register a controller again before operation.', $id);
        });
    }

    public function saveSchedule(int $id)
    {
        return $this->respond(function () use ($id) {
            $data = $this->input(['times', 'duration_seconds', 'pwm', 'enabled', 'expected_version']);
            if (isset($data['times']) && !is_array($data['times'])) { throw new InvalidArgumentException('Schedule times must be a list.'); }
            $data['times'] = array_values(array_filter($data['times'] ?? [], static fn ($time): bool => $time !== ''));
            $data['enabled'] ??= false;
                        if (($data['pwm'] ?? null) === '') { $data['pwm'] = null; }
            $this->rotation->saveSchedule($id, $data);
            return $this->success('Schedule saved. Controller application must be confirmed separately.', $id);
        });
    }

    public function registerDevice(int $id)
    {
        return $this->respond(function () use ($id) {
            $token = $this->rotation->registerDevice($id, $this->input(['chip_mac', 'device_id', 'expected_version']));
            // Never redirect or flash credentials: this no-store response is their only disclosure.
            $this->response->setHeader('Referrer-Policy', 'no-referrer')->setHeader('X-Robots-Tag', 'noindex, nofollow');
            return view('facilities/device_token', ['tokenResult' => $token, 'title' => 'Controller Credential']);
        });
    }

    public function revokeDevice(int $id)
    {
        return $this->respond(function () use ($id) {
            $this->rotation->revokeDevice($id, $this->request->getPost('expected_version'));
            return $this->success('Controller credential revoked. This is not proof that its motor has stopped.', 0);
        });
    }

    public function command(int $id)
    {
        return $this->respond(function () use ($id) {
            $result = $this->rotation->command($id, $this->input(['command', 'duration_seconds', 'expected_version']));
            return $this->success('Command #' . $result['command_id'] . ' queued; motor execution is not yet confirmed.', $id);
        });
    }

    public function startBatch(int $id)
    {
        return $this->respond(function () use ($id) {
            $this->batches->createBatch($id, $this->request->getPost('input_weight_kg'), $this->optionalText('notes'));
            return $this->success('Batch started. Recording a batch does not start the motor.', $id);
        });
    }

    public function releaseBatch(int $id)
    {
        return $this->respond(function () use ($id) {
            $batch = $this->batches->releaseBatch($id, $this->request->getPost('output_weight_kg'), 'unclassified', $this->optionalText('release_notes'));
            return $this->success('Batch completed. This records bookkeeping only, not compost certification or a motor stop.', (int) $batch['unit_id']);
        });
    }

    public function cancelBatch(int $id)
    {
        return $this->respond(function () use ($id) {
            $batch = $this->batches->cancelBatch($id, $this->optionalText('reason') ?? '');
            return $this->success('Batch cancelled. Check the controller separately before working on the drum.', (int) $batch['unit_id']);
        });
    }

    private function access(): array
    {
        $actor = (new AuthenticatedUser())->current();
        $permissions = $actor['permissions'] ?? [];
        return [
            'canManage' => in_array('facilities.manage', $permissions, true),
            'canConfigure' => in_array('facilities.configure', $permissions, true) && in_array('facilities.manage', $permissions, true),
            'canUsb' => array_diff(['facilities.read', 'facilities.manage', 'facilities.configure'], $permissions) === [],
                        'canHardware' => in_array('facilities.hardware.manage', $permissions, true),
            'hardwareEnabled' => in_array(env('BMG_HARDWARE_ENABLED', false), [true, 'true'], true),
        ];
    }

    private function input(array $keys): array
    {
        $post = $this->request->getPost();
        return array_intersect_key(is_array($post) ? $post : [], array_flip($keys));
    }

    private function optionalText(string $key): ?string
    {
        $value = $this->request->getPost($key);
        if ($value !== null && !is_string($value)) { throw new InvalidArgumentException('Invalid ' . $key . '.'); }
        return $value;
    }

    private function success(string $message, int $unit)
    {
        $target = site_url($unit > 0 ? '/facilities/unit/' . $unit : '/facilities');
        if ($this->json()) {
            return $this->response->setJSON(['success' => true, 'message' => $message, 'redirect' => $target,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
        }
        return redirect()->to($target)->with('success', $message)->setHeader('Cache-Control', 'private, no-store');
    }

    private function respond(callable $operation)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try { return $operation(); }
        catch (Throwable $error) {
            $status = $error instanceof InvalidArgumentException ? 422
                : ($error instanceof RuntimeException && in_array($error->getCode(), [401, 403, 404, 409, 422], true) ? $error->getCode() : 503);
            if ($status === 503) { log_message('error', 'BMG operation failed: {exception}', ['exception' => $error]); }
            $message = $status === 503 ? 'Drum operations are temporarily unavailable. Please retry or contact support.' : $error->getMessage();
            $this->response->setStatusCode($status);
            return $this->json() ? $this->response->setJSON(['success' => false, 'message' => $message,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]])
                : $this->response->setContentType('text/plain')->setBody($message);
        }
    }

    private function json(): bool
    {
        return $this->request->isAJAX() || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'json');
    }
}
