<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Closure;
use JsonException;
use Throwable;

final class ModelDiscovery
{
    private readonly Closure $transport;
    private readonly EndpointPolicy $policy;

    /** Transport receives (Profile, endpoint, generated headers, empty body, receive, 'GET'). No retries. */
    public function __construct(#[\SensitiveParameter] private readonly Profile $profile, ?callable $transport = null, ?callable $resolver = null)
    {
        $this->transport = Closure::fromCallable($transport ?? new CurlTransport());
        $this->policy = new EndpointPolicy($profile, $resolver);
    }

    /** Caller authorizes tenant/actor and calls ModelCatalogStore::reserveDiscovery BEFORE this method. */
    public function discover(): array
    {
        $this->profile->assertUnchanged();
        $c = $this->profile->config;
        if ($c->baseUrl === '' || ($c->authMode !== 'none' && $c->apiKey === '')) {
            throw new AiFailure('invalid_config');
        }
        $endpoint = $this->policy->validate();
        // Replace only the fixed suffix introduced by EndpointPolicy, not any matching base path.
        $suffix = '/chat/completions';
        if (!str_ends_with($endpoint['url'], $suffix)) { throw new AiFailure('invalid_config'); }
        $endpoint['url'] = substr($endpoint['url'], 0, -strlen($suffix)) . '/models';
        $headers = ['Accept: application/json', 'Expect:'];
        if ($c->authMode === 'bearer') { $headers[] = 'Authorization: Bearer ' . $c->apiKey; }
        elseif ($c->authMode === 'api_key_header') { $headers[] = $c->apiKeyHeader . ': ' . $c->apiKey; }
        $response = '';
        $overflow = false;
        $receive = static function (#[\SensitiveParameter] string $chunk) use (&$response, &$overflow, $c): void {
            if ($overflow || strlen($chunk) > $c->maxResponseBytes - strlen($response)) {
                $overflow = true;
                throw new AiFailure('response_too_large');
            }
            $response .= $chunk;
        };
        try {
            $status = ($this->transport)($this->profile, $endpoint, $headers, '', $receive, 'GET');
        } catch (Throwable $error) {
            throw new AiFailure($overflow ? 'response_too_large' : ($error instanceof AiFailure ? $error->reason : 'transport_failed'));
        }
        if ($overflow) { throw new AiFailure('response_too_large'); }
        if (!is_int($status) || $status < 100 || $status > 599) { throw new AiFailure('transport_failed'); }
        if (in_array($status, [401, 403], true)) { throw new AiFailure('provider_auth_failed'); }
        if ($status === 429) { throw new AiFailure('provider_rate_limited'); }
        if ($status >= 500) { throw new AiFailure('provider_unavailable'); }
        if ($status !== 200) { throw new AiFailure('provider_rejected'); }
        try { $json = json_decode($response, false, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new AiFailure('invalid_response'); }
        if (!$json instanceof \stdClass || property_exists($json, 'error') || !is_array($json->data ?? null)) {
            throw new AiFailure('invalid_response');
        }
        if (count($json->data) > ModelCapabilities::MAX_MODELS) { throw new \RuntimeException('catalog_too_large', 422); }
        $models = [];
        foreach ($json->data as $row) {
            if (!$row instanceof \stdClass) { throw new AiFailure('invalid_response'); }
            // Decode to arrays only after verifying the wire document's object/list distinction.
            $models[] = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        }
        return ModelCapabilities::normalize($models, $this->profile);
    }
}
