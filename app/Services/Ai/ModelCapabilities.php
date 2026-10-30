<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Only explicit provider facts and local recommendations; never provider prose or routing data. */
final class ModelCapabilities
{
    public const MAX_MODELS = 200;

    public static function safeId(mixed $value): bool
    {
        // Match Profile's opaque model contract. IDs are payload values, never URL paths or query strings.
        return is_string($value) && $value !== '' && strlen($value) <= 191
            && mb_check_encoding($value, 'UTF-8') && !preg_match('/[\p{Z}\p{C}\s]/u', $value);
    }

    public static function safeName(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 191
            && mb_check_encoding($value, 'UTF-8') && !preg_match('/[\p{C}<>]/u', $value)
            && !preg_match('~[a-z][a-z0-9+.-]*://~i', $value);
    }

    /** @return list<array> */
    public static function normalize(#[\SensitiveParameter] array $models, #[\SensitiveParameter] Profile $profile): array
    {
        $profile->assertUnchanged();
        if (count($models) > self::MAX_MODELS) { throw new \RuntimeException('catalog_too_large', 422); }
        if (!array_is_list($models)) { throw new AiFailure('invalid_response'); }
        $result = [];
        $seen = [];
        foreach ($models as $model) {
            if (!is_array($model) || !self::safeId($id = $model['id'] ?? null)) {
                throw new AiFailure('invalid_response');
            }
            // A provider reflecting credentials must not put them in either public field or storage.
            $secret = $profile->config->apiKey;
            if ($secret !== '' && (str_contains($id, $secret)
                || (is_string($model['name'] ?? null) && str_contains($model['name'], $secret)))) {
                throw new AiFailure('invalid_response');
            }
            $key = 'id:' . $id;
            if (isset($seen[$key])) { throw new \RuntimeException('catalog_duplicate_id', 422); }
            $seen[$key] = true;
            $row = self::row($model, $profile);
            if ($row !== null) { $result[] = $row; }
        }
        usort($result, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        return $result;
    }

    private static function row(#[\SensitiveParameter] array $model, #[\SensitiveParameter] Profile $profile): ?array
    {
        $c = $profile->config;
        $id = $model['id'];
        // A display label is optional. Unsafe labels fall back to the safe identifier, not stripped prose.
        $name = $model['name'] ?? $id;
        if (!self::safeName($name)) { $name = $id; }
        $sources = [];
        $context = self::capacity($model, ['context_window', 'context_length', 'max_model_len', 'top_provider.context_length'], $sources, 'context_window');
        // Too small for the minimum 512 input + 1 output + 128 overhead; do not fabricate a budget.
        if ($context !== null && $context < 641) { return null; }
        $output = self::capacity($model, ['max_output_tokens', 'max_completion_tokens', 'top_provider.max_completion_tokens'], $sources, 'max_output_tokens');
        $caps = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];
        $parameters = is_array($model['supported_parameters'] ?? null) && array_is_list($model['supported_parameters'])
            ? $model['supported_parameters'] : [];
        $supports = null;
        foreach (['supports_thinking' => $model['supports_thinking'] ?? null, 'capabilities.thinking' => $caps['thinking'] ?? null,
            'capabilities.supports_thinking' => $caps['supports_thinking'] ?? null] as $field => $value) {
            if (is_bool($value)) { $supports = $value; $sources['supports_thinking'] = 'provider: ' . $field; break; }
        }
        if ($supports === null && in_array('thinking', $parameters, true)) {
            $supports = true; $sources['supports_thinking'] = 'provider: supported_parameters';
        }
        $sources += ['context_window' => 'unknown', 'max_output_tokens' => 'unknown', 'supports_thinking' => 'unknown'];
        $warnings = ['Input allowance is a conservative UTF-8 byte budget, not an exact provider tokenizer or context guarantee.'];
        $maxOutput = min($c->maxOutputTokens, $output ?? 32768);
        if ($context !== null) { $maxOutput = min($maxOutput, max(1, $context - 640)); }
        $maxInput = min($c->maxInputTokens, $context === null ? 131072 : max(512, $context - $maxOutput - 128));

        if ($context === null) { $warnings[] = 'Context capacity is unknown; local limits require provider verification.'; }
        $same = $id === $c->model;
        $thinking = $same && $supports === true ? $c->thinkingMode : '';
        $reasoningSupported = in_array('reasoning_effort', $parameters, true) || ($caps['reasoning_effort'] ?? null) === true;
        $reasoning = $same && $reasoningSupported ? $c->reasoningEffort : '';
        $structuredSupported = in_array('response_format', $parameters, true) || ($caps['json_object'] ?? null) === true;
        $structured = $same && $structuredSupported ? $c->structuredOutputMode : 'none';
        $tokenField = $same ? $c->maxOutputTokensField : 'max_tokens';
        if (in_array('max_completion_tokens', $parameters, true) && !in_array('max_tokens', $parameters, true)) {
            $tokenField = 'max_completion_tokens';
        }
        $sources['defaults.max_input_tokens'] = $context === null ? 'local safe budget' : 'local safe budget; provider context minus output and 128 overhead';
        $sources['defaults.max_output_tokens'] = $output === null ? 'current config; local safe budget' : 'current config capped by provider';
        $sources['defaults.thinking_mode'] = $thinking === '' ? 'provider default' : 'current config; explicit provider support';
        $sources['defaults.reasoning_effort'] = $reasoning === '' ? 'provider default' : 'current config; explicit provider support';
        $sources['defaults.token_field'] = $tokenField === 'max_completion_tokens' && in_array($tokenField, $parameters, true)
            ? 'provider: supported_parameters' : ($same ? 'current config' : 'local safe budget');
        $sources['defaults.structured_output'] = $structured === 'none' ? 'provider default' : 'current config; explicit provider support';
        // Reviewed family only: exact or one namespace, never substring matches or inferred context sizes.
        if (preg_match('~\A(?:[a-z0-9][a-z0-9._-]*/)?minimax-m3\z~iD', $id)) {
            $thinking = 'disabled';
            $sources['defaults.thinking_mode'] = 'reviewed preset';
            $warnings[] = 'Reviewed MiniMax-M3 recommendation: disable thinking; verify endpoint compatibility before use.';
        }
        if (($same && $c->reasoningEffort !== '' && !$reasoningSupported) || ($same && $c->thinkingMode !== '' && $supports !== true)) {
            $warnings[] = 'Unverified reasoning options use provider defaults.';
        }
        return ['id' => $id, 'name' => $name, 'context_window' => $context, 'max_output_tokens' => $output,
            'supports_thinking' => $supports,
            'defaults' => ['max_input_tokens' => $maxInput, 'max_output_tokens' => $maxOutput,
                'thinking_mode' => $thinking, 'reasoning_effort' => $reasoning, 'token_field' => $tokenField, 'structured_output' => $structured],
            'sources' => $sources, 'warnings' => $warnings];
    }

    private static function capacity(#[\SensitiveParameter] array $model, array $fields, array &$sources, string $target): ?int
    {
        foreach ($fields as $field) {
            $value = $model;
            foreach (explode('.', $field) as $part) { $value = is_array($value) ? ($value[$part] ?? null) : null; }
            if (is_int($value) && $value > 0 && $value <= 1000000000) {
                $sources[$target] = 'provider: ' . $field;
                return $value;
            }
        }
        return null;
    }
}
