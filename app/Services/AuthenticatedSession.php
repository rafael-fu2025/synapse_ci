<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Session\Session;
use RuntimeException;

/** Retain a locked, anonymous predecessor so waiting requests cannot revive auth. */
final class AuthenticatedSession extends Session
{
    public function regenerate(bool $destroy = false)
    {
        $identity = $this->get('user');
        $tenant = $this->get('tenant_id');
        $auth = $this->get('_auth');
        $oldId = session_id();
        $this->remove(['user', 'tenant_id', '_auth', '_ci_old_input']);

        // PHP writes the anonymous predecessor before unlocking it. Deleting it
        // instead can let a request already waiting on that file recreate it.
        parent::regenerate(false);
        if (session_id() === $oldId || session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session rotation failed; authentication was cleared.');
        }

        // Never upgrade a legacy or incorrectly bound session during auto-rotation.
        if (is_array($auth) && ($auth['session_id'] ?? null) === $oldId && $oldId !== '') {
            $auth['session_id'] = session_id();
            $this->set(['user' => $identity, 'tenant_id' => $tenant, '_auth' => $auth]);
        }
    }
}
