<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use RuntimeException;

/** The shared question contract for survey snapshots and bank revisions. */
final class SurveyQuestion
{
    public static function normalize(mixed $question, bool $allowSource = true): array
    {
        if (!is_array($question) || array_diff(array_keys($question), $allowSource
            ? ['type', 'label', 'required', 'options', 'bank_id', 'bank_revision'] : ['type', 'label', 'required', 'options']) !== []) {
            throw new RuntimeException('Invalid or unexpected question fields.', 422);
        }
        $type = $question['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['text', 'single', 'multiple', 'rating'], true)) throw new RuntimeException('Unknown question type.', 422);
        $required = $question['required'] ?? false;
        if (!in_array($required, [true, false, '1', '0', 1, 0], true)) throw new RuntimeException('Invalid required flag.', 422);
        $options = $question['options'] ?? [];
        if (!is_array($options) || !array_is_list($options)) throw new RuntimeException('Invalid options.', 422);
        if (in_array($type, ['single', 'multiple'], true)) {
            if (count($options) < 2 || count($options) > 10) throw new RuntimeException('Choices need 2 to 10 options.', 422);
            $options = array_map(static fn ($value) => self::text($value, 200), $options);
            if (count(array_unique($options)) !== count($options)) throw new RuntimeException('Choice labels must be distinct.', 422);
        } elseif ($options !== []) throw new RuntimeException('Only choice questions accept options.', 422);
        $out = ['type' => $type, 'label' => self::text($question['label'] ?? null, 500),
            'required' => in_array($required, [true, '1', 1], true), 'options' => $options];
        if (array_key_exists('bank_id', $question) || array_key_exists('bank_revision', $question)) {
            $out['bank_id'] = self::positive($question['bank_id'] ?? null);
            $out['bank_revision'] = self::positive($question['bank_revision'] ?? null);
        }
        return $out;
    }

    public static function text(mixed $value, int $max, bool $empty = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) throw new RuntimeException('Expected valid text.', 422);
        $value = trim($value);
        if ((!$empty && $value === '') || mb_strlen($value) > $max) throw new RuntimeException('Text is empty or too long.', 422);
        return $value;
    }

    public static function positive(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[1-9][0-9]*\z/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT) === false) throw new RuntimeException('Invalid question identifier, revision or version.', 422);
        return (int) $value;
    }
}
