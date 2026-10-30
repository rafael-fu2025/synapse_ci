<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Session\Session;
use RuntimeException;

/** Retain a locked, anonymous predecessor so waiting requests cannot revive auth. */
final class AuthenticatedSession extends Session
{
    /** A queued request on an obsolete ID must not replace the browser's successor cookie. */
    public function start()
    {
        $result = parent::start();
        if (is_int($this->get('_auth_rotated_at'))) {
            $cookies = service('response')->getCookieStore();
            $remaining = $cookies->remove($this->config->cookieName);
            $cookies->clear();
            foreach ($remaining as $cookie) service('response')->setCookie($cookie);
        }
        return $result;
    }

    public function regenerate(bool $destroy = false)
    {
        // Never rotate an obsolete anonymous ID into a new cookie, even after the handoff expires.
        if (is_int($this->get('_auth_rotated_at'))) return;
        $identity = $this->get('user');
        $tenant = $this->get('tenant_id');
        $auth = $this->get('_auth');
        $oldId = session_id();
        $bound = is_array($auth) && ($auth['session_id'] ?? null) === $oldId && $oldId !== '';
        $this->remove(['user', 'tenant_id', '_auth', '_ci_old_input']);
        $this->set('_auth_rotated_at', time());

        // PHP writes the anonymous predecessor before unlocking it. Deleting it
        // instead can let a request already waiting on that file recreate it.
        parent::regenerate(false);
        if (session_id() === $oldId || session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session rotation failed; authentication was cleared.');
        }

        // Never upgrade a legacy or incorrectly bound session during auto-rotation.
        $this->remove('_auth_rotated_at');
        if ($bound) {
            $auth['session_id'] = session_id();
            $this->set(['user' => $identity, 'tenant_id' => $tenant, '_auth' => $auth]);
        }
    }
}
