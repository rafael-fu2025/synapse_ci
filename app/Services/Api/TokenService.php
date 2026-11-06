<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\ApiTokenModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\ApiTokens;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Opaque bearer tokens for the mobile API. Only SHA-256 hashes are stored.
 * Refresh tokens are single-use: each refresh retires its row and issues a successor
 * in the same family; replaying or expiring a refresh token revokes the whole family.
 */
final class TokenService
{
    private ApiTokens $config;
    private ApiTokenModel $tokens;
    private UserModel $users;
    private AuthenticatedUser $authority;
    private BaseConnection $db;
    private Closure $clock;

    public function __construct(
        ?ApiTokens $config = null,
        ?ApiTokenModel $tokens = null,
        ?UserModel $users = null,
        ?AuthenticatedUser $authority = null,
        ?Closure $clock = null,
        ?BaseConnection $db = null
    ) {
        $this->config = $config ?? new ApiTokens();
        $this->db = $db ?? Database::connect();
        $this->tokens = $tokens ?? new ApiTokenModel($this->db);
        $this->users = $users ?? new UserModel($this->db);
        $this->authority = $authority ?? new AuthenticatedUser();
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Issue a fresh token family after a successful sign-in.
     *
     * @param array<string, mixed> $identity Validated AuthenticatedUser::forIdentity payload.
     * @return array<string, mixed> Credential payload for the client; tokens are shown exactly once.
     */
    public function issue(array $identity, string $deviceLabel, string $ip): array
    {
        $now = $this->now();
        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));
        $userId = (int) $identity['id'];
        $tenantId = (int) $identity['tenant_id'];

        $this->enforceActiveTokenCap($userId, $tenantId, $now);
        $this->users->update($userId, ['last_login_at' => $this->dateTime($now)]);

