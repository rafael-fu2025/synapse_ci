<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Mis\MisClientInterface;
use App\Integrations\Mis\MisProfileMapper;
use App\Integrations\Mis\MisUnavailableException;
use App\Models\EmployeeProfileModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use Config\Services;

final class AuthService
{
    private MisClientInterface $misClient;
    private MisProfileMapper $mapper;
    private UserModel $userModel;
    private PersonModel $personModel;
    private StudentProfileModel $studentProfileModel;
    private EmployeeProfileModel $employeeProfileModel;

    public function __construct(
        ?MisClientInterface $misClient = null,
        ?MisProfileMapper $mapper = null,
        ?UserModel $userModel = null,
        ?PersonModel $personModel = null,
        ?StudentProfileModel $studentProfileModel = null,
        ?EmployeeProfileModel $employeeProfileModel = null
    ) {
        $this->misClient = $misClient ?? Services::misClient();
        $this->mapper = $mapper ?? new MisProfileMapper();
        $this->userModel = $userModel ?? new UserModel();
        $this->personModel = $personModel ?? new PersonModel();
        $this->studentProfileModel = $studentProfileModel ?? new StudentProfileModel();
        $this->employeeProfileModel = $employeeProfileModel ?? new EmployeeProfileModel();
    }

    /**
     * Authenticate user via local credentials or delegated MIS API and establish the web session.
     *
     * @return array<string, mixed>|null Returns session payload on success, null on failure.
     */
    public function login(string $identifier, string $password): ?array
    {
        $sessionData = $this->authenticate($identifier, $password);
        if ($sessionData === null) {
            return null;
        }
        return $this->establishUserSession($sessionData);
    }

    /**
     * Validate credentials without binding any session state; the web session and mobile API share this path.
     *
     * @return array<string, mixed>|null Returns identity payload on success, null on failure.
     */
    public function authenticate(string $identifier, string $password): ?array
    {
        $id = trim($identifier);
        if ($id === '' || mb_strlen($id) > 100 || preg_match('/[\x00-\x1F\x7F]/', $id) || $password === '') {
            return null;
        }

        // Local credentials remain available for deliberately provisioned break-glass accounts.
        $localUser = $this->userModel->withDeleted()->scoped()
            ->groupStart()->where('username', $id)->orWhere('email', $id)->groupEnd()->first();
        if ($localUser !== null && (new AuthenticatedUser())->forIdentity((int) $localUser['id'], CurrentTenant::id()) === null) {
            return null;
        }
        if ($localUser !== null && !empty($localUser['password_hash'])) {
            if (password_verify($password, $localUser['password_hash'])) {
                return $this->identityFor($localUser);
            }
            return null; // Local account found but password incorrect
        }

        // Only a credential rejection (null) permits trying the other MIS namespace.
        // Transport/contract failures propagate instead of being reported as a bad password.
        $studentRes = $this->misClient->studentLogin($id, $password);
        if ($studentRes !== null) {
            $profile = $this->mapper->mapStudent($studentRes);
            $this->assertReturnedIdentifier($id, $profile['mis_id']);
            return $this->provision($profile, 'student');
        }

        $employeeRes = $this->misClient->employeeLogin($id, $password);
        if ($employeeRes !== null) {
            $profile = $this->mapper->mapEmployee($employeeRes);
            $this->assertReturnedIdentifier($id, $profile['mis_id']);
            return $this->provision($profile, 'employee');
        }

        return null;
    }

    /** Identity payload with the same credential-version binding the session layer enforces. */
    private function identityFor(array $user): ?array
    {
        $identity = (new AuthenticatedUser())->forIdentity((int) $user['id'], CurrentTenant::id());
        if ($identity === null || (int) ($user['auth_version'] ?? 0) !== $identity['auth_version']) {
            return null;
        }
        return $identity;
    }

