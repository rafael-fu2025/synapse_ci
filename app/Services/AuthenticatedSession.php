<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Session\Session;
use RuntimeException;

/** Retain a locked, anonymous predecessor so waiting requests cannot revive auth. */
final class AuthenticatedSession extends Session
{
    private bool $autoStarting = false;

    /** A queued request on an obsolete ID must not replace the browser's successor cookie. */
    public function start()
    {
        $this->autoStarting = true;
        try {
            $result = parent::start();
        } finally {
            $this->autoStarting = false;
        }
        if ($this->isObsoletePredecessor()) {
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
        if ($this->isObsoletePredecessor()) return;

        // Avoid auto-rotating on abortable/concurrent background or subresource requests
        // (e.g. progressive navigation fetch, image avatars, HTMX, JSON fetch) that omit
        // X-Requested-With; a cancelled fetch drops Set-Cookie while invalidating the old ID.
        if ($this->autoStarting && $this->isBackgroundRequest()) return;

        $identity = $this->get('user');
        $tenant = $this->get('tenant_id');
        $auth = $this->get('_auth');
        $oldId = session_id();
        $bound = is_array($auth) && ($auth['session_id'] ?? null) === $oldId && $oldId !== '';
        $this->remove(['user', 'tenant_id', '_auth', '_ci_old_input', '_auth_handoff_doc_retries']);
        $this->set('_auth_obsolete', true);
        if ($bound) {
            $this->set('_auth_rotated_at', time());
        } else {
            $this->remove('_auth_rotated_at');
        }

        // PHP writes the anonymous predecessor before unlocking it. Deleting it
        // instead can let a request already waiting on that file recreate it.
        parent::regenerate(false);
        if (session_id() === $oldId || session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session rotation failed; authentication was cleared.');
        }

        // Never upgrade a legacy or incorrectly bound session during auto-rotation.
        $this->remove(['_auth_rotated_at', '_auth_obsolete', '_auth_handoff_doc_retries']);
        if ($bound) {
            $auth['session_id'] = session_id();
            $this->set(['user' => $identity, 'tenant_id' => $tenant, '_auth' => $auth]);
        }
    }

    private function isObsoletePredecessor(): bool
    {
        return is_int($this->get('_auth_rotated_at')) || $this->get('_auth_obsolete') === true;
    }

    private function isBackgroundRequest(): bool
    {
        $request = service('request');
        return $request instanceof RequestInterface && AuthenticatedUser::isBackgroundRequest($request);
    }
}
