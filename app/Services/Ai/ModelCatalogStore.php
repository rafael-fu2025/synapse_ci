<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Closure;
use RuntimeException;
use Throwable;

/** Metadata only. Import never changes settings, approvals, credentials or analytics state. */
final class ModelCatalogStore
{
    private readonly string $directory;
    private readonly Closure $clock;

    public function __construct(?string $directory = null, ?callable $clock = null)
    {
        $directory = rtrim($directory ?? WRITEPATH . 'ai-settings/model-catalog', '/\\');
        if ($directory === '' || str_contains($directory, "\0")) { throw new AiFailure('store_unavailable'); }
        $this->directory = $directory;
        $this->clock = Closure::fromCallable($clock ?? static fn (): int => time());
    }

    /** Reserve BEFORE outbound HTTP. Sliding window, shared by the tenant, including failed requests. */
    public function reserveDiscovery(int $tenant, int $actor): void
    {
        $this->identity($tenant, $actor);
        $this->locked(function (PayloadCipher $cipher) use ($tenant, $actor): void {
            $now = $this->now();
            $record = $this->read($cipher, $tenant, 'rate') ?? ['requests' => []];
            $requests = array_values(array_filter($record['requests'], static fn (array $r): bool => $r['at'] > $now - 60));
            if (count($requests) >= 10) { throw new RuntimeException('catalog_rate_limited', 429); }
            $requests[] = ['at' => $now, 'actor' => $actor];
            $this->write($cipher, $tenant, 'rate', ['requests' => $requests]);
        });
    }

    /** Latest preview per tenant; staging is separate from the imported catalog. */
    public function stage(int $tenant, int $actor, string $baseUrl, #[\SensitiveParameter] array $models): array
    {
        $this->identity($tenant, $actor);
        $baseUrl = $this->base($baseUrl);
        $models = $this->rows($models);
        return $this->locked(function (PayloadCipher $cipher) use ($tenant, $actor, $baseUrl, $models): array {
            $token = bin2hex(random_bytes(32));
            $expires = $this->now() + 600;
            $this->write($cipher, $tenant, 'preview', ['actor' => $actor, 'token_hash' => hash('sha256', $token),
                'base_url' => $baseUrl, 'models' => $models, 'expires_at' => $expires]);
            return ['preview_token' => $token, 'models' => $models, 'base_url' => $baseUrl, 'expires_at' => $expires];
        });
    }

    /** IDs only, server-staged defaults, single-use tokens. Import all replaces; selection adds on the same base. */
    public function import(int $tenant, int $actor, #[\SensitiveParameter] string $token, array $ids, bool $all = false): array
    {
        $this->identity($tenant, $actor);
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token) || !array_is_list($ids) || count($ids) > ModelCapabilities::MAX_MODELS) {
            throw new RuntimeException('catalog_invalid_selection', 422);
        }
        foreach ($ids as $id) {
            if (!ModelCapabilities::safeId($id)) { throw new RuntimeException('catalog_invalid_selection', 422); }
        }
        if (count(array_unique($ids)) !== count($ids) || ($all && $ids !== [])) {
            throw new RuntimeException('catalog_invalid_selection', 422);
        }
        return $this->locked(function (PayloadCipher $cipher) use ($tenant, $actor, $token, $ids, $all): array {
            $preview = $this->read($cipher, $tenant, 'preview');
            if ($preview === null || ($preview['actor'] ?? null) !== $actor
                || !hash_equals($preview['token_hash'] ?? '', hash('sha256', $token))) {
                throw new RuntimeException('catalog_preview_invalid', 409);
            }
            if (($preview['expires_at'] ?? 0) <= $this->now()) { throw new RuntimeException('catalog_preview_expired', 409); }
            $available = $this->rows($preview['models']);
            $selected = $all ? $available : array_values(array_filter($available, static fn (array $row): bool => in_array($row['id'], $ids, true)));
            if ($selected === [] || (!$all && count($selected) !== count($ids))) {
                throw new RuntimeException('catalog_invalid_selection', 422);
            }
            $catalog = $this->read($cipher, $tenant, 'catalog');
            $merged = [];
            if (!$all && $catalog !== null && $catalog['base_url'] === $preview['base_url']) {
                foreach ($this->rows($catalog['models']) as $row) { $merged['id:' . $row['id']] = $row; }
            }
            foreach ($selected as $row) { $merged['id:' . $row['id']] = $row; }
            if (count($merged) > ModelCapabilities::MAX_MODELS) { throw new RuntimeException('catalog_too_large', 422); }
            $models = array_values($merged);
            usort($models, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
            $catalog = ['base_url' => $this->base($preview['base_url']), 'models' => $models, 'imported_at' => gmdate('c', $this->now())];
            // Consume first: if catalog persistence fails, a new discovery is needed, never a token replay.
            $this->write($cipher, $tenant, 'preview', ['actor' => $actor, 'token_hash' => '', 'expires_at' => 0]);
            $this->write($cipher, $tenant, 'catalog', $catalog);
            return $catalog + ['imported_count' => count($selected), 'selected_count' => count($selected)];
        });
    }

    public function summary(int $tenant): array
    {
        $this->identity($tenant, 1);
        return $this->locked(function (PayloadCipher $cipher) use ($tenant): array {
            $catalog = $this->read($cipher, $tenant, 'catalog');
            if ($catalog === null) { return ['base_url' => '', 'models' => [], 'imported_at' => null]; }
            return ['base_url' => $this->base($catalog['base_url']), 'models' => $this->rows($catalog['models']),
                'imported_at' => $catalog['imported_at']];
        });
    }

    private function identity(int $tenant, int $actor): void
    {
        if ($tenant < 1 || $actor < 1) { throw new AiFailure('access_denied', 403); }
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) { throw new AiFailure('store_unavailable'); }
        return $now;
    }

