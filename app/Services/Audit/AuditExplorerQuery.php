<?php

declare(strict_types=1);

namespace App\Services\Audit;

use DateTimeImmutable;

/** Validated URL state for the reader; no cursor is ever converted to a PHP integer. */
final class AuditExplorerQuery
{
    public array $filters = [];
    public array $errors = [];
    public ?string $cursor = null;
    public string $direction = 'older';
    public int $limit = 50;

    public static function fromQuery(array $query): self
    {
        $state = new self();
        foreach (['event_type' => 64, 'action' => 32, 'entity_type' => 64, 'entity_id' => 64,
            'actor_user_id' => 20, 'from' => 10, 'to' => 10, 'per_page' => 3,
            'cursor' => 20, 'direction' => 5] as $key => $max) {
            $raw = $query[$key] ?? '';
            if (!is_string($raw) && !is_int($raw)) {
                $state->errors[] = "Invalid {$key}: a single value is required.";
                continue;
            }
            $value = trim((string) $raw);
            if (strlen($value) > $max) {
                $state->errors[] = "Invalid {$key}: value is too long.";
                continue;
            }
            if ($key === 'cursor') {
                if ($value !== '' && !self::validId($value)) {
                    $state->errors[] = 'Invalid pagination cursor.';
                } else {
                    $state->cursor = $value === '' ? null : $value;
                }
            } elseif ($key === 'direction') {
                if ($value !== '' && !in_array($value, ['older', 'newer'], true)) {
                    $state->errors[] = 'Invalid pagination direction.';
                } else {
                    $state->direction = $value ?: 'older';
                }
            } elseif ($key === 'per_page') {
                if ($value !== '' && !in_array($value, ['25', '50', '100'], true)) {
                    $state->errors[] = 'Page size must be 25, 50, or 100.';
                } else {
                    $state->limit = $value === '' ? 50 : (int) $value;
                }
            } else {
                $state->filters[$key] = $value;
                if ($key === 'actor_user_id' && $value !== '' && !self::validId($value)) {
                    $state->errors[] = 'Actor ID must be a positive BIGINT UNSIGNED decimal.';
                }
                if (in_array($key, ['from', 'to'], true) && $value !== '' && !self::validDate($value)) {
                    $state->errors[] = "Invalid {$key} date: use YYYY-MM-DD.";
                }
            }
        }
        $state->filters['per_page'] = (string) $state->limit;
        if (($state->filters['from'] ?? '') !== '' && ($state->filters['to'] ?? '') !== ''
            && $state->filters['from'] > $state->filters['to']) {
            $state->errors[] = 'Start date must not be after end date.';
        }
        if ($state->direction === 'newer' && $state->cursor === null) {
            $state->errors[] = 'Newer navigation requires a cursor.';
        }
        return $state;
    }

    public function parameters(?string $cursor = null, string $direction = 'older'): array
    {
        $parameters = array_filter($this->filters, static fn (string $value): bool => $value !== '');
        if ($cursor !== null) {
            $parameters['cursor'] = $cursor;
            $parameters['direction'] = $direction;
        }
        return $parameters;
    }

    private static function validId(string $id): bool
    {
        return preg_match('/\A[1-9][0-9]{0,19}\z/', $id) === 1
            && (strlen($id) < 20 || strcmp($id, '18446744073709551615') <= 0);
    }

    private static function validDate(string $date): bool
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) !== 1 || substr($date, 0, 4) === '0000') {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