    /**
     * Provisions or refreshes a student record from MIS.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function provisionStudent(array $profile): array
    {
        $now = date('Y-m-d H:i:s');
        $person = $this->provisionablePerson($profile['mis_id'], 'student');
        $this->assertRequiredNames($person, $profile);

        $derivedEmail = UniversityEmail::derive($profile['first_name'] ?? null, $profile['last_name'] ?? null);

        $personData = $this->provided([
            'tenant_id'        => CurrentTenant::id(),
            'kind'             => 'student',
            'mis_id'           => $profile['mis_id'],
            'first_name'       => $profile['first_name'],
            'middle_name'      => $profile['middle_name'],
            'last_name'        => $profile['last_name'],
            'email'            => $derivedEmail,
            'mis_synced_at'    => $now,
            'mis_payload_hash' => $profile['raw_hash'],
        ]);

        if ($person === null) {
            $personId = (int) $this->personModel->insert($personData);
        } else {
            $personId = (int) $person['id'];
            $this->personModel->update($personId, $personData);
        }

        // Student Profile
        $studentProfile = $this->studentProfileModel->findByPersonId($personId);
        $spData = $this->provided([
            'tenant_id'   => CurrentTenant::id(),
            'person_id'   => $personId,
            'program'     => $profile['program'],
            'year_level'  => $profile['year_level'],
            'section'     => $profile['section'],
            'department'  => $profile['department'],
        ]);
        // Explicit unknowns revoke audience eligibility; omission (including older
        // mapper doubles without presence markers) must not overwrite known values.
        foreach (['enrollment_type', 'is_graduating'] as $field) {
            if (($profile[$field . '_provided'] ?? false) === true) {
                $spData[$field] = $profile[$field] ?? null;
            }
        }

        if ($studentProfile === null) {
            $this->studentProfileModel->insert($spData);
        } else {
            $this->studentProfileModel->update($studentProfile['id'], $spData);
        }

        // User Account
        $user = $this->userModel->scoped()->where('person_id', $personId)->first();
        if ($user === null) {
            $userId = (int) $this->userModel->insert([
                'tenant_id' => CurrentTenant::id(),
                'person_id' => $personId,
                'username'  => $profile['mis_id'],
                'email'     => $derivedEmail,
                'role'      => 'student',
                'status'    => 'active',
            ]);
            $user = $this->userModel->find($userId);
        }

        return $user;
    }

    /**
     * Provisions or refreshes an employee record from MIS.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function provisionEmployee(array $profile): array
    {
        $now = date('Y-m-d H:i:s');
        $person = $this->provisionablePerson($profile['mis_id'], 'employee');
        $this->assertRequiredNames($person, $profile);

        $derivedEmail = UniversityEmail::derive($profile['first_name'] ?? null, $profile['last_name'] ?? null);

        $personData = $this->provided([
            'tenant_id'        => CurrentTenant::id(),
            'kind'             => 'employee',
            'mis_id'           => $profile['mis_id'],
            'first_name'       => $profile['first_name'],
            'middle_name'      => $profile['middle_name'],
            'last_name'        => $profile['last_name'],
            'email'            => $derivedEmail,
            'mis_synced_at'    => $now,
            'mis_payload_hash' => $profile['raw_hash'],
        ]);

        if ($person === null) {
            $personId = (int) $this->personModel->insert($personData);
        } else {
            $personId = (int) $person['id'];
            $this->personModel->update($personId, $personData);
        }

        // Employee Profile
        $empProfile = $this->employeeProfileModel->findByPersonId($personId);
        $epData = $this->provided([
            'tenant_id'         => CurrentTenant::id(),
            'person_id'         => $personId,
            'department'        => $profile['department'],
            'position'          => $profile['position'],
            'employment_status' => $profile['employment_status'],
            'is_teaching'       => $profile['is_teaching'] === null ? null : ($profile['is_teaching'] ? 1 : 0),
        ]);

        if ($empProfile === null) {
            $this->employeeProfileModel->insert($epData);
        } else {
            $this->employeeProfileModel->update($empProfile['id'], $epData);
        }

        // User Account (preserve assigned privileged roles such as clinic_admin, counsellor, etc.)
        $user = $this->userModel->scoped()->where('person_id', $personId)->first();
        if ($user === null) {
            $userId = (int) $this->userModel->insert([
                'tenant_id' => CurrentTenant::id(),
                'person_id' => $personId,
                'username'  => $profile['mis_id'],
                'email'     => $derivedEmail,
                'role'      => 'employee',
                'status'    => 'active',
            ]);
            $user = $this->userModel->find($userId);
        }

        return $user;
    }

    private function provided(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }

    private function assertReturnedIdentifier(string $identifier, string $returned): void
    {
        if (mb_strlen($returned) > 50 || preg_match('/[\x00-\x1F\x7F]/', $returned)
            || !hash_equals($identifier, $returned)) {
            throw new MisUnavailableException();
        }
    }

    private function assertRequiredNames(?array $person, array $profile): void
    {
        if ($person === null && (empty($profile['first_name']) || empty($profile['last_name']))) {
            throw new MisUnavailableException();
        }
    }

    /** Reject blocked linked identities before changing any MIS-derived identity profile. */
    private function provisionablePerson(string $misId, string $kind): ?array
    {
        $person = $this->personModel->withDeleted()->scoped()->where('kind', $kind)->where('mis_id', $misId)->first();
        if ($person === null) {
            return null;
        }
        $user = $this->userModel->withDeleted()->scoped()->where('person_id', $person['id'])->first();
        if (!empty($person['deleted_at']) || ($user !== null
            && (new AuthenticatedUser())->forIdentity((int) $user['id'], CurrentTenant::id()) === null)) {
            throw new \DomainException('This account is not available for sign in.');
        }
        return $person;
    }

