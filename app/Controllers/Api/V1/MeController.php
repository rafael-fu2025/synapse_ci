<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Clinic\PatientHistoryService;
use App\Services\ProfileAvatar;
use App\Services\ProfilePhotoService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use RuntimeException;

/** Session identity and client entitlements; the app hides UI by these flags but the server still decides. */
final class MeController extends ApiV1Controller
{
    private const STAFF_QUEUE_PERMISSIONS = ['clinic' => 'clinic.queue.read', 'guidance' => 'guidance.queue.read'];

    public function show(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $identity = $this->identity();
            $permissions = is_array($identity['permissions'] ?? null) ? $identity['permissions'] : [];
            $staffUnits = [];
            foreach (self::STAFF_QUEUE_PERMISSIONS as $unit => $permission) {
                if (in_array($permission, $permissions, true)) {
                    $staffUnits[] = $unit;
                }
            }
            return $this->ok([
                'user' => $this->presentUser($identity),
                'entitlements' => [
                    'portal' => true,
                    'staff_queue_units' => $staffUnits,
                ],
                'photo_url' => 'api/v1/me/photo',
            ]);
        });
    }

    /**
     * Lean, privacy-minimal health summary: identity and health profile only.
     * Clinical encounter detail is workstation data and stays off the mobile API.
     */
    public function healthSummary(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $identity = $this->identity();
            $personId = (int) ($identity['person_id'] ?? 0);
            if ($personId <= 0 || !in_array('portal.records.read', $identity['permissions'] ?? [], true)) {
                throw new RuntimeException('Permission required: portal.records.read', 403);
            }
            $chart = (new PatientHistoryService())->getPatientChart($personId, includeAppointments: false);
            $person = $chart['person'];
            $health = $chart['health_profile'];
            $student = $chart['student_profile'];
            $employee = $chart['employee_profile'];

            return $this->ok(['data' => [
                'person' => [
                    'name' => trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')),
                    'kind' => $person['kind'] ?? null,
                    'identifier' => $person['mis_id'] ?? null,
                    'program' => $student['program'] ?? null,
                    'year_level' => $student['year_level'] ?? null,
                    'department' => $student['department'] ?? $employee['department'] ?? null,
                    'position' => $employee['position'] ?? null,
                ],
                'health' => [
                    'blood_type' => $health['blood_type'] ?? null,
                    'allergies' => $health['allergies'] ?? null,
                    'chronic_conditions' => $health['chronic_conditions'] ?? null,
                    'emergency_contact_name' => $health['emergency_contact_name'] ?? null,
                    'emergency_contact_phone' => $health['emergency_contact_phone'] ?? null,
                    'emergency_contact_relationship' => $health['emergency_contact_relationship'] ?? null,
                ],
            ]]);
        });
    }

    public function photo(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            [$tenantId, $person] = $this->photoOwner();
            $photos = new ProfilePhotoService();
            if ($photos->exists($tenantId, (int) $person['id'])) {
                $bytes = @file_get_contents($photos->path($tenantId, (int) $person['id']));
                if ($bytes !== false) {
                    return $this->response->setContentType('image/webp')
                        ->setHeader('Cache-Control', 'private, no-store')->setBody($bytes);
                }
            }
            return $this->response->setContentType('image/svg+xml')
                ->setHeader('Cache-Control', 'private, no-store')->setBody(ProfileAvatar::svg($person));
        });
    }

    public function uploadPhoto(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            [$tenantId, $person] = $this->photoOwner();
            $file = $this->request->getFile('photo');
            if ($file === null) {
                return $this->fail(422, 'validation_failed', 'Choose a JPEG, PNG, or WebP image up to 10 MB.',
                    ['photo' => ['Choose a JPEG, PNG, or WebP image up to 10 MB.']]);
            }
            (new ProfilePhotoService())->save($file, $tenantId, (int) $person['id']);
            return $this->ok(['data' => ['has_photo' => true, 'photo_url' => 'api/v1/me/photo']]);
        });
    }

    public function deletePhoto(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            [$tenantId, $person] = $this->photoOwner();
            (new ProfilePhotoService())->remove($tenantId, (int) $person['id']);
            return $this->noContent();
        });
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function photoOwner(): array
    {
        $identity = $this->identity();
        $personId = (int) ($identity['person_id'] ?? 0);
        $tenantId = (int) ($identity['tenant_id'] ?? 0);
        $person = null;
        if ($personId > 0 && $tenantId > 0) {
            $person = Database::connect()->table('people')->where('id', $personId)->where('tenant_id', $tenantId)
                ->where('deleted_at', null)->get()->getRowArray();
        }
        if ($person === null || !in_array($person['kind'], ['student', 'employee'], true)) {
            throw new RuntimeException('A linked student or employee profile is required.', 403);
        }
        return [$tenantId, $person];
    }
}
