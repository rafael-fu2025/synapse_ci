<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseBuilder;
use Config\Database;

/**
 * Literal LIKE matching for search terms.
 *
 * A search term that reaches SQL as a LIKE pattern turns % and _ into wildcards, so a
 * user typing "%" matches every row. Escaping the metacharacters is only half the fix:
 * the statement also needs the matching ESCAPE clause, otherwise the database applies
 * its own default escape character and the term is still partly a wildcard. Keeping
 * both halves in one place stops them from drifting apart.
 */
final class LikePattern
{
    /**
     * Escape the LIKE metacharacters so the term matches literally.
     */
    public static function literal(string $value): string
    {
        $escape = Database::connect()->likeEscapeChar;

        return strtr($value, [
            $escape => $escape . $escape,
            '%' => $escape . '%',
            '_' => $escape . '_',
        ]);
    }

    /**
     * Apply a literal LIKE comparison to one column.
     *
     * The caller owns any surrounding group.
     */
    public static function apply(BaseBuilder $builder, string $field, string $value, string $side = 'both'): void
    {
        $builder->like($field, self::literal($value), $side, true);
    }

    /**
     * Apply a literal LIKE comparison across several columns.
     *
     * The columns are grouped, so the match never leaks out of the term's scope.
     */
    public static function applyGroup(BaseBuilder $builder, array $fields, string $value, string $side = 'both'): void
    {
        if ($fields === []) {
            return;
        }
        $literal = self::literal($value);
        $builder->groupStart();
        foreach (array_values($fields) as $index => $field) {
            $index === 0
                ? $builder->like($field, $literal, $side, true)
                : $builder->orLike($field, $literal, $side, true);
        }
        $builder->groupEnd();
    }
}
