<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use RuntimeException;

/** Shared bounds for portal feeds, applied only after tenant/audience scoping. */
final class PortalContent
{
    public static function filters(array $query, array $states, ?array $units = null): array
    {
        $q = $query['q'] ?? '';
        $status = $query['status'] ?? 'all';
        $unit = $query['unit'] ?? 'all';
        if (!is_string($q) || !mb_check_encoding($q, 'UTF-8') || mb_strlen($q) > 120
            || preg_match('/[\p{Cc}\p{Cf}]/u', $q) || !is_string($status) || !in_array($status, $states, true)
            || !is_string($unit) || ($units !== null && !in_array($unit, ['all', ...$units], true))) {
            throw new RuntimeException('Choose valid content filters and a search of at most 120 characters.', 422);
        }
        $page = self::integer($query['page'] ?? 1, 1, 1000000);
        $perPage = self::integer($query['per_page'] ?? 25, 1, 100);
        if (!in_array($perPage, [25, 50, 100], true)) throw new RuntimeException('Invalid page size.', 422);
        return ['q' => trim($q), 'status' => $status, 'unit' => $units === null ? 'all' : $unit, 'page' => $page, 'per_page' => $perPage];
    }

    public static function page(BaseBuilder $builder, array $filters, string $order): array
    {
        $total = (clone $builder)->countAllResults();
        $pages = max(1, (int) ceil($total / $filters['per_page']));
        $page = min($filters['page'], $pages);
        return ['items' => $builder->orderBy($order, 'DESC')->limit($filters['per_page'], ($page - 1) * $filters['per_page'])->get()->getResultArray(),
            'pagination' => ['page' => $page, 'per_page' => $filters['per_page'], 'total' => $total, 'filtered' => $total, 'pages' => $pages],
            'filters' => $filters];
    }

    private static function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_string($value) && !is_int($value)) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
            throw new RuntimeException('Invalid content page.', 422);
        }
        return (int) $value;
    }
}
