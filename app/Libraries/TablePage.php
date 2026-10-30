<?php

declare(strict_types=1);

namespace App\Libraries;

use InvalidArgumentException;

/** GET names are q/page/per_page; PHP consumers use q/page/perPage. */
final class TablePage
{
    private function __construct(
        public readonly string $q,
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $kind,
        public readonly string $status,
        public readonly bool $fragment,
    ) {
    }

    public static function fromQuery(array $query): self
    {
        $q = trim(self::string($query, 'q', ''));
        if (!mb_check_encoding($q, 'UTF-8')) {
            throw new InvalidArgumentException('Search must be valid UTF-8.', 422);
        }
        if (mb_strlen($q, 'UTF-8') > 150) {
            throw new InvalidArgumentException('Search must be at most 150 characters.', 422);
        }
        $page = self::integer($query, 'page', 1);
        $perPage = self::integer($query, 'per_page', 25);
        if ($page < 1 || $page > 1000000) {
            throw new InvalidArgumentException('Page must be between 1 and 1000000.', 422);
        }
        if (!in_array($perPage, [25, 50, 100], true)) {
            throw new InvalidArgumentException('Per page must be 25, 50 or 100.', 422);
        }
        $kind = self::string($query, 'kind', 'all');
        $status = self::string($query, 'status', 'all');
        if (!in_array($kind, ['all', 'student', 'employee', 'guest'], true)) {
            throw new InvalidArgumentException('Invalid patient kind.', 422);
        }
        if (!in_array($status, ['all', 'active', 'archived', 'low_stock'], true)) {
            throw new InvalidArgumentException('Invalid medicine status.', 422);
        }
        $fragment = self::string($query, 'fragment', '0');
        if (!in_array($fragment, ['0', '1'], true)) {
            throw new InvalidArgumentException('Invalid fragment flag.', 422);
        }
        return new self($q, $page, $perPage, $kind, $status, $fragment === '1');
    }

    /** Pagination-only lists may have their own filters and independent page names. */
    public static function forList(array $query, string $pageParam = 'page'): self
    {
        $page = self::integer($query, $pageParam, 1);
        $perPage = self::integer($query, 'per_page', 25);
        if ($page < 1 || $page > 1000000) {
            throw new InvalidArgumentException('Page must be between 1 and 1000000.', 422);
        }
        if (!in_array($perPage, [20, 25, 50, 100], true)) {
            throw new InvalidArgumentException('Per page must be 20, 25, 50 or 100.', 422);
        }
        return new self('', $page, $perPage, 'all', 'all', false);
    }

    /** Counts are measured before clamping; even an empty result has page 1. */
    public function metadata(int $total, int $filtered): array
    {
        $pages = max(1, (int) ceil($filtered / $this->perPage));
        $page = min($this->page, $pages);
        return [
            'page' => $page, 'per_page' => $this->perPage,
            'total' => $total, 'filtered' => $filtered, 'pages' => $pages,
            'from' => $filtered === 0 ? 0 : ($page - 1) * $this->perPage + 1,
            'to' => min($page * $this->perPage, $filtered),
        ];
    }

    private static function string(array $query, string $key, string $default): string
    {
        if (!array_key_exists($key, $query)) {
            return $default;
        }
        if (!is_string($query[$key])) {
            throw new InvalidArgumentException('Invalid ' . $key . '.', 422);
        }
        return $query[$key];
    }

    private static function integer(array $query, string $key, int $default): int
    {
        if (!array_key_exists($key, $query)) {
            return $default;
        }
        $value = $query[$key];
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/\A[1-9][0-9]{0,6}\z/D', (string) $value)) {
            throw new InvalidArgumentException('Invalid ' . $key . '.', 422);
        }
        return (int) $value;
    }
}
