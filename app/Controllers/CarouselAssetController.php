<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Carousel\CarouselAssetStore;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Publishes managed login carousel artwork.
 *
 * The artwork itself lives in the private store outside the web root, so this
 * controller is the only way to read it. The logical path is rebuilt from the
 * route arguments and re-validated against the store, so a crafted request can
 * never escape the managed directory.
 */
final class CarouselAssetController extends BaseController
{
    private const CONTENT_TYPES = [
        'image/png'  => 'image/png',
        'image/jpeg' => 'image/jpeg',
        'image/webp' => 'image/webp',
        'image/gif'  => 'image/gif',
    ];

    /**
     * Serve one managed carousel image.
     */
    public function show(string $scope, string $name): ResponseInterface
    {
        $store = new CarouselAssetStore();
        $logicalPath = CarouselAssetStore::NAMESPACE . '/' . $scope . '/' . $name;

        $absolute = $store->absolute($logicalPath);
        if ($absolute === null) {
            return $this->unavailable(404);
        }

        $bytes = @file_get_contents($absolute);
        if ($bytes === false) {
            return $this->unavailable(503);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolute);
        $type = self::CONTENT_TYPES[$mime] ?? null;
        if ($type === null) {
            return $this->unavailable(404);
        }

        return $this->response
            ->setHeader('Content-Type', $type)
            ->setHeader('Content-Length', (string) strlen($bytes))
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($bytes);
    }

    private function unavailable(int $status): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody('Image unavailable.');
    }
}
