<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Closure;
use Throwable;

/** Cross-process dispatch approval. A missing, paused, changed, or unreadable manifest denies sending. */
final class DeploymentControl
{
    private readonly string $path;
    private readonly Closure $sourceStamp;

    public function __construct(?string $path = null, ?callable $sourceStamp = null)
    {
        $this->path = $path ?? WRITEPATH . 'ai-dispatch-control.json';
        $this->sourceStamp = Closure::fromCallable($sourceStamp ?? self::configurationStamp(...));
    }

    public function assertAllowed(Profile $profile, int $tenantId, ?string $unit = null): void
    {
        $profile->assertDispatchAllowed($tenantId, $unit ?? ($profile->approvedUnits()[0] ?? ''));
        $handle = null;
        try {
            if (!is_file($this->path) || is_link($this->path)) { throw new AiFailure('dispatch_paused'); }
            $handle = @fopen($this->path, 'rb');
            if ($handle === false || !flock($handle, LOCK_SH | LOCK_NB)) { throw new AiFailure('dispatch_paused'); }
            $raw = stream_get_contents($handle, 4097);
            $manifest = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : null;
            if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ($manifest['paused'] ?? null) !== false
                || !is_string($manifest['revision'] ?? null) || !hash_equals($profile->revision(), $manifest['revision'])
                || !is_string($manifest['source_stamp'] ?? null) || !hash_equals(($this->sourceStamp)($profile), $manifest['source_stamp'])) {
                throw new AiFailure('dispatch_paused');
            }
        } catch (Throwable) { throw new AiFailure('dispatch_paused'); }
        finally { if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); } }
    }

    /** Pause never depends on valid provider settings or an encryption key. */
    public function pause(): void
    {
        $this->write(['version' => 1, 'paused' => true, 'revision' => '', 'source_stamp' => '', 'updated_at' => gmdate('c')]);
    }

    public function resume(Profile $profile, int $tenantId, ?string $unit = null): void
    {
        $profile->assertDispatchAllowed($tenantId, $unit ?? ($profile->approvedUnits()[0] ?? ''));
        (new InsightGenerator($profile))->assertProfile($profile);
        $this->write(['version' => 1, 'paused' => false, 'revision' => $profile->revision(),
            'source_stamp' => ($this->sourceStamp)($profile), 'updated_at' => gmdate('c')]);
    }

    private function write(array $manifest): void
    {
        $handle = null;
        try {
            if (is_link($this->path) || !is_dir(dirname($this->path))) { throw new AiFailure('dispatch_paused'); }
            $handle = @fopen($this->path, 'c+b');
            if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) { throw new AiFailure('dispatch_paused'); }
            @chmod($this->path, 0600);
            $json = json_encode($manifest, JSON_THROW_ON_ERROR);
            if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0 || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new AiFailure('dispatch_paused');
            }
        } catch (Throwable) { throw new AiFailure('dispatch_paused'); }
        finally { if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); } }
    }

    /** Private HMAC only; never displayed or logged. Detects dotenv/code changes in already-running workers. */
    private static function configurationStamp(#[\SensitiveParameter] Profile $profile): string
    {
        $key = base64_decode($profile->config->encryptionKey, true);
        if ($key === false || strlen($key) !== 32) { throw new AiFailure('dispatch_paused'); }
        $key = hash_hmac('sha256', 'humus.ai.control.source.v1', $key, true);
        $files = [ROOTPATH . '.env', APPPATH . 'Config/Ai.php', __DIR__ . '/ProgramLibrary.php',
            __DIR__ . '/InsightGenerator.php', __DIR__ . '/DisclosurePolicy.php', __DIR__ . '/SignalDetector.php',
            __DIR__ . '/EndpointPolicy.php', __DIR__ . '/CurlTransport.php', __DIR__ . '/AnalysisCatalog.php',
            __DIR__ . '/BroadDisclosurePolicy.php', __DIR__ . '/DepartmentSnapshotBuilder.php', __DIR__ . '/ClinicSnapshotBuilder.php',
            __DIR__ . '/Profile.php', __DIR__ . '/AiAccess.php', __DIR__ . '/AiGrantStore.php',
            __DIR__ . '/InsightApplication.php', __DIR__ . '/InsightWorker.php', __DIR__ . '/InsightStore.php', __FILE__];
        $settings = WRITEPATH . 'ai-settings/provider.enc';
        if (is_file($settings)) { $files[] = $settings; }
        $stamps = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                if ($file === ROOTPATH . '.env') { $stamps[] = 'absent'; continue; }
                throw new AiFailure('dispatch_paused');
            }
            $stamp = @hash_hmac_file('sha256', $file, $key);
            if (!is_string($stamp)) { throw new AiFailure('dispatch_paused'); }
            $stamps[] = $stamp;
        }
        return hash_hmac('sha256', json_encode($stamps, JSON_THROW_ON_ERROR), $key);
    }
}
