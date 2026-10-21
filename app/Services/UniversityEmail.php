<?php

declare(strict_types=1);

namespace App\Services;

/**
 * UniversityEmail — derives the Foundation University email address
 * from a person's name, for MIS-provisioned users whose university
 * account the MIS API does not return:
 *
 *   all given names concatenated + '.' + surname concatenated + '@foundationu.com'
 *
 *   "John Lloyd" + "Macias"   -> johnlloyd.macias@foundationu.com
 *   "JUAN" + "DELA CRUZ"      -> juan.delacruz@foundationu.com
 *
 * Rules fixed by the university's account convention:
 *   - middle names are NEVER part of the address — callers pass
 *     first/last only (the MIS `middle_name` is the PH middle/maiden
 *     name);
 *   - every non-alphabetic character is dropped and the remaining
 *     letters concatenated (spaces, hyphens, apostrophes, dots);
 *   - common Spanish/PH diacritics are transliterated first (ñ -> n; the
 *     university writes this rule as Ñ -> N against its ALL-CAPS MIS
 *     payloads — the address itself is lowercase, so every diacritic
 *     maps to its lowercase ASCII letter);
 *   - generational suffixes (JR, SR, II..V) are stripped from the
 *     surname;
 *   - either side empty -> null (no honest address to show).
 *
 * DISPLAY-ONLY: the derived address is never persisted as an
 * `email_password` identity. MIS users authenticate by university ID
 * and manage their password through the FU helpdesk — deriving an
 * address must not open the email+password login path.
 */
final class UniversityEmail
{
    private const DOMAIN = '@foundationu.com';

    private const SUFFIXES = ['jr', 'sr', 'ii', 'iii', 'iv', 'v'];

    /**
     * Deterministic Spanish/PH transliterations (no iconv locale games).
     *
     * Keys are lowercase only and values must stay inside [a-z]: callers
     * lowercase with an explicit UTF-8 encoding first, so an ALL-CAPS
     * 'Ñ' is already 'ñ' by the time this table runs, and the caller's
     * `[^a-z]` filter would delete anything this table returned outside
     * the lowercase range.
     */
    private const TRANSLITERATIONS = [
        'ñ' => 'n',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];

    public static function derive(?string $firstName, ?string $lastName): ?string
    {
        $first = self::lettersOnly($firstName ?? '');
        $last  = self::lettersOnly(self::stripSuffix($lastName ?? ''));

        if ($first === '' || $last === '') {
            return null;
        }

        return $first . '.' . $last . self::DOMAIN;
    }

    /** Lowercase, transliterate, then concatenate the a-z letters. */
    private static function lettersOnly(string $value): string
    {
        $mapped = strtr(mb_strtolower(trim($value), 'UTF-8'), self::TRANSLITERATIONS);

        return preg_replace('/[^a-z]/', '', $mapped) ?? '';
    }

    /** Drop trailing generational suffix tokens ("Macias Jr" -> "Macias"). */
    private static function stripSuffix(string $lastName): string
    {
        $parts = preg_split('/\s+/', trim($lastName)) ?: [];
        while ($parts !== []) {
            $token = strtolower(rtrim((string) end($parts), '.'));
            if (! in_array($token, self::SUFFIXES, true)) {
                break;
            }
            array_pop($parts);
        }

        return implode(' ', $parts);
    }
}
