<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Closure;
use Throwable;

/** Synthetic traffic only: no snapshots, jobs, clinical records or approval writes. */
final class SyntheticProbe
{
    private readonly Profile $profile;
    private readonly Closure $callback;
    private readonly string $cacheDirectory;
    private readonly Closure $clock;

    /** Callback: callable(Profile): array; the production path always uses ChatClient's fixed fixture. */
    public function __construct(?Profile $profile = null, ?callable $callback = null, ?string $cacheDirectory = null, ?callable $clock = null)
    {
        $this->profile = $profile ?? Profile::fromConfig();
        $this->callback = Closure::fromCallable($callback ?? static fn (Profile $profile): array => (new ChatClient($profile))->probe());
        $this->cacheDirectory = $cacheDirectory ?? WRITEPATH . 'cache/ai-probes';
        $this->clock = Closure::fromCallable($clock ?? time(...));
    }

    public function execute(int $tenantId): array
    {
        $revision = $this->profile->revision();
        if ($tenantId < 1 || ! in_array((string) $tenantId, Profile::csv($this->profile->config->approvedTenants), true)) {
            throw new AiFailure('tenant_not_approved', 403);
        }
        // A disabled, not-yet-approved profile may be tested, but never an unconfigured endpoint.
        $this->profile->assertCompletionConfigured(true);
        $directory = rtrim($this->cacheDirectory, '/\\');
        if ($directory === '' || is_link($directory)
            || (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))
            || ! @chmod($directory, 0700)) {
            throw new AiFailure('store_unavailable');
        }
        $path = $directory . '/tenant-' . $tenantId . '.json';
        if (is_link($path)) {
            throw new AiFailure('store_unavailable');
        }
        $mask = umask(0077);
        try {
            $file = @fopen($path, 'c+b');
        } finally {
            umask($mask);
        }
        if ($file === false) {
            throw new AiFailure('store_unavailable');
        }
        $locked = false;
        try {
            if (! @chmod($path, 0600)) {
                throw new AiFailure('store_unavailable');
            }
            if (! @flock($file, LOCK_EX | LOCK_NB)) {
                throw new AiFailure('worker_busy', 409);
            }
            $locked = true;
            $now = ($this->clock)();
            if (! is_int($now) || $now < 0) {
                throw new AiFailure('store_unavailable');
            }
            $raw = @stream_get_contents($file, 1025);
            if ($raw === false || strlen($raw) > 1024) {
                throw new AiFailure('store_unavailable');
            }
            $counter = $raw === '' ? ['timestamp' => $now, 'count' => 0] : json_decode($raw, true);
            // Corruption fails closed; a clock rollback must not reset the cost allowance.
            if (! is_array($counter) || count($counter) !== 2
                || ! is_int($counter['timestamp'] ?? null) || $counter['timestamp'] < 0
                || ! is_int($counter['count'] ?? null) || $counter['count'] < 0 || $counter['count'] > 10) {
                throw new AiFailure('store_unavailable');
            }
            if ($now >= $counter['timestamp'] && $now - $counter['timestamp'] >= 60) {
                $counter = ['timestamp' => $now, 'count' => 0];
            }
            if ($counter['count'] >= 10) {
                throw new AiFailure('daily_budget_exceeded', 429);
            }
            ++$counter['count'];
            $encoded = json_encode($counter, JSON_THROW_ON_ERROR);
            // Reserve before the remote request, including failed/ambiguous requests. Keep this inode locked.
            if (! @rewind($file) || ! @ftruncate($file, 0) || @fwrite($file, $encoded) !== strlen($encoded) || ! @fflush($file)) {
                throw new AiFailure('store_unavailable');
            }
            $result = ($this->callback)($this->profile);
            $this->profile->assertUnchanged();
            if (! is_array($result) || ($result['success'] ?? null) !== true || ($result['verified'] ?? null) !== true) {
                throw new AiFailure('probe_failed');
            }

            return ['success' => true, 'verified' => true, 'revision' => $revision];
        } catch (AiFailure $error) {
            throw $error;
        } catch (Throwable) {
            throw new AiFailure('probe_failed');
        } finally {
            if ($locked) {
                @flock($file, LOCK_UN);
            }
            fclose($file);
        }
    }
}