    private function base(string $base): string
    {
        $endpoint = EndpointPolicy::parseBaseUrl($base);
        return substr($endpoint['url'], 0, -strlen('/chat/completions'));
    }

    /** Strict allowlist for already-normalized discovery rows; no arbitrary provider prose/secrets persisted. */
    private function rows(#[\SensitiveParameter] array $models): array
    {
        if (count($models) > ModelCapabilities::MAX_MODELS) { throw new RuntimeException('catalog_too_large', 422); }
        if (!array_is_list($models)) { throw new RuntimeException('catalog_invalid_models', 422); }
        $result = []; $seen = [];
        $sourceValues = ['unknown', 'provider default', 'reviewed preset', 'local safe budget', 'current config',
            'current config; local safe budget', 'current config capped by provider', 'current config; explicit provider support',
            'local safe budget; provider context minus output and 128 overhead'];
        foreach (['context_window', 'context_length', 'max_model_len', 'top_provider.context_length', 'max_output_tokens',
            'max_completion_tokens', 'top_provider.max_completion_tokens', 'supports_thinking', 'capabilities.thinking',
            'capabilities.supports_thinking', 'supported_parameters'] as $field) { $sourceValues[] = 'provider: ' . $field; }
        $warningValues = ['Input allowance is a conservative UTF-8 byte budget, not an exact provider tokenizer or context guarantee.',
            'Context capacity is unknown; local limits require provider verification.',
            'Reviewed MiniMax-M3 recommendation: disable thinking; verify endpoint compatibility before use.',
            'Unverified reasoning options use provider defaults.'];
        foreach ($models as $row) {
            if (!is_array($row) || !ModelCapabilities::safeId($id = $row['id'] ?? null) || isset($seen['id:' . $id])) {
                throw new RuntimeException('catalog_invalid_models', 422);
            }
            $seen['id:' . $id] = true;
            $name = $row['name'] ?? null;
            if ($name !== $id && !ModelCapabilities::safeName($name)) {
                throw new RuntimeException('catalog_invalid_models', 422);
            }
            foreach (['context_window', 'max_output_tokens'] as $field) {
                if (!array_key_exists($field, $row) || ($row[$field] !== null && (!is_int($row[$field]) || $row[$field] < 1 || $row[$field] > 1000000000))) {
                    throw new RuntimeException('catalog_invalid_models', 422);
                }
            }
            if (!array_key_exists('supports_thinking', $row) || ($row['supports_thinking'] !== null && !is_bool($row['supports_thinking']))) {
                throw new RuntimeException('catalog_invalid_models', 422);
            }
            $defaults = $row['defaults'] ?? null;
            if (!is_array($defaults) || count($defaults) !== 6) { throw new RuntimeException('catalog_invalid_models', 422); }
            foreach (['max_input_tokens' => [512, 131072], 'max_output_tokens' => [1, 32768]] as $key => [$min, $max]) {
                if (!is_int($defaults[$key] ?? null) || $defaults[$key] < $min || $defaults[$key] > $max) { throw new RuntimeException('catalog_invalid_models', 422); }
            }
            foreach (['thinking_mode' => ['', 'disabled', 'enabled'], 'reasoning_effort' => ['', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh'],
                'token_field' => ['max_tokens', 'max_completion_tokens'], 'structured_output' => ['none', 'json_object']] as $key => $values) {
                if (!in_array($defaults[$key] ?? null, $values, true)) { throw new RuntimeException('catalog_invalid_models', 422); }
            }
            $sources = $row['sources'] ?? null; $warnings = $row['warnings'] ?? null;
            $sourceKeys = ['context_window', 'max_output_tokens', 'supports_thinking', 'defaults.max_input_tokens', 'defaults.max_output_tokens',
                'defaults.thinking_mode', 'defaults.reasoning_effort', 'defaults.token_field', 'defaults.structured_output'];
            if (!is_array($sources) || count($sources) !== count($sourceKeys) || array_diff($sourceKeys, array_keys($sources)) !== []
                || !is_array($warnings) || !array_is_list($warnings) || count($warnings) > 4) { throw new RuntimeException('catalog_invalid_models', 422); }
            foreach ($sources as $value) { if (!in_array($value, $sourceValues, true)) { throw new RuntimeException('catalog_invalid_models', 422); } }
            foreach ($warnings as $value) { if (!in_array($value, $warningValues, true)) { throw new RuntimeException('catalog_invalid_models', 422); } }
            $result[] = ['id' => $id, 'name' => $name, 'context_window' => $row['context_window'], 'max_output_tokens' => $row['max_output_tokens'],
                'supports_thinking' => $row['supports_thinking'], 'defaults' => $defaults, 'sources' => $sources, 'warnings' => $warnings];
        }
        usort($result, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        return $result;
    }

    /** One lock protects key creation and all preview/catalog/rate read-modify-write operations. */
    private function locked(callable $work): mixed
    {
        $lock = null;
        try {
            $this->noLinks($this->directory);
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) { throw new AiFailure('store_unavailable'); }
            @chmod($this->directory, 0700);
            $lockPath = $this->directory . '/catalog.lock';
            $keyPath = $this->directory . '/catalog.key';
            $this->noLinks($lockPath); $this->noLinks($keyPath);
            $lock = @fopen($lockPath, 'c+b');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { throw new AiFailure('settings_busy', 409); }
            @chmod($lockPath, 0600);
            $marker = stream_get_contents($lock, 32);
            if (!is_file($keyPath)) {
                if ($marker !== '' || glob($this->directory . '/*.enc') !== []) { throw new AiFailure('store_unavailable'); }
                $keyFile = @fopen($keyPath, 'x+b');
                if ($keyFile === false) { throw new AiFailure('store_unavailable'); }
                try {
                    @chmod($keyPath, 0600); $key = base64_encode(random_bytes(32));
                    if (fwrite($keyFile, $key) !== strlen($key) || !fflush($keyFile)) { throw new AiFailure('store_unavailable'); }
                } finally { fclose($keyFile); }
            }
            $key = @file_get_contents($keyPath, false, null, 0, 129);
            if (!is_string($key) || strlen($key) !== 44) { throw new AiFailure('store_unavailable'); }
            $cipher = new PayloadCipher($key);
            if (!$cipher->ready()) { throw new AiFailure('store_unavailable'); }
            if ($marker === '') {
                if (!rewind($lock) || fwrite($lock, 'initialized') !== 11 || !fflush($lock)) { throw new AiFailure('store_unavailable'); }
            } elseif ($marker !== 'initialized') { throw new AiFailure('store_unavailable'); }
            return $work($cipher);
        } catch (RuntimeException $error) { throw $error; }
        catch (Throwable) { throw new AiFailure('store_unavailable'); }
        finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
    }

    /** Reject linked ancestors as well as leaf files, including dangling symlinks. */
    private function noLinks(string $path): void
    {
        do {
            clearstatcache(true, $path);
            if (is_link($path)) { throw new AiFailure('store_unavailable'); }
            $parent = dirname($path);
            if ($parent === $path) { break; }
            $path = $parent;
        } while ($path !== '.' && $path !== '');
    }

    private function read(PayloadCipher $cipher, int $tenant, string $kind): ?array
    {
        $path = $this->directory . '/' . $tenant . '.' . $kind . '.enc';
        $this->noLinks($path);
        if (!file_exists($path)) { return null; }
        if (!is_file($path)) { throw new AiFailure('store_unavailable'); }
        $bytes = @file_get_contents($path, false, null, 0, 2097153);
        if (!is_string($bytes) || strlen($bytes) > 2097152) { throw new AiFailure('store_unavailable'); }
        return $cipher->decrypt($tenant, 'model-catalog', $kind, $bytes);
    }

    private function write(#[\SensitiveParameter] PayloadCipher $cipher, int $tenant, string $kind, #[\SensitiveParameter] array $record): void
    {
        $path = $this->directory . '/' . $tenant . '.' . $kind . '.enc';
        $this->noLinks($path);
        $bytes = $cipher->encrypt($tenant, 'model-catalog', $kind, $record);
        if (strlen($bytes) > 2097152) { throw new AiFailure('store_unavailable'); }
        $temp = $path . '.' . bin2hex(random_bytes(12)) . '.tmp';
        try {
            $file = @fopen($temp, 'x+b');
            if ($file === false) { throw new AiFailure('store_unavailable'); }
            try {
                @chmod($temp, 0600);
                if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file)) { throw new AiFailure('store_unavailable'); }
            } finally { fclose($file); }
            if (!@rename($temp, $path)) { throw new AiFailure('store_unavailable'); }
        } finally { if (is_file($temp)) { @unlink($temp); } }
    }
}
