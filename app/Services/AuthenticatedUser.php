<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/** DB-backed authority; deliberately uncached, including between filters. */
final class AuthenticatedUser
{
    /** Return a refreshed, credential-free session identity, or clear invalid authentication. */
    public function current(): ?array
    {
        CurrentTenant::reset();
        $source = session()->get('user');
        $tenantId = self::positiveId(session()->get('tenant_id'));
        if (!is_array($source) || ($source['logged_in'] ?? null) !== true
            || self::positiveId($source['id'] ?? null) === null
            || $tenantId === null || self::positiveId($source['tenant_id'] ?? null) !== $tenantId) {
            self::clear();
            return null;
        }

        $auth = session()->get('_auth');
        $now = time();
        $config = config('Session');
        if (!is_array($auth)
            || !is_int($auth['started_at'] ?? null) || !is_int($auth['last_seen_at'] ?? null)
            || !is_int($auth['version'] ?? null) || $auth['version'] < 1
            || !is_string($auth['session_id'] ?? null) || $auth['session_id'] !== session_id()
            || $auth['started_at'] > $auth['last_seen_at'] || $auth['last_seen_at'] > $now
            || $config->authIdleTimeout <= 0 || $config->authAbsoluteTimeout <= 0
            || $now - $auth['last_seen_at'] >= $config->authIdleTimeout
            || $now - $auth['started_at'] >= $config->authAbsoluteTimeout) {
            self::clear();
            return null;
        }

        $user = $this->forIdentity((int) $source['id'], $tenantId);
        if ($user === null || $auth['version'] !== $user['auth_version']) {
            self::clear();
            return null;
        }

        // Replace rather than merge: cached privileges and secrets must not survive refresh.
        $auth['last_seen_at'] = $now;
        session()->set(['user' => $user, '_auth' => $auth]);
        CurrentTenant::set($tenantId);
        return $user;
    }

    /** Validate an explicit DB identity without binding tenant context or writing session state. */
    public function forIdentity(int $id, int $tenantId): ?array
    {
        if ($id <= 0 || $tenantId <= 0) {
            return null;
        }
        $db = Database::connect();
        $tenant = $db->table('tenants')->where('id', $tenantId)->where('is_active', 1)->where('deleted_at', null)->get()->getRowArray();
        if ($tenant === null) { return null; }
        $user = $db->table('users')->where('id', $id)->where('tenant_id', $tenantId)
            ->where('status', 'active')->where('deleted_at', null)->get()->getRowArray();
        if ($user === null || !in_array($user['role'], PortalContext::allowedRoles(PortalContext::PORTAL_MAIN), true)) {
            return null;
        }

        $person = null;
        $isTeaching = false;
        if ($user['person_id'] !== null) {
            $person = $db->table('people')->where('id', $user['person_id'])->where('tenant_id', $tenantId)
                ->where('deleted_at', null)->get()->getRowArray();
            if ($person === null) {
                return null;
            }
            $employee = $db->table('employee_profiles')->where('person_id', $person['id'])
                ->where('tenant_id', $tenantId)->get()->getRowArray();
            $isTeaching = !empty($employee['is_teaching']);
        } elseif ($user['role'] !== 'superadmin') {
            // The local superadmin is the only supported person-less break-glass identity.
            return null;
        }

        $email = $user['email'] ?? null;
        if (($email === null || $email === '') && $person !== null) {
            $email = $person['email'] ?? UniversityEmail::derive($person['first_name'] ?? null, $person['last_name'] ?? null);
        }

        return [
            'id' => (int) $user['id'],
            'auth_version' => (int) $user['auth_version'],
            'person_id' => $person !== null ? (int) $person['id'] : null,
            'tenant_id' => $tenantId,
            'username' => $user['username'],
            'email' => $email,
            'name' => $person !== null ? trim($person['first_name'] . ' ' . $person['last_name']) : $user['username'],
            'avatar_initials' => ProfileAvatar::initials($person ?? ['name' => $user['username']]),
            'identifier' => $person['mis_id'] ?? $user['username'],
            'role' => $user['role'],
            'is_teaching' => $isTeaching,
            'permissions' => (new UserModel())->getPermissionsForRole($user['role']),
            'logged_in' => true,
        ];
    }

    /** Clear authentication without SQL or destroying unrelated UI preferences. */
    public static function clear(): void
    {
        session()->remove(['user', 'tenant_id', '_auth', '_ci_old_input']);
        CurrentTenant::reset();
    }

    public static function denied(RequestInterface $request, bool $authenticated, string $message, string $target): ResponseInterface
    {
        $json = strcasecmp($request->getHeaderLine('X-Requested-With'), 'XMLHttpRequest') === 0
            || str_contains(strtolower($request->getHeaderLine('Accept')), 'json')
            || strtolower($request->getHeaderLine('HX-Request')) === 'true'
            || $request->getHeaderLine('X-Up-Version') !== ''
            || (method_exists($request, 'getGet') && $request->getGet('fragment') === '1');
        if ($json || strtoupper($request->getMethod()) !== 'GET') {
            // Never redirect rejected mutations, even when a browser submitted them.
            $status = $authenticated || !$json ? 403 : 401;
            return service('response')->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
                ->setJSON(['message' => $message]);
        }
        session()->setFlashdata('error', $message);
        return redirect()->to($target)->setHeader('Cache-Control', 'no-store');
    }

    private static function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}
