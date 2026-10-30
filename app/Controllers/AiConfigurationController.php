<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Filters\WebCsrfFilter;
use App\Services\Ai\AiAccess;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AiGrantStore;
use App\Services\Ai\DeploymentControl;
use App\Services\Ai\InsightStore;
use App\Services\Ai\ModelCatalogStore;
use App\Services\Ai\ModelDiscovery;
use App\Services\Ai\Profile;
use App\Services\Ai\SettingsStore;
use App\Services\Ai\SyntheticProbe;
use App\Services\AuthenticatedUser;
use Throwable;

class AiConfigurationController extends BaseController
{
    public function index()
    {
        if (strtoupper($this->request->getMethod()) !== 'GET') { return $this->methodNotAllowed('GET'); }
        try {
            $actor = $this->actor('ai.configuration.read');
            $settings = $this->summary($actor);
            try { $status = $this->profile()->publicStatus(); }
            catch (AiFailure $error) { $status = ['configuration_error' => $error->reason]; }
            $status['settings'] = $settings;
            $status['access'] = $this->accessSummary($actor);
            $status['catalog'] = $this->catalogSummary($actor);
            return $this->response->setHeader('Cache-Control', 'private, no-store')->setBody($this->renderStatus($status));
        } catch (Throwable $error) { return $this->failure($error); }
    }

