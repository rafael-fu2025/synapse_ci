<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\ProfilePhotoService;
use App\Services\ProfileAvatar;
use Config\Database;
use RuntimeException;
use Throwable;

final class ProfileSettingsController extends BaseController
{
    private ProfilePhotoService $photos;

    public function __construct(?ProfilePhotoService $photos = null)
    {
        $this->photos = $photos ?? new ProfilePhotoService();
    }

    private function owner(): array
    {
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) throw new RuntimeException('Please sign in to continue.', 401);
        $personId = (int) ($actor['person_id'] ?? 0);
        $tenantId = (int) ($actor['tenant_id'] ?? 0);
        if ($personId <= 0 || $tenantId <= 0) throw new RuntimeException('A linked student or employee profile is required.', 403);
        $person = Database::connect()->table('people')->where('id', $personId)->where('tenant_id', $tenantId)
            ->where('deleted_at', null)->get()->getRowArray();
        if ($person === null || !in_array($person['kind'], ['student', 'employee'], true)) {
            throw new RuntimeException('A linked student or employee profile is required.', 403);
        }
        return [$actor, $person];
    }

    public function index()
    {
        try {
            [$actor, $person] = $this->owner();
            $photos = $this->photos;
            $hasPhoto = $photos->exists($actor['tenant_id'], $actor['person_id']);
            $health = (new \App\Models\HealthProfileModel())->findByPersonId($actor['person_id']) ?? [];
            return view('portal/settings', [
                'title' => 'Profile & Settings', 'person' => $person, 'actor' => $actor,
                'cancelUrl' => site_url('portal'), 'formModalPage' => true, 'modalSize' => '',
                'hasPhoto' => $hasPhoto,
                'health' => $health,
                'defaultAvatar' => ProfileAvatar::dataUri($person),
                'photoUrl' => $hasPhoto ? site_url('portal/settings/photo') : ProfileAvatar::dataUri($person),
                'breadcrumbs' => [['label' => 'My Health Portal', 'url' => site_url('portal')], ['label' => 'Profile & Settings', 'url' => '']],
            ]);
        } catch (RuntimeException $error) {
            return AuthenticatedUser::denied($this->request, $error->getCode() !== 401, $error->getMessage(), '/portal');
        }
    }

    public function upload()
    {
        try {
            [$actor, $person] = $this->owner();
            $file = $this->request->getFile('photo');
            if ($file === null) throw new RuntimeException('Choose a JPEG, PNG, or WebP image up to 10 MB. If the upload was rejected, check the server upload limit.', 422);
            $this->photos->save($file, $actor['tenant_id'], $actor['person_id']);
            return $this->saved('Profile picture updated. Only the compressed image was stored.', true, $person);
        } catch (RuntimeException $error) {
            return $this->failure($error);
        } catch (Throwable $error) {
            log_message('error', 'Profile photo processing failed: {exception}', ['exception' => $error]);
            return $this->failure(new RuntimeException('The photo could not be processed. Your existing picture was kept.', 503));
        }
    }

    public function remove()
    {
        try {
            [$actor, $person] = $this->owner();
            $this->photos->remove($actor['tenant_id'], $actor['person_id']);
            return $this->saved('Profile picture removed.', false, $person);
        } catch (RuntimeException $error) { return $this->failure($error); }
    }

    public function photo()
    {
        try {
            [$actor, $person] = $this->owner();
            $path = $this->photos->path($actor['tenant_id'], $actor['person_id']);
            $bytes = is_file($path) ? @file_get_contents($path) : false;
            $type = 'image/webp';
            if ($bytes === false) {
                $bytes = ProfileAvatar::svg($person);
                $type = 'image/svg+xml';
            }
            return $this->response->setHeader('Content-Type', $type)
                ->setHeader('Cache-Control', 'private, no-store')->setHeader('X-Content-Type-Options', 'nosniff')
                ->setHeader('Cross-Origin-Resource-Policy', 'same-site')->setBody($bytes);
        } catch (RuntimeException $error) {
            return $this->response->setStatusCode($error->getCode() === 401 ? 401 : 403)
                ->setHeader('Cache-Control', 'private, no-store')->setBody('Photo unavailable.');
        }
    }

    public function updateEmergencyContact()
    {
        try {
            [$actor, $person] = $this->owner();
            $data = [
                'emergency_contact_name'         => $this->validateContactField('emergency_contact_name', 150),
                'emergency_contact_relationship' => $this->validateContactField('emergency_contact_relationship', 50),
                'emergency_contact_phone'        => $this->validateContactField('emergency_contact_phone', 50),
            ];

            (new \App\Services\Clinic\PatientHistoryService())->updateEmergencyContact((int) $actor['person_id'], $data);
            return $this->emergencyContactSaved('Emergency contact updated.', $data);
        } catch (RuntimeException $error) {
            return $this->failure($error);
        } catch (Throwable $error) {
            log_message('error', 'Emergency contact update failed: {exception}', ['exception' => $error]);
            return $this->failure(new RuntimeException('Unable to update emergency contact. Please try again.', 503));
        }
    }

    private function validateContactField(string $field, int $maxLength): ?string
    {
        $value = $this->request->getPost($field);
        if ($value === null) return null;
        if (!is_string($value)) {
            throw new RuntimeException('Invalid ' . str_replace('_', ' ', $field) . '.', 422);
        }
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException('Invalid text characters in ' . str_replace('_', ' ', $field) . '.', 422);
        }
        $trimmed = trim($value);
        if ($trimmed === '') return null;
        if (mb_strlen($trimmed, 'UTF-8') > $maxLength) {
            throw new RuntimeException(ucfirst(str_replace('_', ' ', $field)) . ' must be ' . $maxLength . ' characters or less.', 422);
        }
        return $trimmed;
    }

    private function emergencyContactSaved(string $message, array $contact)
    {
        if (!$this->request->isAJAX()) {
            return redirect()->to(site_url('portal/settings'))->with('success', $message);
        }
        return $this->response->setJSON([
            'success' => true,
            'message' => $message,
            'emergency_contact' => $contact,
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }

    private function saved(string $message, bool $hasPhoto, array $person)
    {
        if (!$this->request->isAJAX()) return redirect()->to(site_url('portal/settings'))->with('success', $message);
        return $this->response->setJSON([
            'success' => true, 'message' => $message, 'has_photo' => $hasPhoto,
            'photo_url' => $hasPhoto ? site_url('account/photo') . '?v=' . bin2hex(random_bytes(8)) : ProfileAvatar::dataUri($person),
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }

    private function failure(RuntimeException $error)
    {
        $status = in_array($error->getCode(), [401, 403, 413, 422, 503], true) ? $error->getCode() : 422;
        if ($status === 401 || $status === 403) {
            return AuthenticatedUser::denied($this->request, $status !== 401, $error->getMessage(), '/portal');
        }
        if ($this->request->isAJAX()) {
            return $this->response->setStatusCode($status)->setJSON(['success' => false, 'message' => $error->getMessage(),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
        }
        return redirect()->to(site_url('portal/settings'))->with('error', $error->getMessage());
    }
}
