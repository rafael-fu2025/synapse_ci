<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\Realtime\TicketIssuer;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Realtime;
use Throwable;

final class RealtimeController extends BaseController
{
    public function ticket(): ResponseInterface
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        if (strtoupper($this->request->getMethod()) !== 'POST') {
            return $this->response->setStatusCode(405)->setHeader('Allow', 'POST')
                ->setJSON(['message' => 'Method not allowed.']);
        }
        try {
            $actor = (new AuthenticatedUser())->current();
            if ($actor === null) {
                return $this->response->setStatusCode(401)->setJSON(['message' => 'Please sign in to continue.']);
            }
            // Never accept a user, tenant, channel, or origin from request parameters.
            $payload = (new TicketIssuer(config(Realtime::class)))->issue($actor, $this->request->getHeaderLine('Origin'));
            $payload['csrf'] = ['name' => csrf_token(), 'hash' => csrf_hash()];
            return $this->response->setJSON($payload);
        } catch (Throwable $failure) {
            $status = in_array($failure->getCode(), [401, 403], true) ? (int) $failure->getCode() : 503;
            return $this->response->setStatusCode($status)->setJSON([
                'message' => $status === 403 ? 'Realtime origin not allowed.' : 'Realtime is unavailable.',
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        }
    }
}
