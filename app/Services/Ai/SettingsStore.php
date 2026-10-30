<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Config\Ai;
use Throwable;

/** One deployment profile, encrypted outside the document root; never returns a saved secret to the UI. */
final class SettingsStore
{
    private readonly string $directory;
    private readonly Ai $defaults;

    public function __construct(?string $directory = null, #[\SensitiveParameter] ?Ai $defaults = null)
    {
        $this->directory = $directory ?? WRITEPATH . 'ai-settings';
        $this->defaults = clone ($defaults ?? new Ai());
    }

    public static function effectiveConfig(): Ai { return (new self())->configuration(); }

    public function configuration(): Ai
    {
        return $this->locked(false, fn (#[\SensitiveParameter] ?array $record): Ai => $record === null ? clone $this->defaults : $this->build($record));
    }

    public function summary(int $tenantId): array
    {
        return $this->locked(false, function (#[\SensitiveParameter] ?array $record) use ($tenantId): array {
            $this->owner($record, $tenantId);
            $c = $record === null ? clone $this->defaults : $this->build($record, false);
            $tested = $record !== null && hash_equals(Profile::fromConfig($this->build($record, false))->revision(), (string) $record['tested_revision']);
            return ['saved' => $record !== null, 'version' => $record['version'] ?? '', 'base_url' => $c->baseUrl,
                'model' => $c->model, 'has_api_key' => $c->apiKey !== '', 'reasoning_effort' => $c->reasoningEffort,
                'thinking_mode' => $c->thinkingMode,
                'max_input_tokens' => $c->maxInputTokens, 'max_output_tokens' => $c->maxOutputTokens,
                'auth_mode' => $c->authMode, 'token_field' => $c->maxOutputTokensField,
                'structured_output' => $c->structuredOutputMode, 'allow_private_network' => $record['allow_private_network'] ?? false,
                'approved_units' => $record === null ? Profile::csv($c->approvedUnits) : $this->recordUnits($record),
                'connection_tested' => $tested, 'tested_at' => $record['tested_at'] ?? null,
                'enabled' => $tested && ($record['enabled'] ?? false), 'storage_key_ready' => $c->encryptionKey !== ''];
        });
    }

    public function save(int $tenantId, int $actorId, #[\SensitiveParameter] array $input): array
    {
        $allowed = ['version', 'base_url', 'api_key', 'remove_api_key', 'model', 'reasoning_effort', 'thinking_mode', 'max_input_tokens',
            'max_output_tokens', 'auth_mode', 'token_field', 'structured_output', 'allow_private_network',
            'unit_selection', 'allow_clinic', 'allow_guidance', 'allow_bmg'];
        if (array_diff(array_keys($input), $allowed) !== []) { throw new AiFailure('invalid_settings', 422); }
        foreach ($input as $field => $value) {
            if ($field === 'version' && !is_string($value)) { throw new AiFailure('settings_conflict', 409); }
            $numeric = in_array($field, ['max_input_tokens', 'max_output_tokens', 'allow_private_network', 'remove_api_key',
                'unit_selection', 'allow_clinic', 'allow_guidance', 'allow_bmg'], true);
            if ((!$numeric && !is_string($value)) || ($numeric && !is_int($value) && !is_string($value))) {
                throw new AiFailure('invalid_settings', 422);
            }
        }
        foreach (['unit_selection', 'allow_clinic', 'allow_guidance', 'allow_bmg'] as $field) {
            if (array_key_exists($field, $input)) {
                $selected = $this->flag($input[$field]);
                if ($field === 'unit_selection' && !$selected) { throw new AiFailure('invalid_settings', 422); }
            }
        }
        $this->locked(true, function (#[\SensitiveParameter] ?array $old) use ($tenantId, $actorId, $input): array {
            $this->owner($old, $tenantId);
            $this->version($old, $input['version'] ?? '');
            if ($actorId < 1) { throw new AiFailure('access_denied', 403); }
            $baseUrl = $this->text($input['base_url'] ?? '', 2048);
            $endpoint = EndpointPolicy::parseBaseUrl($baseUrl);
            $private = $this->flag($input['allow_private_network'] ?? '0');
            if ($endpoint['scheme'] === 'http' && !$private) { throw new AiFailure('https_required', 422); }
            $providedKey = $this->text($input['api_key'] ?? '', 4096, true);
            $removeKey = $this->flag($input['remove_api_key'] ?? '0');
            if ($removeKey && $providedKey !== '') { throw new AiFailure('invalid_settings', 422); }
            $units = $this->recordUnits($old ?? []);
            if (array_key_exists('unit_selection', $input)) {
                $units = [];
                foreach (['clinic', 'guidance', 'bmg'] as $unit) {
                    if ($this->flag($input['allow_' . $unit] ?? '0')) { $units[] = $unit; }
                }
                if ($units === []) { throw new AiFailure('invalid_settings', 422); }
            }
            $oldKey = $old['api_key'] ?? $this->defaults->apiKey;
            $key = $removeKey ? '' : ($providedKey !== '' ? $providedKey : $oldKey);
            $record = ['schema' => 1, 'tenant_id' => $tenantId, 'version' => bin2hex(random_bytes(16)),
                'base_url' => $baseUrl, 'model' => $this->text($input['model'] ?? '', 191), 'api_key' => $key,
                'auth_mode' => $this->text($input['auth_mode'] ?? 'bearer', 32),
                'reasoning_effort' => $this->text($input['reasoning_effort'] ?? '', 32, true),
                'thinking_mode' => $this->text($input['thinking_mode'] ?? '', 32, true),
                'max_input_tokens' => $this->integer($input['max_input_tokens'] ?? 8192, 512, 131072),
                'max_output_tokens' => $this->integer($input['max_output_tokens'] ?? 2000, 1, 32768),
                'token_field' => $this->text($input['token_field'] ?? 'max_tokens', 40),
                'structured_output' => $this->text($input['structured_output'] ?? 'none', 32),
                'allow_private_network' => $private, 'approved_units' => $units,
                // Storage key is independent of the API key and survives provider changes.
                'storage_key' => $old['storage_key'] ?? ($this->defaults->encryptionKey !== '' ? $this->defaults->encryptionKey : base64_encode(random_bytes(32))),
                'enabled' => false, 'tested_revision' => '', 'tested_at' => null,
                'history' => $old['history'] ?? []];
            $candidate = Profile::fromConfig($this->build($record, false));
            $candidate->assertCompletionConfigured(true);
            $this->audit($record, $actorId, 'saved');
            return $record;
        });
        return $this->summary($tenantId);
    }

    /** Preview unsaved connection details without persisting credentials or changing approvals. */
    public function discoveryProfile(int $tenantId, #[\SensitiveParameter] array $input): Profile
    {
        $allowed = ['version', 'base_url', 'api_key', 'remove_api_key', 'auth_mode', 'allow_private_network'];
        if (array_diff(array_keys($input), $allowed) !== []) { throw new AiFailure('invalid_settings', 422); }
        foreach ($input as $field => $value) {
            if ($field === 'version' && !is_string($value)) { throw new AiFailure('settings_conflict', 409); }
            if (in_array($field, ['remove_api_key', 'allow_private_network'], true)) {
                $this->flag($value);
            } elseif (!is_string($value)) { throw new AiFailure('invalid_settings', 422); }
        }
        return $this->locked(false, function (#[\SensitiveParameter] ?array $record) use ($tenantId, $input): Profile {
            $this->owner($record, $tenantId);
            $this->version($record, $input['version'] ?? '');
            $base = $this->text($input['base_url'] ?? '', 2048);
            $endpoint = EndpointPolicy::parseBaseUrl($base);
            $private = $this->flag($input['allow_private_network'] ?? '0');
            if ($endpoint['scheme'] === 'http' && !$private) { throw new AiFailure('https_required', 422); }
            $mode = $this->text($input['auth_mode'] ?? 'bearer', 32);
            $key = $this->text($input['api_key'] ?? '', 4096, true);
            $remove = $this->flag($input['remove_api_key'] ?? '0');
            if ($remove && $key !== '') { throw new AiFailure('invalid_settings', 422); }
            $saved = $record === null ? clone $this->defaults : $this->build($record, false);
            if (!$remove && $key === '' && $mode !== 'none' && $saved->apiKey !== '') {
                // A blank field must never forward an existing secret to a newly entered endpoint.
                $same = $saved->baseUrl !== ''
                    && EndpointPolicy::parseBaseUrl($saved->baseUrl)['url'] === $endpoint['url']
                    && $saved->authMode === $mode;
                if (!$same) { throw new AiFailure('discovery_key_required', 422); }
                $key = $saved->apiKey;
            }
            $c = clone $saved;
            $c->baseUrl = $base;
            $c->apiKey = $remove ? '' : $key;
            $c->authMode = $mode;
            $c->allowedOrigins = $endpoint['origin'];
            $c->privateOrigins = $private ? $endpoint['origin'] : '';
            $c->allowLocalHttp = $private && $endpoint['scheme'] === 'http';
            $c->allowPrivateHttpDispatch = $c->allowLocalHttp;
            $c->enabled = false;
            $c->governanceApproved = false;
            $c->programLibraryApproved = false;
            $c->approvedRevision = '';
            $c->compatibilityApprovedRevision = '';
            // Discovery does not require a selected completion model.
            $profile = Profile::fromConfig($c);
            if ($mode !== 'none' && $c->apiKey === '') { throw new AiFailure('discovery_key_required', 422); }
            return $profile;
        });
    }

    public function testingProfile(int $tenantId, string $version): Profile
    {
        return $this->locked(false, function (#[\SensitiveParameter] ?array $record) use ($tenantId, $version): Profile {
            $this->owner($record, $tenantId); $this->version($record, $version);
            if ($record === null) { throw new AiFailure('settings_not_saved', 422); }
            return Profile::fromConfig($this->build($record, false));
        });
    }

    public function markTested(int $tenantId, int $actorId, string $version, string $revision): array
    {
        $this->locked(true, function (#[\SensitiveParameter] ?array $record) use ($tenantId, $actorId, $version, $revision): array {
            $this->owner($record, $tenantId); $this->version($record, $version);
            if ($record === null || !hash_equals(Profile::fromConfig($this->build($record, false))->revision(), $revision)) {
                throw new AiFailure('settings_conflict', 409);
            }
            $record['tested_revision'] = $revision; $record['tested_at'] = gmdate('c'); $record['enabled'] = false;
            $this->audit($record, $actorId, 'connection_tested');
            return $record;
        });
        return $this->summary($tenantId);
    }

    public function enable(int $tenantId, int $actorId, string $version): Profile
    {
        $this->locked(true, function (#[\SensitiveParameter] ?array $record) use ($tenantId, $actorId, $version): array {
            $this->owner($record, $tenantId); $this->version($record, $version);
            if ($record === null || !hash_equals(Profile::fromConfig($this->build($record, false))->revision(), (string) $record['tested_revision'])) {
                throw new AiFailure('compatibility_required', 422);
            }
            $record['enabled'] = true;
            $candidate = Profile::fromConfig($this->build($record));
            $candidate->assertDispatchAllowed($tenantId, $candidate->approvedUnits()[0]);
            (new InsightGenerator($candidate))->assertProfile($candidate);
            $this->audit($record, $actorId, 'analytics_enabled');
            return $record;
        });
        return Profile::fromConfig($this->configuration());
    }

    public function disable(int $tenantId, int $actorId, string $version): void
    {
        $this->locked(true, function (#[\SensitiveParameter] ?array $record) use ($tenantId, $actorId, $version): array {
            $this->owner($record, $tenantId); $this->version($record, $version);
            if ($record === null) { throw new AiFailure('settings_not_saved', 422); }
            $record['enabled'] = false; $this->audit($record, $actorId, 'analytics_disabled');
            return $record;
        });
    }

    private function build(#[\SensitiveParameter] array $record, ?bool $enabled = null): Ai
    {
        $c = clone $this->defaults;
        foreach (['baseUrl' => 'base_url', 'model' => 'model', 'apiKey' => 'api_key', 'authMode' => 'auth_mode',
            'reasoningEffort' => 'reasoning_effort', 'maxInputTokens' => 'max_input_tokens', 'maxOutputTokens' => 'max_output_tokens',
            'maxOutputTokensField' => 'token_field', 'structuredOutputMode' => 'structured_output', 'encryptionKey' => 'storage_key'] as $property => $key) {
            if (!array_key_exists($key, $record)) { throw new AiFailure('settings_unavailable'); }
            $c->$property = $record[$key];
        }
        // Older saved profiles used provider default, regardless of current deployment defaults.
        $c->thinkingMode = array_key_exists('thinking_mode', $record) ? $record['thinking_mode'] : '';
        $endpoint = EndpointPolicy::parseBaseUrl($c->baseUrl);
        $c->allowedOrigins = $endpoint['origin'];
        $c->privateOrigins = $record['allow_private_network'] ? $endpoint['origin'] : '';
        $c->allowLocalHttp = $record['allow_private_network'] && $endpoint['scheme'] === 'http';
        $c->allowPrivateHttpDispatch = $c->allowLocalHttp;
        $c->credentialVersion = $record['version'];
        $c->approvedTenants = (string) $record['tenant_id'];
        $c->approvedUnits = implode(',', $this->recordUnits($record));
        $c->capabilityRevision = ProgramLibrary::version() . ':' . InsightGenerator::PROMPT_VERSION;
        $c->enabled = $enabled ?? $record['enabled'];
        $c->governanceApproved = $c->enabled;
        $c->programLibraryApproved = $c->enabled;
        $c->approvedRevision = ''; $c->compatibilityApprovedRevision = '';
        // Use the installed sibling CLI binary automatically; never search PATH or run a user command.
        if ($c->resolverPhpBinary === '') {
            foreach ([PHP_BINDIR . '/php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), dirname(PHP_BINARY) . '/php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '')] as $binary) {
                if (is_file($binary) && is_readable($binary) && is_executable($binary)) { $c->resolverPhpBinary = (string) realpath($binary); break; }
            }
        }
        if ($c->enabled) {
            $test = clone $c; $test->enabled = false; $test->governanceApproved = false; $test->programLibraryApproved = false;
            if (!hash_equals(Profile::fromConfig($test)->revision(), (string) $record['tested_revision'])) { throw new AiFailure('compatibility_required'); }
            $revision = Profile::fromConfig($c)->revision();
            $c->approvedRevision = $revision; $c->compatibilityApprovedRevision = $revision;
        }
        return $c;
    }

    /** @return non-empty-list<string> */
    private function recordUnits(#[\SensitiveParameter] array $record): array
    {
        if (!array_key_exists('approved_units', $record)) { return ['clinic']; }
        $units = $record['approved_units'];
        if (!is_array($units) || !array_is_list($units) || $units === [] || count($units) > 3) {
            throw new AiFailure('settings_unavailable');
        }
        $seen = [];
        foreach ($units as $unit) {
            if (!is_string($unit) || !in_array($unit, ['clinic', 'guidance', 'bmg'], true)
                || AnalysisCatalog::forUnit($unit) === [] || in_array($unit, $seen, true)) {
                throw new AiFailure('settings_unavailable');
            }
            $seen[] = $unit;
        }
        return $units;
    }

    private function owner(#[\SensitiveParameter] ?array $record, int $tenantId): void
    {
        if ($tenantId < 1 || ($record !== null && $record['tenant_id'] !== $tenantId)) { throw new AiFailure('access_denied', 403); }
    }

    private function version(#[\SensitiveParameter] ?array $record, mixed $expected): void
    {
        if (!is_string($expected) || !hash_equals($record['version'] ?? '', $expected)) { throw new AiFailure('settings_conflict', 409); }
    }

    private function audit(#[\SensitiveParameter] array &$record, int $actorId, string $action): void
    {
        if ($actorId < 1) { throw new AiFailure('access_denied', 403); }
        $record['history'][] = ['actor_id' => $actorId, 'action' => $action, 'at' => gmdate('c'), 'version' => $record['version']];
        $record['history'] = array_slice($record['history'], -200);
    }

    private function text(#[\SensitiveParameter] mixed $value, int $length, bool $empty = false): string
    {
        if (!is_string($value)) { throw new AiFailure('invalid_settings', 422); }
        $value = trim($value);
        if ((!$empty && $value === '') || strlen($value) > $length || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{C}]/u', $value)) {
            throw new AiFailure('invalid_settings', 422);
        }
        return $value;
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) { throw new AiFailure('invalid_settings', 422); }
        return (int) $value;
    }

    private function flag(mixed $value): bool
    {
        if (!in_array($value, ['0', '1', 0, 1], true)) { throw new AiFailure('invalid_settings', 422); }
        return (string) $value === '1';
    }

    private function locked(bool $write, #[\SensitiveParameter] callable $work): mixed
    {
        $file = $this->directory . '/provider.enc';
        if (!$write && !is_file($file)) {
            if (@file_get_contents($this->directory . '/settings.lock', false, null, 0, 32) === 'initialized') { throw new AiFailure('settings_unavailable'); }
            return $work(null);
        }
        $lock = null;
        try {
            if (is_link($this->directory) || (!$write && !is_dir($this->directory))) { throw new AiFailure('settings_unavailable'); }
            if ($write && !is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) { throw new AiFailure('settings_unavailable'); }
            foreach (['provider.enc', 'settings.key', 'settings.lock'] as $name) {
                if (is_link($this->directory . '/' . $name)) { throw new AiFailure('settings_unavailable'); }
            }
            $lock = @fopen($this->directory . '/settings.lock', $write ? 'c+b' : 'rb');
            if ($lock === false || !flock($lock, ($write ? LOCK_EX : LOCK_SH) | LOCK_NB)) { throw new AiFailure('settings_busy', 409); }
            if (!is_file($file) && stream_get_contents($lock, 32) === 'initialized') {
                // Losing the encrypted profile must never silently replace the storage key.
                throw new AiFailure('settings_unavailable');
            }
            if ($write) { @chmod($this->directory, 0700); @chmod($this->directory . '/settings.lock', 0600); }
            $keyPath = $this->directory . '/settings.key';
            if (!is_file($keyPath)) {
                if (!$write || is_file($file)) { throw new AiFailure('settings_unavailable'); }
                $keyFile = @fopen($keyPath, 'x+b');
                if ($keyFile === false) { throw new AiFailure('settings_unavailable'); }
                try {
                    @chmod($keyPath, 0600); $key = base64_encode(random_bytes(32));
                    if (fwrite($keyFile, $key) !== strlen($key) || !fflush($keyFile)) { throw new AiFailure('settings_unavailable'); }
                } finally { fclose($keyFile); }
            }
            if (filesize($keyPath) > 128) { throw new AiFailure('settings_unavailable'); }
            $key = @file_get_contents($keyPath);
            if (!is_string($key)) { throw new AiFailure('settings_unavailable'); }
            $cipher = new PayloadCipher($key);
            $record = null;
            if (is_file($file)) {
                if (filesize($file) > 262144) { throw new AiFailure('settings_unavailable'); }
                $encrypted = @file_get_contents($file);
                if (!is_string($encrypted)) { throw new AiFailure('settings_unavailable'); }
                $record = $cipher->decrypt(1, 'configuration', 'provider-settings', $encrypted);
                if (($record['schema'] ?? null) !== 1 || !is_int($record['tenant_id'] ?? null)
                    || !is_string($record['version'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $record['version'])
                    || !is_bool($record['enabled'] ?? null) || !is_bool($record['allow_private_network'] ?? null)
                    || !is_array($record['history'] ?? null)) { throw new AiFailure('settings_unavailable'); }
                $this->recordUnits($record);
            }
            $result = $work($record);
            if ($write) {
                $encoded = $cipher->encrypt(1, 'configuration', 'provider-settings', $result);
                $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
                try {
                    $handle = @fopen($temporary, 'x+b');
                    if ($handle === false) { throw new AiFailure('settings_unavailable'); }
                    try {
                        @chmod($temporary, 0600);
                        if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) { throw new AiFailure('settings_unavailable'); }
                    } finally { fclose($handle); }
                    if (!@rename($temporary, $file)) { throw new AiFailure('settings_unavailable'); }
                    if (!rewind($lock) || !ftruncate($lock, 0) || fwrite($lock, 'initialized') !== 11 || !fflush($lock)) {
                        throw new AiFailure('settings_unavailable');
                    }
                } finally { if (is_file($temporary)) { @unlink($temporary); } }
            }
            return $result;
        } catch (AiFailure $error) { throw $error; }
        catch (Throwable) { throw new AiFailure('settings_unavailable'); }
        finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
    }
}
