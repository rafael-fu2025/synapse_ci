<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Closure;
use JsonException;
use Throwable;

final class ChatClient
{
    private readonly Profile $profile;
    private readonly Closure $transport;
    private readonly EndpointPolicy $policy;

    /**
     * Transport: callable(Profile, array $endpoint, array $headers, string $body, callable $receive): int.
     * It must deliver chunks to receive(), not buffer or return a raw body. Resolver: callable(string): list<string>.
     * Worker owns authorization, quotas, leases and retries; this client never retries an ambiguous POST.
     */
    public function __construct(#[\SensitiveParameter] ?Profile $profile = null, ?callable $transport = null, ?callable $resolver = null)
    {
        $this->profile = $profile ?? Profile::fromConfig();
        $this->transport = Closure::fromCallable($transport ?? new CurlTransport());
        $this->policy = new EndpointPolicy($this->profile, $resolver);
    }

    /** Caller authorizes scope first; beforeDispatch rechecks lease/budget/pause AFTER bounded DNS. */
    public function complete(#[\SensitiveParameter] array $messages, ?callable $beforeDispatch = null): string
    {
        return $this->request($messages, false, $beforeDispatch);
    }

    /** Harmless fixture only. Success is compatibility evidence, never dispatch authorization. */
    public function probe(): array
    {
        $content = $this->request([
            ['role' => 'system', 'content' => 'This is a synthetic connection test. Return only the JSON object {"ok":true}.'],
            ['role' => 'user', 'content' => 'Return {"ok":true}. No personal or clinical data is present.'],
        ], true);
        try {
            $fixture = json_decode($content, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiFailure('probe_failed');
        }
        if (! $fixture instanceof \stdClass || get_object_vars($fixture) !== ['ok' => true]) {
            throw new AiFailure('probe_failed');
        }

        return ['success' => true, 'verified' => true];
    }

    private function request(#[\SensitiveParameter] array $messages, bool $synthetic, ?callable $beforeDispatch = null): string
    {
        $this->profile->assertCompletionConfigured($synthetic);
        $c = $this->profile->config;
        if (! array_is_list($messages) || count($messages) < 1 || count($messages) > 32) {
            throw new AiFailure('invalid_request');
        }
        $inputBytes = 0;
        $hasUser = false;
        foreach ($messages as $index => $message) {
            if (! is_array($message) || count($message) !== 2 || ! isset($message['role'], $message['content'])
                || ! is_string($message['role']) || ! is_string($message['content'])
                || ! in_array($message['role'], ['system', 'user'], true)
                || ($message['role'] === 'system' && $index !== 0) || trim($message['content']) === ''
                || str_contains($message['content'], "\0") || ! mb_check_encoding($message['content'], 'UTF-8')) {
                throw new AiFailure('invalid_request');
            }
            $hasUser = $hasUser || $message['role'] === 'user';
            $inputBytes += strlen($message['content']);
            if ($inputBytes > $c->maxRequestBytes) {
                throw new AiFailure('request_too_large');
            }
        }
        if (! $hasUser) {
            throw new AiFailure('invalid_request');
        }
        InputBudget::assertFits($messages, $c->maxInputTokens);
        $payload = [
            'model' => $c->model,
            'messages' => $messages,
            $c->maxOutputTokensField => $c->maxOutputTokens,
            'stream' => false,
        ];
        if ($c->reasoningEffort !== '') {
            $payload['reasoning_effort'] = $c->reasoningEffort;
        }
        if ($c->thinkingMode !== '') {
            $payload['thinking'] = ['type' => $c->thinkingMode];
        }
        if ($c->structuredOutputMode === 'json_object') {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new AiFailure('invalid_request');
        }
        if (strlen($body) > $c->maxRequestBytes) {
            throw new AiFailure('request_too_large');
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'Expect:'];
        if ($c->authMode === 'bearer') {
            $headers[] = 'Authorization: Bearer ' . $c->apiKey;
        } elseif ($c->authMode === 'api_key_header') {
            $headers[] = $c->apiKeyHeader . ': ' . $c->apiKey;
        }
        $endpoint = $this->policy->validate();
        $response = '';
        $overflow = false;
        $receive = static function (#[\SensitiveParameter] string $chunk) use (&$response, &$overflow, $c): void {
            if ($overflow || strlen($chunk) > $c->maxResponseBytes - strlen($response)) {
                $overflow = true;
                throw new AiFailure('response_too_large');
            }
            $response .= $chunk;
        };
        if ($beforeDispatch !== null) {
            try {
                $beforeDispatch();
            } catch (Throwable $error) {
                throw new AiFailure($error instanceof AiFailure ? $error->reason : 'dispatch_paused',
                    $error instanceof AiFailure ? $error->getCode() : 503);
            }
        }
        try {
            $status = ($this->transport)($this->profile, $endpoint, $headers, $body, $receive);
        } catch (Throwable $error) {
            throw new AiFailure($overflow ? 'response_too_large' : ($error instanceof AiFailure ? $error->reason : 'transport_failed'));
        }
        if ($overflow) {
            throw new AiFailure('response_too_large');
        }
        if (! is_int($status) || $status < 100 || $status > 599) {
            throw new AiFailure('transport_failed');
        }
        if ($status === 401 || $status === 403) {
            throw new AiFailure('provider_auth_failed');
        }
        if ($status === 429) {
            throw new AiFailure('provider_rate_limited');
        }
        if ($status >= 500) {
            throw new AiFailure('provider_unavailable');
        }
        if ($status !== 200) {
            throw new AiFailure('provider_rejected');
        }
        try {
            $json = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiFailure('invalid_response');
        }
        if (! is_array($json) || isset($json['error']) || ! isset($json['choices'])
            || ! is_array($json['choices']) || ! array_is_list($json['choices']) || count($json['choices']) !== 1
            || ! is_array($choice = $json['choices'][0]) || ! is_array($message = $choice['message'] ?? null)
            || array_is_list($message)) {
            throw new AiFailure('invalid_response');
        }
        if (($choice['finish_reason'] ?? null) !== 'stop' || ($choice['index'] ?? 0) !== 0
            || ($message['role'] ?? null) !== 'assistant'
            || (array_key_exists('tool_calls', $message) && $message['tool_calls'] !== null && $message['tool_calls'] !== [])
            || (array_key_exists('function_call', $message) && $message['function_call'] !== null)
            || (array_key_exists('refusal', $message) && $message['refusal'] !== null)
            || (array_key_exists('audio', $message) && $message['audio'] !== null)) {
            throw new AiFailure('completion_rejected');
        }
        $content = $message['content'] ?? null;
        if (! is_string($content) || trim($content) === '' || str_contains($content, "\0")) {
            throw new AiFailure('invalid_response');
        }
        if ($c->structuredOutputMode === 'json_object') {
            try {
                $document = json_decode($content, false, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new AiFailure('structured_output_invalid');
            }
            if (! $document instanceof \stdClass) {
                throw new AiFailure('structured_output_invalid');
            }
        }

        return $content;
    }
}