    /**
     * Provisions or refreshes the MIS identity transactionally and returns the validated identity payload.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>|null
     */
    private function provision(array $profile, string $kind): ?array
    {
        $db = \Config\Database::connect();
        if (!$db->transBegin()) {
            throw new \RuntimeException('Account provisioning transaction could not be started.');
        }
        try {
            $user = $kind === 'student' ? $this->provisionStudent($profile) : $this->provisionEmployee($profile);
            if ((new AuthenticatedUser())->forIdentity((int) $user['id'], CurrentTenant::id()) === null) {
                throw new \DomainException('Invalid provisioned identity.');
            }
            if (!$db->transStatus()) {
                throw new \RuntimeException('Account provisioning failed.');
            }
            if (!$db->transCommit()) {
                throw new \RuntimeException('Account provisioning could not be committed.');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            if ($e instanceof \DomainException) {
                return null;
            }
            throw $e;
        }
        return $this->identityFor($user);
    }

    /**
     * Initializes the user session from an already-validated identity.
     *
     * @param array<string, mixed> $sessionData
     * @return array<string, mixed>
     */
    private function establishUserSession(array $sessionData): array
    {
        $this->userModel->update($sessionData['id'], ['last_login_at' => date('Y-m-d H:i:s')]);

        AuthenticatedUser::clear();
        session()->remove(['_auth_notice', '_auth_expired']);
        session()->regenerate(true);
        session()->set('_auth', [
            'started_at' => time(), 'last_seen_at' => time(),
            'version' => $sessionData['auth_version'], 'session_id' => session_id(),
        ]);
        session()->set('user', $sessionData);
        session()->set('tenant_id', $sessionData['tenant_id']);
        session()->remove('_ci_old_input');
        CurrentTenant::set($sessionData['tenant_id']);

        return $sessionData;
    }

    /**
     * Clear active session.
     */
    public function logout(): void
    {
        AuthenticatedUser::clear();
        session()->remove(['_auth_notice', '_auth_expired']);
        session()->regenerate(true);
    }
}
