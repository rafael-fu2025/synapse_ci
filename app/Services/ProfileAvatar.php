<?php

declare(strict_types=1);

namespace App\Services;

/** Locally generated fallback only; never renders an uploaded SVG or requests an avatar service. */
final class ProfileAvatar
{
    public static function initials(array $identity): string
    {
        $first = trim((string) ($identity['first_name'] ?? ''));
        $last = trim((string) ($identity['last_name'] ?? ''));
        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/u', trim((string) ($identity['name'] ?? $identity['username'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $first = $parts[0] ?? '';
            $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';
        }
        $letter = static function (string $name): string {
            if (!preg_match('/[\p{L}\p{N}]\p{M}*/u', $name, $match)) return '';
            // Uppercasing can expand a letter (e.g. sharp S); keep one initial per name.
            preg_match('/[\p{L}\p{N}]\p{M}*/u', mb_strtoupper($match[0], 'UTF-8'), $upper);
            return $upper[0] ?? '';
        };
        return ($letter($first) . $letter($last)) ?: 'U';
    }

    public static function svg(array $identity): string
    {
        $initials = $identity['avatar_initials'] ?? self::initials($identity);
        if (!is_string($initials) || !preg_match('/\A(?:[\p{L}\p{N}]\p{M}*){1,2}\z/u', $initials)) {
            $initials = self::initials($identity);
        }
        $text = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        return '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128" role="img" aria-label="Profile initials">'
            . '<rect width="128" height="128" rx="64" fill="#800000"/>'
            . '<text x="64" y="66" text-anchor="middle" dominant-baseline="middle" font-family="Arial, sans-serif" font-size="48" font-weight="700" fill="#ffffff">'
            . $text . '</text></svg>';
    }

    public static function dataUri(array $identity): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($identity));
    }
}
