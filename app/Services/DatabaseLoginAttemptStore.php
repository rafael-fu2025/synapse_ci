<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

final class DatabaseLoginAttemptStore implements LoginAttemptStore
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public function consume(array $buckets, int $now): int
    {
        if ($this->db->DBDriver !== 'MySQLi' || $this->db->transDepth !== 0 || $now < 1 || count($buckets) !== 2) {
            throw new RuntimeException('Login limits require an independent MySQL/MariaDB transaction.');
        }
        foreach ($buckets as $bucket) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $bucket['key']) || $bucket['limit'] < 1 || $bucket['limit'] > 10000
                || $bucket['seconds'] < 1 || $bucket['seconds'] > 86400) {
                throw new RuntimeException('Invalid login limit configuration.');
            }
        }
        if ($buckets[0]['key'] === $buckets[1]['key']) {
            throw new RuntimeException('Login limit buckets must be distinct.');
        }
        $table = $this->db->protectIdentifiers($this->db->prefixTable('login_attempt_buckets'));
        // A range DELETE can lock the first unexpired index entry under REPEATABLE READ.
        // Read candidates without locks, then use independent point deletes with expiry rechecks.
        $expired = $this->db->query("SELECT bucket_hash FROM {$table} WHERE expires_at < ? ORDER BY expires_at LIMIT 100", [$now - 86400]);
        if ($expired === false) {
            throw new RuntimeException('Unable to maintain login limits.');
        }
        foreach ($expired->getResultArray() as $row) {
            if (!$this->db->query("DELETE FROM {$table} WHERE bucket_hash = ? AND expires_at < ?", [$row['bucket_hash'], $now - 86400])) {
                throw new RuntimeException('Unable to maintain login limits.');
            }
        }
        $this->db->resetTransStatus();
        if (!$this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') || !$this->db->transBegin()) {
            throw new RuntimeException('Unable to begin login limit transaction.');
        }
        try {
            $retryAfter = 0;
            // IP first for every caller. A blocked IP cannot create arbitrary identity rows.
            foreach ($buckets as $bucket) {
                if (!$this->db->query("INSERT INTO {$table} (bucket_hash, attempts, expires_at) VALUES (?, 0, ?)
                    ON DUPLICATE KEY UPDATE bucket_hash = VALUES(bucket_hash)", [$bucket['key'], $now + $bucket['seconds']])) {
                    throw new RuntimeException('Unable to initialize login limit.');
                }
                $row = $this->db->query("SELECT attempts, expires_at FROM {$table} WHERE bucket_hash = ? FOR UPDATE", [$bucket['key']])->getRowArray();
                if ($row === null) {
                    throw new RuntimeException('Login limit bucket is unavailable.');
                }
                $expires = (int) $row['expires_at'];
                $attempts = (int) $row['attempts'];
                if ($expires <= $now) {
                    $expires = $now + $bucket['seconds'];
                    $attempts = 0;
                }
                if ($attempts >= $bucket['limit']) {
                    $retryAfter = max(1, $expires - $now);
                    break;
                }
                if (!$this->db->table('login_attempt_buckets')->where('bucket_hash', $bucket['key'])
                    ->update(['attempts' => $attempts + 1, 'expires_at' => $expires])) {
                    throw new RuntimeException('Unable to persist login limit.');
                }
            }
            // Commit even when the second bucket rejects: that request still consumed IP budget.
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Unable to commit login limit.');
            }
            return $retryAfter;
        } catch (Throwable $e) {
            try {
                $this->db->transRollback();
            } finally {
                $this->db->resetTransStatus();
            }
            throw $e;
        }
    }
}