    public function status()
    {
        if (strtoupper($this->request->getMethod()) !== 'GET') { return $this->methodNotAllowed('GET'); }
        try {
            $actor = $this->actor('ai.configuration.read');
            return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON(['success' => true,
                'settings' => $this->summary($actor), 'access' => $this->accessSummary($actor),
                'catalog' => $this->catalogSummary($actor),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
        } catch (Throwable $error) { return $this->failure($error); }
    }

    public function save()
    {
        return $this->mutation('ai.configuration.write', function (array $actor): array {
            $input = $this->input(['version', 'base_url', 'api_key', 'remove_api_key', 'model', 'reasoning_effort', 'thinking_mode',
                'max_input_tokens', 'max_output_tokens', 'auth_mode', 'token_field', 'structured_output', 'allow_private_network',
                'unit_selection', 'allow_clinic', 'allow_guidance', 'allow_bmg']);
            $this->control()->pause();
            $settings = $this->settings()->save((int) $actor['tenant_id'], (int) $actor['id'], $input);
            return ['message' => 'AI settings saved securely. Test the connection next. Aggregate analytics is paused until you enable it.', 'settings' => $settings];
        });
    }

    public function previewModels()
    {
        if (strtoupper($this->request->getMethod()) !== 'POST') { return $this->methodNotAllowed('POST'); }
        try {
            $actor = $this->actor('ai.configuration.write');
            if (($denied = $this->verifyCsrf()) !== null) { return $denied; }
            $input = $this->input(['version', 'base_url', 'api_key', 'remove_api_key', 'auth_mode', 'allow_private_network']);
            $profile = $this->settings()->discoveryProfile((int) $actor['tenant_id'], $input);
            $catalog = $this->catalog();
            $catalog->reserveDiscovery((int) $actor['tenant_id'], (int) $actor['id']);
            $csrf = ['name' => csrf_token(), 'hash' => csrf_hash()];
            $this->releaseSession();
            $models = $this->discoverModels($profile);
            $fresh = AiAccess::assertActor($this->freshIdentity((int) $actor['id'], (int) $actor['tenant_id']),
                (int) $actor['tenant_id'], 'ai.configuration.write');
            if ((int) $fresh['id'] !== (int) $actor['id'] || ($fresh['auth_version'] ?? null) !== ($actor['auth_version'] ?? null)) {
                throw new AiFailure('authentication_required', 401);
            }
            $current = $this->settings()->discoveryProfile((int) $actor['tenant_id'], $input);
            if (!hash_equals($profile->revision(), $current->revision())) { throw new AiFailure('settings_conflict', 409); }
            $preview = $catalog->stage((int) $actor['tenant_id'], (int) $actor['id'], $profile->config->baseUrl, $models);
            return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON(['success' => true,
                'preview' => $preview, 'message' => count($models) . ' models discovered. Select models to import; provider settings and analytics are unchanged.',
                'csrf' => $csrf]);
        } catch (Throwable $error) { return $this->failure($error); }
    }

    public function importModels()
    {
        return $this->mutation('ai.configuration.write', function (array $actor): array {
            $input = $this->input(['preview_token', 'model_ids', 'import_all']);
            if (!is_string($input['preview_token'] ?? null) || !is_string($input['model_ids'] ?? null)
                || strlen($input['model_ids']) > 65536 || !in_array($input['import_all'] ?? null, ['0', '1'], true)) {
                throw new AiFailure('invalid_request', 422);
            }
            try { $ids = json_decode($input['model_ids'], true, 3, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new AiFailure('invalid_request', 422); }
            if (!is_array($ids) || !array_is_list($ids)) { throw new AiFailure('invalid_request', 422); }
            $catalog = $this->catalog()->import((int) $actor['tenant_id'], (int) $actor['id'], $input['preview_token'], $ids, $input['import_all'] === '1');
            return ['catalog' => $catalog, 'message' => $catalog['imported_count'] . ' models imported. Choose an imported model to fill recommended settings, then save and test.'];
        });
    }

    public function access()
    {
        return $this->mutation('ai.configuration.write', function (array $actor): array {
            $input = $this->input(['operation', 'unit', 'role', 'permission']);
            foreach (['operation', 'unit', 'role', 'permission'] as $field) {
                if (!is_string($input[$field] ?? null)) { throw new AiFailure('invalid_request', 422); }
            }
            if (!in_array($input['operation'], ['grant', 'revoke'], true)) { throw new AiFailure('invalid_request', 422); }
            $this->grants()->{$input['operation']}($actor, $input['unit'], $input['role'], $input['permission']);
            return ['message' => $input['operation'] === 'grant'
                ? 'Department AI permission granted for this tenant only. Source-data permissions are unchanged.'
                : 'Department AI permission revoked for this tenant. Existing explicit RBAC permissions are unchanged.',
                'access' => $this->accessSummary($actor)];
        });
    }

    public function enable()
    {
        return $this->mutation('ai.configuration.write', function (array $actor): array {
            $input = $this->input(['version', 'confirm_analytics']);
            if (($input['confirm_analytics'] ?? null) !== '1') { throw new AiFailure('privacy_confirmation_required', 422); }
            $version = $this->version($input);
            $profile = $this->settings()->enable((int) $actor['tenant_id'], (int) $actor['id'], $version);
            try {
                if (!$this->storageReady()) { throw new AiFailure('store_unavailable'); }
                $this->control()->resume($profile, (int) $actor['tenant_id']);
            } catch (Throwable $error) {
                $this->control()->pause();
                $this->settings()->disable((int) $actor['tenant_id'], (int) $actor['id'], $version);
                throw $error;
            }
            return ['message' => 'Protected aggregate analytics enabled for this tenant. Patient charts and counseling notes remain excluded. Department source-data permissions still apply.',
                'settings' => $this->settings()->summary((int) $actor['tenant_id'])];
        });
    }

    public function disable()
    {
        return $this->mutation('ai.configuration.write', function (array $actor): array {
            $input = $this->input(['version']);
            $this->control()->pause();
            $this->settings()->disable((int) $actor['tenant_id'], (int) $actor['id'], $this->version($input));
            return ['message' => 'AI analytics paused. Your provider settings and saved key were kept. Already sent requests cannot be recalled.',
                'settings' => $this->settings()->summary((int) $actor['tenant_id'])];
        });
    }

    public function test()
    {
        if (strtoupper($this->request->getMethod()) !== 'POST') { return $this->methodNotAllowed('POST'); }
        try {
            $actor = $this->actor('ai.configuration.test');
            if (($denied = $this->verifyCsrf()) !== null) { return $denied; }
            $input = $this->input(['version']);
            $managed = array_key_exists('version', $input);
            if ($managed) {
                AiAccess::assertActor($actor, (int) $actor['tenant_id'], 'ai.configuration.write');
                $version = $this->version($input);
                $this->control()->pause();
                $this->settings()->disable((int) $actor['tenant_id'], (int) $actor['id'], $version);
                $profile = $this->settings()->testingProfile((int) $actor['tenant_id'], $version);
            } else { $profile = $this->profile(); }
            $revision = $profile->revision();
            $csrf = ['name' => csrf_token(), 'hash' => csrf_hash()];
            $this->releaseSession();
            $result = null; $failure = null;
            try { $result = $this->probe($profile, (int) $actor['tenant_id']); }
            catch (Throwable $error) { $failure = $error; }
            $fresh = AiAccess::assertActor($this->freshIdentity((int) $actor['id'], (int) $actor['tenant_id']),
                (int) $actor['tenant_id'], 'ai.configuration.test');
            if ((int) $fresh['id'] !== (int) $actor['id'] || ($fresh['auth_version'] ?? null) !== ($actor['auth_version'] ?? null)) {
                throw new AiFailure('authentication_required', 401);
            }
            if ($failure !== null) { throw $failure; }
            $profile->assertUnchanged();
            if (($result['success'] ?? null) !== true || ($result['verified'] ?? null) !== true) { throw new AiFailure('probe_failed'); }
            $payload = ['success' => true, 'verified' => true, 'revision' => $revision, 'csrf' => $csrf];
            if ($managed) {
                AiAccess::assertActor($fresh, (int) $actor['tenant_id'], 'ai.configuration.write');
                $payload['settings'] = $this->settings()->markTested((int) $actor['tenant_id'], (int) $actor['id'], $version, $revision);
                $payload['message'] = 'Connection successful. Endpoint, API key, model, and selected options worked. No patient data was sent. You can now enable aggregate analytics below.';
            } else {
                if (!hash_equals($revision, $this->profile()->revision())) { throw new AiFailure('revision_not_approved', 409); }
                $payload['message'] = 'Synthetic connection test passed. No patient data was sent.';
            }
            return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON($payload);
        } catch (Throwable $error) { return $this->failure($error); }
    }

    protected function actor(string $permission): array { return AiAccess::current($permission); }
    protected function freshIdentity(int $actorId, int $tenantId): ?array { return (new AuthenticatedUser())->forIdentity($actorId, $tenantId); }
    protected function profile(): Profile { return Profile::fromConfig(); }
    protected function settings(): SettingsStore { return new SettingsStore(); }
    protected function grants(): AiGrantStore { return new AiGrantStore(); }
    protected function catalog(): ModelCatalogStore { return new ModelCatalogStore(); }
    protected function discoverModels(Profile $profile): array { return (new ModelDiscovery($profile))->discover(); }
    protected function catalogSummary(array $actor): array
    {
        try { return $this->catalog()->summary((int) $actor['tenant_id']); }
        catch (Throwable) { return ['base_url' => '', 'models' => [], 'imported_at' => null, 'unavailable' => true]; }
    }
    protected function accessSummary(array $actor): array { return $this->grants()->summary((int) $actor['tenant_id']); }
    protected function summary(array $actor): array
    {
        $settings = $this->settings()->summary((int) $actor['tenant_id']);
        if ($settings['enabled']) {
            try { $this->control()->assertAllowed(Profile::fromConfig($this->settings()->configuration()), (int) $actor['tenant_id']); }
            catch (AiFailure) { $settings['enabled'] = false; }
        }
        return $settings;
    }
    protected function storageReady(): bool { return (new InsightStore())->ready(); }
    protected function control(): DeploymentControl { return new DeploymentControl(); }
    protected function probe(Profile $profile, int $tenantId): array { return (new SyntheticProbe($profile))->execute($tenantId); }
    protected function verifyCsrf() { return (new WebCsrfFilter())->before($this->request); }
    protected function releaseSession(): void { session()->close(); }
    protected function renderStatus(array $status): string { return view('admin/ai', ['title' => 'AI Settings', 'status' => $status, 'settings' => $status['settings'], 'access' => $status['access'], 'catalog' => $status['catalog']]); }

    private function mutation(string $permission, callable $action)
    {
        if (strtoupper($this->request->getMethod()) !== 'POST') { return $this->methodNotAllowed('POST'); }
        try {
            $actor = $this->actor($permission);
            if (($denied = $this->verifyCsrf()) !== null) { return $denied; }
            return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON(['success' => true] + $action($actor)
                + ['csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
        } catch (Throwable $error) { return $this->failure($error); }
    }

    private function input(array $allowed): array
    {
        $type = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'), 2)[0]));
        $input = $this->request->getPost();
        if (($type !== '' && $type !== 'application/x-www-form-urlencoded') || !is_array($input)
            || array_diff(array_keys($input), [...$allowed, csrf_token()]) !== [] || $this->request->getGet() !== []) { throw new AiFailure('invalid_request', 400); }
        unset($input[csrf_token()]);
        return $input;
    }

    private function version(#[\SensitiveParameter] array $input): string
    {
        $version = $input['version'] ?? null;
        if (!is_string($version) || !preg_match('/\A[a-f0-9]{32}\z/D', $version)) { throw new AiFailure('settings_not_saved', 422); }
        return $version;
    }

    private function methodNotAllowed(string $method)
    {
        return $this->response->setStatusCode(405)->setHeader('Allow', $method)->setHeader('Cache-Control', 'private, no-store')
            ->setJSON(['success' => false, 'message' => 'Method not allowed.']);
    }

    private function failure(Throwable $error)
    {
        if ($error instanceof AiFailure && in_array($error->reason, ['authentication_required', 'access_denied'], true)) {
            return AuthenticatedUser::denied($this->request, $error->reason === 'access_denied',
                $error->reason === 'access_denied' ? 'AI settings require platform administration access.' : AuthenticatedUser::AUTHENTICATION_MESSAGE,
                site_url($error->reason === 'access_denied' ? 'dashboard' : 'auth/login'))->setHeader('Cache-Control', 'private, no-store');
        }
        $catalogErrors = ['catalog_rate_limited', 'catalog_too_large', 'catalog_duplicate_id', 'catalog_invalid_selection',
            'catalog_preview_invalid', 'catalog_preview_expired', 'catalog_invalid_models'];
        $reason = $error instanceof AiFailure ? $error->reason
            : ($error instanceof \RuntimeException && in_array($error->getMessage(), $catalogErrors, true) ? $error->getMessage() : 'settings_unavailable');
        $status = $error instanceof AiFailure ? $error->getCode()
            : (in_array($reason, $catalogErrors, true) ? (in_array($error->getCode(), [409, 422, 429], true) ? $error->getCode() : 422) : 503);
        $message = match ($reason) {
            'discovery_key_required' => 'Enter an API key to fetch models. A saved key can only be reused for the same endpoint and authentication mode.',
            'catalog_rate_limited' => 'Model discovery is limited to ten requests per minute for this tenant. Wait before fetching again.',
            'catalog_too_large' => 'This endpoint advertises more models than the supported catalog limit of 200, or its catalog is too large. Use a filtered endpoint or enter a model ID manually.',
            'catalog_duplicate_id', 'catalog_invalid_models' => 'The endpoint returned an invalid or ambiguous model list. You can still enter an exact model ID manually.',
            'catalog_invalid_selection' => 'Select at least one model from the current preview, or use Import all.',
            'catalog_preview_invalid', 'catalog_preview_expired' => 'The model preview expired or changed. Fetch models again before importing.',
            'provider_auth_failed' => 'The endpoint rejected the API key. Check the key and authentication mode.',
            'provider_rejected', 'completion_rejected', 'probe_failed', 'invalid_response', 'structured_output_invalid' => 'The endpoint did not accept the selected model or options. Check the base API URL, model name, reasoning effort, and token field under Advanced.',
            'provider_unavailable', 'transport_failed', 'dns_failed' => 'The endpoint could not be reached. Check its address, server connectivity, certificate, or local-network setting.',
            'endpoint_not_allowed', 'address_not_allowed', 'https_required' => 'Use HTTPS for an external endpoint. For a trusted local/private server, explicitly enable the local-network option. Metadata and unsafe destinations are always blocked.',
            'settings_conflict', 'revision_not_approved' => 'These settings changed in another request. Reload the settings before continuing.',
            'settings_not_saved' => 'Save the endpoint and model settings before testing or enabling analytics.',
            'compatibility_required' => 'Test this saved configuration successfully before enabling analytics.',
            'privacy_confirmation_required' => 'Confirm that the configured provider may process protected aggregate analytics before enabling it.',
            'invalid_settings', 'invalid_config', 'invalid_request' => 'Check the settings: base API URL, model, authentication, reasoning option, and token limits must be valid. A blank key keeps the saved key; clear it explicitly for no-auth mode.',
            'input_budget_exceeded' => 'The input budget is too small for this request. Increase the input allowance; no text was truncated or sent.',
            'settings_busy', 'worker_busy' => 'Another settings operation or connection test is running. Wait before trying again.',
            'daily_budget_exceeded', 'provider_rate_limited' => 'The test or provider rate limit has been reached. Wait before testing again.',
            'store_unavailable' => 'The AI database schema or audit ledger is unavailable. No AI access changes or analytics activation were committed.',
            'independent_transaction_required' => 'AI access changes require a separate transaction. Reload before trying again.',
            default => 'AI settings could not be saved or tested. No secret values are included in this response. Check server storage permissions and try again.',
        };
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')
            ->setJSON(['success' => false, 'reason' => $reason, 'message' => $message, 'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
    }
}
