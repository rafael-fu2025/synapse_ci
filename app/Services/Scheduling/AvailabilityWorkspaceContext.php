<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

/** Presentation state only; never an input to scheduling authorization or writes. */
final class AvailabilityWorkspaceContext
{
    public const DEFAULTS = ['q' => '', 'provider' => '', 'day' => 1, 'section' => 'weekly'];

    public static function fromGet(array $input, array $providers): array
    {
        $context = self::parse($input);
        if ($context['provider'] !== '' && !in_array($context['provider'], array_map(
            static fn (array $provider): string => (string) $provider['id'], $providers
        ), true)) {
            $context['provider'] = '';
        }
        return $context;
    }

    public static function fromPost(array $input): array
    {
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists('return_' . $key, $input)) {
                $values[$key] = $input['return_' . $key];
            }
        }
        try {
            return self::parse($values);
        } catch (\InvalidArgumentException) {
            // Invalid navigation state must not turn an authorized write into a failure.
            return self::DEFAULTS;
        }
    }

    public static function withSubmitted(array $context, mixed $provider, mixed $day = null): array
    {
        // Services validate the submitted fields first; accept their integer syntax here too.
        $providerId = is_string($provider) || is_int($provider)
            ? filter_var($provider, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($providerId !== false) {
            $context['provider'] = (string) $providerId;
        }
        $weekday = is_string($day) || is_int($day)
            ? filter_var($day, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]) : false;
        if ($weekday !== false) {
            $context['day'] = $weekday;
        }
        return $context;
    }

    public static function query(array $context): string
    {
        return http_build_query(self::parse($context), '', '&', PHP_QUERY_RFC3986);
    }

    public static function path(string $unit, array $context): string
    {
        return '/' . rawurlencode($unit) . '/appointments/availability?' . self::query($context);
    }

    private static function parse(array $input): array
    {
        $values = array_intersect_key($input, self::DEFAULTS) + self::DEFAULTS;
        $q = $values['q'];
        if (!is_string($q) || !mb_check_encoding($q, 'UTF-8') || mb_strlen($q, 'UTF-8') > 120
            || preg_match('/[<>\p{Cc}]/u', $q)) {
            throw new \InvalidArgumentException('Invalid availability search text. Use up to 120 plain-text characters.');
        }
        if (!is_string($values['section']) || !in_array($values['section'], ['enrollment', 'weekly', 'exceptions'], true)) {
            throw new \InvalidArgumentException('Invalid availability section.');
        }
        return [
            'q' => trim($q),
            'provider' => $values['provider'] === '' ? '' : (string) self::integer($values['provider'], 1, PHP_INT_MAX),
            'day' => self::integer($values['day'], 0, 6),
            'section' => $values['section'],
        ];
    }

    private static function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[0-9]+\z/', (string) $value)) {
            throw new \InvalidArgumentException('Invalid availability workspace selection.');
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($number === false) {
            throw new \InvalidArgumentException('Invalid availability workspace selection.');
        }
        return $number;
    }
}
