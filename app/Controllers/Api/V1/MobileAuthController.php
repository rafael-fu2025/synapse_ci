<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Api\ApiContext;
use App\Services\Api\TokenService;
use App\Services\AuthService;
use App\Services\LoginRateLimiter;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

/** Credential lifecycle for the mobile client: sign in, refresh rotation, sign out. */
final class MobileAuthController extends ApiV1Controller
{
    public function login(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $payload = $this->jsonBody();
            $identifier = $payload['identifier'] ?? null;
            $password = $payload['password'] ?? null;
            $identifierOk = is_string($identifier) && trim($identifier) !== '' && mb_strlen($identifier) <= 100;
            $passwordOk = is_string($password) && $password !== '';
            if (!$identifierOk || !$passwordOk) {
                return $this->fail(422, 'validation_failed', 'Please enter your ID number/email and password.', [
                    'identifier' => $identifierOk ? [] : ['Enter your ID number or email.'],
                    'password' => $passwordOk ? [] : ['Enter your password.'],
                ]);
            }

            // Same IP + identity budgets as the web form; limiter state must survive transport changes.
            $this->throttle($identifier);

            $identity = (new AuthService())->authenticate($identifier, $password);
            if ($identity === null) {
                return $this->fail(401, 'invalid_credentials',
                    'Invalid ID or password. Please verify your credentials with the MIS directory.');
            }
            $token = (new TokenService())->issue($identity, $this->deviceLabel(), $this->request->getIPAddress());
            return $this->ok(['user' => $this->presentUser($identity), 'token' => $token]);
        });
    }

    public function refresh(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $payload = $this->jsonBody();
            $refreshToken = $payload['refresh_token'] ?? null;
            if (!is_string($refreshToken) || preg_match('/^[0-9a-f]{64}$/', $refreshToken) !== 1) {
                return $this->fail(422, 'validation_failed', 'A valid refresh token is required.', [
                    'refresh_token' => ['A valid refresh token is required.'],
                ]);
            }

            $this->throttle($refreshToken);

            $rotated = (new TokenService())->rotate($refreshToken);
            if ($rotated === null) {
                return $this->fail(401, 'invalid_refresh', 'Please sign in again to continue.');
            }
            return $this->ok(['user' => $this->presentUser($rotated['identity']), 'token' => $rotated['token']]);
        });
    }

    public function logout(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $this->identity();
            (new TokenService())->revokeByHash(ApiContext::tokenHash());
            return $this->noContent();
        });
    }

    private function throttle(string $pseudonym): void
    {
        try {
            $wait = service('loginRateLimiter')->consume($this->request->getIPAddress(), $pseudonym);
        } catch (Throwable $error) {
            // No exception messages, credential payloads, IP, or identifier values in logs.
            log_message('error', 'Login limiter unavailable: {type}', ['type' => get_class($error)]);
            throw new RuntimeException('Sign-in is temporarily unavailable. Please try again shortly.', 503);
        }
        if ($wait > 0) {
            throw new RuntimeException('Too many sign-in attempts. Please wait before trying again.', 429);
        }
    }

    private function deviceLabel(): string
    {
        $label = preg_replace('/[\x00-\x1F\x7F]/', '', $this->request->getUserAgent()->getAgentString());
        return mb_substr(trim((string) $label), 0, 120);
    }
}
