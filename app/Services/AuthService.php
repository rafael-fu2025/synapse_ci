<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Mis\MisAccountException;
use App\Integrations\Mis\MisClientInterface;
use App\Integrations\Mis\MisInactiveAccountException;
use App\Integrations\Mis\MisProfileMapper;
use App\Integrations\Mis\MisUnavailableException;
use App\Models\EmployeeProfileModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use Config\Services;
use Throwable;

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
    public function login(string $identifier, string $password, bool $allowStudentMis = true): ?array
    {
        $sessionData = $this->authenticate($identifier, $password, $allowStudentMis);
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
    public function authenticate(string $identifier, string $password, bool $allowStudentMis = true): ?array
    {
        $id = trim($identifier);
        if ($id === '' || mb_strlen($id) > 100 || preg_match('/[\x00-\x1F\x7F]/', $id) || $password === '') {
            return null;
        }

        // Local credentials remain available for deliberately provisioned break-glass accounts.
        ['user' => $localUser, 'ambiguousEmail' => $ambiguousEmail] = $this->resolveLocalUser($id);
        if ($ambiguousEmail) {
            return null;
        }
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
        // Staff-only hosts skip the student namespace; on shared hosts, uniquely synced employees
        // authenticate directly against the employee endpoint first.
        $misLookupId = $this->resolveMisLookupId($id, $localUser);
        if ($allowStudentMis) {
            $namespaces = $this->shouldPreferEmployeeNamespace($misLookupId) ? ['employee', 'student'] : ['student', 'employee'];
        } else {
            // Staff hosts only allow student MIS authentication for students with an active,
            // in-window internship placement in that exact workstation unit.
            $namespaces = $this->isActiveInternForCurrentHost($localUser, $misLookupId) ? ['student'] : ['employee'];
        }

        foreach ($namespaces as $namespace) {
            ['matched' => $matched, 'identity' => $identity] = $namespace === 'student'
                ? $this->authenticateStudentMis($misLookupId, $password)
                : $this->authenticateEmployeeMis($misLookupId, $password);
            if ($matched) {
                return $identity;
            }
        }

        return null;
    }

    /**
     * Resolves a local user unambiguously by exact username first, or by unique tenant email.
     *
     * @return array{user: ?array<string, mixed>, ambiguousEmail: bool}
     */
    private function resolveLocalUser(string $id): array
    {
        $byUsername = $this->userModel->withDeleted()->scoped()
            ->where('username', $id)
            ->first();
        if ($byUsername !== null) {
            return ['user' => $byUsername, 'ambiguousEmail' => false];
        }

        if (! str_contains($id, '@')) {
            return ['user' => null, 'ambiguousEmail' => false];
        }

        $byEmail = $this->userModel->withDeleted()->scoped()
            ->where('LOWER(email)', mb_strtolower($id, 'UTF-8'))
            ->findAll(2);
        if (count($byEmail) === 1) {
            return ['user' => $byEmail[0], 'ambiguousEmail' => false];
        }

        return ['user' => null, 'ambiguousEmail' => count($byEmail) > 1];
    }

    /**
     * True when active local directory records uniquely identify this MIS ID as an employee.
     */
    private function shouldPreferEmployeeNamespace(string $misLookupId): bool
    {
        $rows = $this->personModel->scoped()
            ->select('kind')
            ->where('mis_id', $misLookupId)
            ->whereIn('kind', ['student', 'employee'])
            ->findAll(2);
        $kinds = array_values(array_unique(array_column($rows, 'kind')));

        return $kinds === ['employee'];
    }

    /**
     * True only when the identifier resolves to a student with an active, in-window internship
     * placement for the active staff portal host. Ensures non-intern students still skip studentLogin() on staff hosts.
     */
    private function isActiveInternForCurrentHost(?array $localUser, string $misLookupId): bool
    {
        $hostPortal = PortalContext::hostPortal();
        if ($hostPortal === null || !in_array($hostPortal, [PortalContext::PORTAL_CLINIC, PortalContext::PORTAL_GUIDANCE, PortalContext::PORTAL_BMG], true)) {
            return false;
        }

        $db = \Config\Database::connect();
        if (!$db->tableExists('student_intern_placements')) {
            return false;
        }

        $tenantId = CurrentTenant::id();
        $userId = null;
        if ($localUser !== null && !empty($localUser['id']) && !empty($localUser['person_id'])) {
            $person = $this->personModel->scoped($tenantId)->where('id', (int) $localUser['person_id'])->first();
            if ($person !== null && ($person['kind'] ?? null) === 'student') {
                $userId = (int) $localUser['id'];
            }
        } else {
            $studentPerson = $this->personModel->scoped($tenantId)
                ->where('kind', 'student')
                ->where('mis_id', $misLookupId)
                ->first();
            if ($studentPerson !== null) {
                $userRow = $this->userModel->scoped($tenantId)
                    ->where('person_id', (int) $studentPerson['id'])
                    ->where('status', 'active')
                    ->first();
                if ($userRow !== null) {
                    $userId = (int) $userRow['id'];
                }
            }
        }

        if ($userId === null) {
            return false;
        }

        $internService = new StudentInternService($db);
        $internService->reconcileForUser($userId, $tenantId);
        $placement = $internService->activePlacementForUser($userId, $tenantId);

        return $placement !== null && ($placement['unit'] ?? null) === $hostPortal;
    }

    /** @return array{matched: bool, identity: ?array<string, mixed>} */
    private function authenticateStudentMis(string $misLookupId, string $password): array
    {
        $studentRes = $this->misClient->studentLogin($misLookupId, $password);
        if ($studentRes === null) {
            return ['matched' => false, 'identity' => null];
        }
        $profile = $this->mapLoginProfile($studentRes, 'student');
        if (! $this->returnedIdentifierMatches($misLookupId, $profile['mis_id'], 'student')) {
            // The upstream answered for a different identity than the one entered. Fail closed
            // as a credential rejection without falling through to the sibling namespace.
            return ['matched' => true, 'identity' => null];
        }
        return [
            'matched'  => true,
            'identity' => $this->provision($profile, 'student', $this->mapper->extractEmail($studentRes)),
        ];
    }

    /** @return array{matched: bool, identity: ?array<string, mixed>} */
    private function authenticateEmployeeMis(string $misLookupId, string $password): array
    {
        try {
            $employeeRes = $this->misClient->employeeLogin($misLookupId, $password);
        } catch (MisAccountException $e) {
            // The upstream could not resolve the account. The employee directory lists the
            // accounts MIS can authenticate, so an absent identifier means the account is not
            // active in MIS; a listed one means the upstream failed for another reason.
            if ($this->isListedEmployee($misLookupId) === false) {
                throw new MisInactiveAccountException();
            }
            throw $e;
        }
        if ($employeeRes === null) {
            return ['matched' => false, 'identity' => null];
        }
        $profile = $this->mapLoginProfile($employeeRes, 'employee');
        if (! $this->returnedIdentifierMatches($misLookupId, $profile['mis_id'], 'employee')) {
            return ['matched' => true, 'identity' => null];
        }
        return [
            'matched'  => true,
            'identity' => $this->provision($profile, 'employee', $this->mapper->extractEmail($employeeRes)),
        ];
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
    private function provisionStudent(array $profile, ?string $misEmail = null): array
    {
        [$personId, $resolvedEmail] = $this->upsertMisPerson($profile, 'student', $misEmail);

        $studentProfile = $this->studentProfileModel->findByPersonId($personId);
        $spData = $this->provided([
            'tenant_id'   => CurrentTenant::id(),
            'person_id'   => $personId,
            'program'     => $this->clip($profile['program'], 100),
            'year_level'  => $profile['year_level'],
            'section'     => $this->clip($profile['section'], 50),
            'department'  => $this->clip($profile['department'], 100),
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

        return $this->provisionUserAccount($personId, $profile['mis_id'], $resolvedEmail, 'student');
    }

    /**
     * Provisions or refreshes an employee record from MIS.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function provisionEmployee(array $profile, ?string $misEmail = null): array
    {
        [$personId, $resolvedEmail] = $this->upsertMisPerson($profile, 'employee', $misEmail);

        $empProfile = $this->employeeProfileModel->findByPersonId($personId);
        $epData = $this->provided([
            'tenant_id'         => CurrentTenant::id(),
            'person_id'         => $personId,
            'department'        => $this->clip($profile['department'], 100),
            'position'          => $this->clip($profile['position'], 100),
            'employment_status' => $profile['employment_status'],
            'is_teaching'       => $profile['is_teaching'] === null ? null : ($profile['is_teaching'] ? 1 : 0),
        ]);

        if ($empProfile === null) {
            $this->employeeProfileModel->insert($epData);
        } else {
            $this->employeeProfileModel->update($empProfile['id'], $epData);
        }

        // User Account (preserve assigned privileged roles such as clinic_admin, counsellor, etc.)
        return $this->provisionUserAccount($personId, $profile['mis_id'], $resolvedEmail, 'employee');
    }

    /**
     * Inserts or refreshes the canonical MIS person record and returns [personId, authoritativeEmail].
     *
     * Derived UniversityEmail addresses remain display-only (computed on read by PersonModel and
     * AuthenticatedUser) and are never persisted as login identifiers.
     *
     * @param array<string, mixed> $profile
     * @return array{0: int, 1: ?string}
     */
    private function upsertMisPerson(array $profile, string $kind, ?string $misEmail): array
    {
        $now = date('Y-m-d H:i:s');
        $person = $this->provisionablePerson($profile['mis_id'], $kind);
        $this->assertRequiredNames($person, $profile, $kind);

        $persistedEmail = $this->clip($misEmail, 150);

        $personData = $this->provided([
            'tenant_id'        => CurrentTenant::id(),
            'kind'             => $kind,
            'mis_id'           => $profile['mis_id'],
            'first_name'       => $this->clip($profile['first_name'], 100),
            'middle_name'      => $this->clip($profile['middle_name'], 100),
            'last_name'        => $this->clip($profile['last_name'], 100),
            'email'            => $persistedEmail,
            'mis_synced_at'    => $now,
            'mis_payload_hash' => $profile['raw_hash'],
        ]);

        if ($person === null) {
            $personId = (int) $this->personModel->insert($personData);
        } else {
            $personId = (int) $person['id'];
            $this->personModel->update($personId, $personData);
        }

        return [$personId, $persistedEmail];
    }

    /**
     * Resolves an email login input to the local MIS ID when uniquely matched; otherwise passes input as-is.
     */
    private function resolveMisLookupId(string $identifier, ?array $localUser): string
    {
        if (! str_contains($identifier, '@')) {
            return $identifier;
        }
        $matches = $this->personModel->scoped()
            ->where('LOWER(email)', mb_strtolower($identifier, 'UTF-8'))
            ->findAll(2);
        $misIds = array_values(array_unique(array_filter(array_column($matches, 'mis_id'), static fn (mixed $v): bool => is_string($v) && $v !== '')));
        if (count($misIds) > 1) {
            return $identifier;
        }
        if (count($misIds) === 1) {
            return $misIds[0];
        }
        if ($localUser !== null && ! empty($localUser['person_id'])) {
            $person = $this->personModel->scoped()->find((int) $localUser['person_id']);
            if ($person !== null && ! empty($person['mis_id'])) {
                return (string) $person['mis_id'];
            }
        }
        return $identifier;
    }

    /** @return array<string, mixed> */
    private function mapLoginProfile(array $response, string $kind): array
    {
        try {
            return $kind === 'student'
                ? $this->mapper->mapStudent($response)
                : $this->mapper->mapEmployee($response);
        } catch (MisUnavailableException $e) {
            $this->logMisFailure('map_' . $kind, $response);
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function provisionUserAccount(int $personId, string $misId, ?string $email, string $defaultRole): array
    {
        $user = $this->userModel->scoped()->where('person_id', $personId)->first();
        if ($user !== null) {
            if ($email !== null && empty($user['email'])) {
                $this->userModel->update((int) $user['id'], ['email' => $email]);
                $user = $this->userModel->find((int) $user['id']);
            }
            return $user;
        }

        $userId = (int) $this->userModel->insert([
            'tenant_id' => CurrentTenant::id(),
            'person_id' => $personId,
            'username'  => $this->availableUsername($misId, $defaultRole),
            'email'     => $email,
            'role'      => $defaultRole,
            'status'    => 'active',
        ]);
        return $this->userModel->find($userId);
    }

    private function availableUsername(string $misId, string $kind): string
    {
        if ($this->userModel->withDeleted()->scoped()->where('username', $misId)->first() === null) {
            return $misId;
        }
        $candidate = mb_substr($kind . ':' . $misId, 0, 100, 'UTF-8');
        if ($this->userModel->withDeleted()->scoped()->where('username', $candidate)->first() === null) {
            return $candidate;
        }
        return mb_substr($kind . ':' . bin2hex(random_bytes(4)) . ':' . $misId, 0, 100, 'UTF-8');
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') : $value;
    }

    private function provided(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * True when the upstream identity is the identifier that was submitted.
     *
     * Numeric identifiers are compared by value, so zero padding on either side does not turn an
     * authenticated sign-in into a rejection. A genuine difference is a failed authentication and
     * is reported as such, never as a service outage.
     */
    private function returnedIdentifierMatches(string $identifier, string $returned, string $kind = 'unknown'): bool
    {
        $expected = mb_strtolower(trim($identifier), 'UTF-8');
        $actual = mb_strtolower(trim($returned), 'UTF-8');
        if ($actual === '' || mb_strlen($returned, 'UTF-8') > 50 || preg_match('/[\x00-\x1F\x7F]/', $returned)) {
            $this->logMisFailure('assert_' . $kind . '_id', null, [
                'reason' => mb_strlen($returned, 'UTF-8') > 50 ? 'too_long' : 'malformed',
            ]);
            return false;
        }
        if ($expected !== $actual && ! $this->sameNumericIdentifier($expected, $actual)) {
            $this->logMisFailure('assert_' . $kind . '_id', null, ['reason' => 'mismatch']);
            return false;
        }
        return true;
    }

    /**
     * Whether the MIS employee directory lists this identifier.
     *
     * True when listed, false when the directory holds no such employee, and null when the
     * directory could not be read. The distinction matters: an unreadable directory must not be
     * reported as an inactive account.
     */
    private function isListedEmployee(string $misId): ?bool
    {
        try {
            $response = $this->misClient->searchEmployees($misId);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($response)) {
            return null;
        }
        // The row list is present only on a trustworthy success envelope. The client answers an
        // unreadable directory with an empty envelope that carries no rows, which is unknown.
        $rows = $response['data']['data'] ?? null;
        if (! is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['employee_id'] ?? '') === $misId) {
                return true;
            }
        }
        return false;
    }

    /** Numeric identifiers compare by value: 012345 and 12345 are the same employee. */
    private function sameNumericIdentifier(string $left, string $right): bool
    {
        if (! ctype_digit($left) || ! ctype_digit($right)) {
            return false;
        }
        $normalise = static fn (string $value): string => ltrim($value, '0') ?: '0';
        return $normalise($left) === $normalise($right);
    }

    private function assertRequiredNames(?array $person, array $profile, string $kind = 'unknown'): void
    {
        if ($person === null && (empty($profile['first_name']) || empty($profile['last_name']))) {
            $this->logMisFailure('assert_' . $kind . '_names', null, [
                'reason' => empty($profile['first_name']) ? 'missing_first_name' : 'missing_last_name',
            ]);
            throw new MisUnavailableException();
        }
    }

    /** Log only non-secret structural failure context; never log credentials, tokens, or PII. */
    private function logMisFailure(string $stage, ?array $payload = null, array $meta = []): void
    {
        if (! function_exists('log_message')) {
            return;
        }
        $keys = [];
        if ($payload !== null) {
            foreach (array_slice(array_keys($payload), 0, 10) as $key) {
                if (is_string($key) && preg_match('/\A[A-Za-z0-9_.-]{1,40}\z/', $key) === 1) {
                    $keys[] = $key;
                }
            }
            if (is_array($payload['data'] ?? null)) {
                foreach (array_slice(array_keys($payload['data']), 0, 10) as $key) {
                    if (is_string($key) && preg_match('/\A[A-Za-z0-9_.-]{1,40}\z/', $key) === 1) {
                        $keys[] = 'data.' . $key;
                    }
                }
            }
        }
        try {
            log_message('warning', 'MIS auth provisioning failed [stage={stage}, reason={reason}, keys={keys}]', [
                'stage'  => $stage,
                'reason' => (string) ($meta['reason'] ?? 'contract_error'),
                'keys'   => $keys === [] ? 'none' : implode(',', $keys),
            ]);
        } catch (\Throwable) {
            // Logging must never mask or alter authentication failure behavior.
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
    private function provision(array $profile, string $kind, ?string $misEmail = null): ?array
    {
        $db = \Config\Database::connect();
        if (!$db->transBegin()) {
            throw new \RuntimeException('Account provisioning transaction could not be started.');
        }
        try {
            $user = $kind === 'student'
                ? $this->provisionStudent($profile, $misEmail)
                : $this->provisionEmployee($profile, $misEmail);
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
     * @return array<string, mixed>|null
     */
    private function establishUserSession(array $sessionData): ?array
    {
        // Do not upgrade credentials verified before a concurrent password/access change.
        $fresh = (new AuthenticatedUser())->forIdentity((int) $sessionData['id'], (int) $sessionData['tenant_id']);
        if ($fresh === null || !isset($sessionData['auth_version']) || (int) $sessionData['auth_version'] !== $fresh['auth_version']) {
            return null;
        }
        $sessionData = $fresh;
        $this->userModel->update($sessionData['id'], ['last_login_at' => date('Y-m-d H:i:s')]);

        AuthenticatedUser::clear();
        session()->remove(['_auth_notice', '_auth_expired', '_auth_rotated_at', '_auth_obsolete', '_auth_handoff_doc_retries']);
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
        session()->remove(['_auth_notice', '_auth_expired', '_auth_rotated_at', '_auth_obsolete', '_auth_handoff_doc_retries']);
        session()->regenerate(true);
    }
}
