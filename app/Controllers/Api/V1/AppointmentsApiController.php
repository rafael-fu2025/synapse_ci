<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Clinic\PatientHistoryService;
use App\Services\Scheduling\AppointmentService;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/** Personal appointment self-service: list, detail, request, cancel. */
final class AppointmentsApiController extends ApiV1Controller
{
    private const CLINIC_TYPES = ['routine', 'follow_up', 'referral_based'];
    private const GUIDANCE_TYPES = ['initial', 'follow_up', 'referral_based'];

    public function index(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $identity = $this->personalActor('portal.appointments.read');
            $filters = [
                'service' => $this->filter($this->request->getGet('service'), ['all', 'clinic', 'guidance'], 'all'),
                'status' => $this->filter($this->request->getGet('status'),
                    ['all', 'active', 'requested', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show'], 'all'),
                'sort' => $this->filter($this->request->getGet('sort'), ['upcoming', 'date_asc', 'date_desc'], 'upcoming'),
            ];
            $appointments = (new PatientHistoryService())->getPortalAppointments((int) $identity['person_id'], $filters);
            return $this->ok([
                'data' => array_map($this->present(...), $appointments),
                'meta' => ['count' => count($appointments), 'filters' => $filters],
            ]);
        });
    }

    public function show(int $id): ResponseInterface
    {
        return $this->run(function () use ($id): ResponseInterface {
            $identity = $this->personalActor('portal.appointments.read');
            $appointment = (new PatientHistoryService())->getPortalAppointment((int) $identity['person_id'], $id);
            if ($appointment === null) {
                return $this->fail(404, 'not_found', 'Appointment not found.');
            }
            return $this->ok(['data' => $this->present($appointment)]);
        });
    }

    public function create(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $identity = $this->identity();
            $payload = $this->jsonBody();

            // Field-level contract for the booking form; the service re-validates authoritatively.
            $fields = [];
            $unit = $payload['unit'] ?? null;
            if (!is_string($unit) || !in_array($unit, ['clinic', 'guidance'], true)) {
                $fields['unit'] = ['Choose the clinic or guidance department.'];
            }
            $date = $payload['date'] ?? null;
            if (!is_string($date) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1
                || \DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false
                || \DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date) {
                $fields['date'] = ['Use a valid appointment date (YYYY-MM-DD).'];
            }
            $time = $payload['preferred_time'] ?? null;
            if (!is_string($time) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time) !== 1) {
                $fields['preferred_time'] = ['Use a valid preferred time (HH:mm).'];
            }
            $type = $payload['type'] ?? null;
            $types = $unit === 'guidance' ? self::GUIDANCE_TYPES : self::CLINIC_TYPES;
            if (!is_string($type) || !in_array($type, $types, true)) {
                $fields['type'] = ['Choose a valid consultation type for this department.'];
            }
            $reason = $payload['reason'] ?? null;
            if ($reason !== null && (!is_string($reason) || mb_strlen(trim($reason)) > 255)) {
                $fields['reason'] = ['Reason must be at most 255 characters.'];
            }
            if ($fields !== []) {
                return $this->fail(422, 'validation_failed', 'Please review the highlighted fields.', $fields);
            }

            $appointment = (new AppointmentService())->requestAppointment($unit, [
                'date' => $date, 'preferred_time' => $time, 'type' => $type, 'reason' => $reason,
            ], $identity, true);
            return $this->ok(['data' => $this->present($appointment)], 201);
        });
    }

    public function cancel(int $id): ResponseInterface
    {
        return $this->run(function () use ($id): ResponseInterface {
            $identity = $this->identity();
            $payload = $this->jsonBody();
            $reason = $payload['reason'] ?? null;
            if (!is_string($reason) || trim($reason) === '' || mb_strlen(trim($reason)) > 255) {
                return $this->fail(422, 'validation_failed', 'A cancellation reason is required.',
                    ['reason' => ['Provide a reason between 1 and 255 characters.']]);
            }
            (new AppointmentService())->cancelAppointment($id, $reason, null, $identity, true);
            return $this->noContent();
        });
    }

    /** Personal authority is re-validated inside the services; this gate mirrors the web portal page. */
    private function personalActor(string $permission): array
    {
        $identity = $this->identity();
        if (empty($identity['person_id']) || !in_array($permission, $identity['permissions'] ?? [], true)) {
            throw new RuntimeException('Permission required: ' . $permission, 403);
        }
        return $identity;
    }

    private function filter(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array(strtolower(trim($value)), $allowed, true)
            ? strtolower(trim($value)) : $default;
    }

    private function present(array $appointment): array
    {
        return [
            'id' => (int) $appointment['id'],
            'unit' => (string) $appointment['unit'],
            'status' => (string) $appointment['status'],
            'type' => (string) $appointment['type'],
            'date' => (string) $appointment['local_date'],
            'starts_at' => (string) $appointment['starts_at'],
            'ends_at' => (string) $appointment['ends_at'],
            'reason' => $appointment['reason'] ?? null,
            'cancellation_reason' => $appointment['cancellation_reason'] ?? null,
            'provider_name' => $appointment['provider_name'] ?? null,
            'version' => (int) ($appointment['version'] ?? 1),
        ];
    }
}
