<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

final class ExportPdf
{
    public static function response(ResponseInterface $response, string $pdf, string $filename): ResponseInterface
    {
        if (!preg_match('/\A[a-z0-9_-]+\.pdf\z/D', $filename) || !str_starts_with($pdf, '%PDF-1.4')) {
            throw new RuntimeException('Invalid PDF export.', 503);
        }
        return $response->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('Pragma', 'no-cache')->setHeader('Expires', '0')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                        ->setHeader('Content-Length', (string) strlen($pdf))
            ->setBody($pdf);
    }
}