        $this->tokens->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'auth_version' => (int) $identity['auth_version'],
            'token_hash' => self::hash($accessToken),
            'refresh_hash' => self::hash($refreshToken),
            'family_id' => bin2hex(random_bytes(16)),
            'device_label' => $deviceLabel === '' ? null : $deviceLabel,
            'created_ip' => $ip === '' ? null : $ip,
            'access_expires_at' => $this->dateTime($now + $this->config->accessTtl),
            'refresh_expires_at' => $this->dateTime($now + $this->config->refreshTtl),
        ]);

        return [
            'token_type' => 'Bearer',
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'access_expires_at' => $this->iso($now + $this->config->accessTtl),
        ];
    }

    /**
     * Validate an access token and revalidate its identity from the database.
     *
     * @return array{identity: array<string, mixed>, token_hash: string}|null
     */
    public function resolve(string $accessToken): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $accessToken) !== 1) {
            return null;
        }
        $hash = self::hash($accessToken);
        $row = $this->tokens->where('token_hash', $hash)->first();
        if ($row === null || $row['revoked_at'] !== null || $row['rotated_at'] !== null) {
            return null;
        }
        $now = $this->now();
        if (strtotime((string) $row['access_expires_at']) < $now) {
            return null;
        }

        $identity = $this->authority->forIdentity((int) $row['user_id'], (int) $row['tenant_id']);
        if ($identity === null || (int) $identity['auth_version'] !== (int) $row['auth_version']) {
            // Disabled users, role changes, and credential resets must end every mobile session.
            $this->revokeAllForUser((int) $row['user_id'], (int) $row['tenant_id'], $now);
            return null;
        }
        CurrentTenant::set((int) $identity['tenant_id']);

        // Bounded bookkeeping: at most one last_used_at write per minute per token.
        if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at']) < $now - 60) {
            $this->tokens->update($row['id'], ['last_used_at' => $this->dateTime($now)]);
        }
        return ['identity' => $identity, 'token_hash' => $hash];
    }

    /**
     * Exchange a single-use refresh token for a successor credential in the same family.
     *
     * @return array{identity: array<string, mixed>, token: array<string, mixed>}|null
     */
    public function rotate(string $refreshToken): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $refreshToken) !== 1) {
            return null;
        }

        $db = $this->db;
        if (!$db->transBegin()) {
            throw new RuntimeException('Refresh transaction could not be started.');
        }

        try {
            $table = $db->protectIdentifiers($db->prefixTable('api_tokens'));
            $lock = $db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : '';
            $query = $db->query("SELECT * FROM {$table} WHERE refresh_hash = ?{$lock}", [self::hash($refreshToken)]);
            if ($query === false) {
                throw new RuntimeException('Refresh token could not be locked.');
            }
            $row = $query->getRowArray();
            if ($row === null) {
                if (!$db->transCommit()) {
                    throw new RuntimeException('Refresh transaction could not be committed.');
                }
                return null;
            }

            $now = $this->now();
            if ($row['rotated_at'] !== null || $row['revoked_at'] !== null
                || strtotime((string) $row['refresh_expires_at']) < $now) {
                // The row lock serializes replay detection with successor creation. A losing
                // concurrent request therefore revokes the successor before returning failure.
                $this->revokeFamily((string) $row['family_id'], $now);
                if (!$db->transCommit()) {
                    throw new RuntimeException('Refresh compromise revocation could not be committed.');
                }
                return null;
            }

            $identity = $this->authority->forIdentity((int) $row['user_id'], (int) $row['tenant_id']);
            if ($identity === null || (int) $identity['auth_version'] !== (int) $row['auth_version']) {
                $this->revokeAllForUser((int) $row['user_id'], (int) $row['tenant_id'], $now);
                if (!$db->transCommit()) {
                    throw new RuntimeException('Refresh identity revocation could not be committed.');
                }
                return null;
            }

            $accessToken = bin2hex(random_bytes(32));
            $nextRefresh = bin2hex(random_bytes(32));
            $retired = $this->dateTime($now);
            if (!$this->tokens->update($row['id'], ['rotated_at' => $retired, 'revoked_at' => $retired])) {
                throw new RuntimeException('Refresh token could not be retired.');
            }
            if ($this->tokens->insert([
                'tenant_id' => (int) $row['tenant_id'],
                'user_id' => (int) $row['user_id'],
                'auth_version' => (int) $identity['auth_version'],
                'token_hash' => self::hash($accessToken),
                'refresh_hash' => self::hash($nextRefresh),
                'family_id' => (string) $row['family_id'],
                'device_label' => $row['device_label'],
                'created_ip' => $row['created_ip'],
                'access_expires_at' => $this->dateTime($now + $this->config->accessTtl),
                'refresh_expires_at' => $this->dateTime($now + $this->config->refreshTtl),
            ]) === false) {
                throw new RuntimeException('Refresh successor could not be issued.');
            }
            if (!$db->transCommit()) {
                throw new RuntimeException('Refresh transaction could not be committed.');
            }
            CurrentTenant::set((int) $identity['tenant_id']);

            return ['identity' => $identity, 'token' => [
                'token_type' => 'Bearer',
                'access_token' => $accessToken,
                'refresh_token' => $nextRefresh,
                'access_expires_at' => $this->iso($now + $this->config->accessTtl),
            ]];
        } catch (\Throwable $error) {
            $db->transRollback();
            $db->resetTransStatus();
            throw $error;
        }
    }

    /** End the session family that the presented access token belongs to. */
    public function revokeByHash(string $tokenHash): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/', $tokenHash) !== 1) {
            return false;
        }
        $row = $this->tokens->where('token_hash', $tokenHash)->first();
        if ($row === null || $row['revoked_at'] !== null) {
            return false;
        }
        $this->revokeFamily((string) $row['family_id'], $this->now());
        return true;
    }

    public function revokeAllForUser(int $userId, int $tenantId, ?int $now = null): void
    {
        $this->tokens->builder()->where('user_id', $userId)->where('tenant_id', $tenantId)->where('revoked_at', null)
            ->update(['revoked_at' => $this->dateTime($now ?? $this->now())]);
    }

    private function revokeFamily(string $familyId, int $now): void
    {
        $this->tokens->builder()->where('family_id', $familyId)->where('revoked_at', null)
            ->update(['revoked_at' => $this->dateTime($now)]);
    }

    private function enforceActiveTokenCap(int $userId, int $tenantId, int $now): void
    {
        $active = $this->tokens->where('user_id', $userId)->where('tenant_id', $tenantId)
            ->where('revoked_at', null)->where('refresh_expires_at >', $this->dateTime($now))
            ->orderBy('id', 'ASC')->findAll();
        $surplus = count($active) - $this->config->maxActiveTokens + 1;
        if ($surplus <= 0) {
            return;
        }
        foreach (array_slice($active, 0, $surplus) as $row) {
            $this->revokeFamily((string) $row['family_id'], $now);
        }
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function dateTime(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /** Stored times are UTC; clients see ISO-8601 Asia/Manila like every other JSON surface. */
    private function iso(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format(DATE_ATOM);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
