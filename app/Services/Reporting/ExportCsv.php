<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

final class ExportCsv
{
    public const MAX_BYTES = 8388608;

    public static function cell(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) { throw new RuntimeException('Invalid CSV cell.', 503); }
        $text = (string) $value;
        if (!mb_check_encoding($text, 'UTF-8')) { throw new RuntimeException('Invalid CSV text encoding.', 503); }
        // Include whitespace/control-prefixed formulas, and tab/newline injection.
        if (preg_match('/\A[\s\x00-\x20\x{FEFF}]*[=+\-@]/u', $text)
            || preg_match('/\A[\t\r\n]/', $text)) {
            return "'" . $text;
        }
        return $text;
    }

    /** Entirely memory-backed: no plaintext export artifacts on server disk. */
    public static function encode(iterable $rows): string
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) { throw new RuntimeException('CSV export unavailable.', 503); }
        try {
            foreach ($rows as $row) {
                if (fputcsv($stream, array_map(self::cell(...), $row), ',', '"', '', "\r\n") === false
                    || ftell($stream) > self::MAX_BYTES) {
                    throw new RuntimeException('Export exceeds the size limit. Select a smaller date range.', 422);
                }
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) { throw new RuntimeException('CSV export unavailable.', 503); }
            return $csv;
        } finally { fclose($stream); }
    }

    public static function response(ResponseInterface $response, string $csv, string $filename): ResponseInterface
    {
        if (!preg_match('/\A[a-z0-9_-]+\.csv\z/D', $filename)) {
            throw new RuntimeException('Invalid export filename.', 503);
        }
        return $response->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('Pragma', 'no-cache')->setHeader('Expires', '0')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                        ->setHeader('Content-Length', (string) strlen($csv))
            ->setBody($csv);
    }
}
