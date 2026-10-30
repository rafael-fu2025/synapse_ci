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
    public const EXPIRED_MESSAGE = 'Your session has expired. Please sign in again to continue.';
    public const REAUTHENTICATION_MESSAGE = 'Your session could not be validated. Please sign in again to continue.';
    public const AUTHENTICATION_MESSAGE = 'Please sign in to continue.';
    private const ROTATION_HANDOFF_SECONDS = 15;

    /** Return a refreshed, credential-free session identity, or clear invalid authentication. */
    public function current(): ?array
    {
        CurrentTenant::reset();
        $source = session()->get('user');
        $tenantId = self::positiveId(session()->get('tenant_id'));
        if (!is_array($source) || ($source['logged_in'] ?? null) !== true
            || self::positiveId($source['id'] ?? null) === null
            || $tenantId === null || self::positiveId($source['tenant_id'] ?? null) !== $tenantId) {
            if ($source !== null || session()->get('_auth') !== null || session()->get('tenant_id') !== null) {
                self::invalidate('reauthentication_required', 'identity_metadata_invalid');
            } else {
                self::clear();
            }
            return null;
        }

        $auth = session()->get('_auth');
        $now = time();
        $config = config('Session');
        if (!is_array($auth)
            || !is_int($auth['started_at'] ?? null) || !is_int($auth['last_seen_at'] ?? null)
            || !is_int($auth['version'] ?? null) || $auth['version'] < 1) {
            self::invalidate('reauthentication_required', 'auth_metadata_invalid');
            return null;
        }
        if (!is_string($auth['session_id'] ?? null) || $auth['session_id'] !== session_id()) {
            self::invalidate('reauthentication_required', 'session_binding_mismatch');
            return null;
        }
        if ($auth['started_at'] > $auth['last_seen_at'] || $auth['last_seen_at'] > $now) {
            self::invalidate('reauthentication_required', 'auth_clock_invalid');
            return null;
        }
        if ($config->authIdleTimeout <= 0 || $config->authAbsoluteTimeout <= 0) {
            self::invalidate('reauthentication_required', 'auth_limits_invalid');
            return null;
        }

        if ($now - $auth['last_seen_at'] >= $config->authIdleTimeout
            || $now - $auth['started_at'] >= $config->authAbsoluteTimeout) {
            self::invalidate('session_expired', $now - $auth['last_seen_at'] >= $config->authIdleTimeout ? 'idle_timeout' : 'absolute_timeout');
            // Legacy readers may still inspect this; _auth_notice owns durability.
            session()->setFlashdata('_auth_expired', true);
            return null;
        }

        $user = $this->forIdentity((int) $source['id'], $tenantId);
        if ($user === null || $auth['version'] !== $user['auth_version']) {
            self::invalidate('reauthentication_required', $user === null ? 'identity_no_longer_authorized' : 'auth_version_changed');
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
        $rotatedAt = session()->get('_auth_rotated_at');
        $age = is_int($rotatedAt) ? time() - $rotatedAt : null;
        if (!$authenticated && $age !== null && $age >= 0 && $age <= self::ROTATION_HANDOFF_SECONDS) {
            // This ID stays anonymous. Only safe reads may be retried with the browser's current cookie.
            $response = service('response')->setStatusCode(409)->setHeader('Cache-Control', 'no-store')
                ->setHeader('X-Synapse-Session', 'rotated')->setHeader('Retry-After', '1')
                ->setJSON(['message' => 'Your session is being renewed. Please retry this request.']);
            foreach (['X-Synapse-Auth', 'X-Synapse-Login', 'Location'] as $header) $response->removeHeader($header);
            return $response;
        }
        $reason = $authenticated ? null : self::noticeReason();
        if (!$authenticated) {
            $reason ??= 'authentication_required';
            session()->set('_auth_notice', $reason);
            if ($reason !== 'authentication_required') {
                $message = self::noticeMessage($reason);
            }
        }
        $json = self::isBackgroundRequest($request);
        if ($json || strtoupper($request->getMethod()) !== 'GET') {
            // Never redirect rejected mutations, even when a browser submitted them.
            $status = $authenticated || !$json ? 403 : 401;
            $response = service('response')->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
                ->setJSON(['message' => $message]);
            $response->removeHeader('X-Synapse-Auth');
            $response->removeHeader('X-Synapse-Login');
            $response->removeHeader('Location');
            if ($status === 401) {
                $response->setHeader('X-Synapse-Auth', $reason)
                    ->setHeader('X-Synapse-Login', site_url('auth/login'));
            }
            return $response;
        }
        if (!$authenticated && $reason === 'session_expired') {
            session()->keepFlashdata('_auth_expired');
        } elseif ($authenticated || $reason === 'authentication_required') {
            session()->setFlashdata('error', $message);
        }
        return redirect()->to($target)->setHeader('Cache-Control', 'no-store');
    }

    private static function invalidate(string $reason, string $diagnostic): void
    {
        // Fixed reason codes only: never log IDs, cookies, tokens, or serialized authentication.
        log_message('warning', 'Browser authentication rejected: {reason}.', ['reason' => $diagnostic]);
        self::clear();
        session()->remove('_auth_expired');
        session()->set('_auth_notice', $reason);
    }

    /** Read without consuming; legacy timeout flash is accepted during migration. */
    public static function noticeReason(): ?string
    {
        $reason = session()->get('_auth_notice');
        if (in_array($reason, ['session_expired', 'reauthentication_required', 'authentication_required'], true)) {
            return $reason;
        }
        if (session()->getFlashdata('_auth_expired') === true) {
            session()->set('_auth_notice', 'session_expired');
            return 'session_expired';
        }
        return null;
    }

    public static function noticeMessage(string $reason): string
    {
        return match ($reason) {
            'session_expired' => self::EXPIRED_MESSAGE,
            'reauthentication_required' => self::REAUTHENTICATION_MESSAGE,
            default => self::AUTHENTICATION_MESSAGE,
        };
    }

    public static function isBackgroundRequest(RequestInterface $request): bool
    {
        $destination = strtolower($request->getHeaderLine('Sec-Fetch-Dest'));
        return strcasecmp($request->getHeaderLine('X-Requested-With'), 'XMLHttpRequest') === 0
            || str_contains(strtolower($request->getHeaderLine('Accept')), 'json')
            || strtolower($request->getHeaderLine('HX-Request')) === 'true'
            || $request->getHeaderLine('X-Up-Version') !== ''
            || $request->getHeaderLine('X-Synapse-Navigation') === '1'
            || ($destination !== '' && $destination !== 'document')
            || (method_exists($request, 'getGet') && $request->getGet('fragment') === '1');
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
