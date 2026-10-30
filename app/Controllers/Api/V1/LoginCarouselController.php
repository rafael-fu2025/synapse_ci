<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Carousel\CarouselService;
use App\Services\CurrentTenant;
use App\Services\Realtime\CarouselTicketIssuer;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Realtime;
use RuntimeException;

final class LoginCarouselController extends ApiV1Controller
{
    public function index(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $slides = [];
            foreach ((new CarouselService())->getSlidesForLogin() as $slide) {
                $lightImage = $this->imagePath($slide['light_image'] ?? null);
                if ($lightImage === null) {
                    continue;
                }

                $slides[] = [
                    'id' => (int) $slide['id'],
                    'title' => isset($slide['title']) ? (string) $slide['title'] : null,
                    'subtitle' => isset($slide['subtitle']) ? (string) $slide['subtitle'] : null,
                    'light_image' => $lightImage,
                    'dark_image' => $this->imagePath($slide['dark_image'] ?? null),
                    'fit_mode' => ($slide['fit_mode'] ?? null) === 'contain_blur' ? 'contain_blur' : 'cover',
                ];
            }

            return $this->ok(['data' => ['slides' => $slides]]);
        });
    }

    public function realtime(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            if (! service('throttler')->check(
                'carousel-realtime-ip-' . hash('sha256', $this->request->getIPAddress()), 120, 60
            )) {
                throw new RuntimeException('Too many realtime requests. Please try again shortly.', 429);
            }

            // Selectors in request parameters never influence this public-only grant.
            $payload = (new CarouselTicketIssuer(config(Realtime::class)))->issue(
                CurrentTenant::id(), $this->request->getHeaderLine('Origin')
            );

            return $this->ok(['data' => $payload]);
        });
    }

    private function imagePath(mixed $path): ?string
    {
        // Project only local raster paths; never normalize untrusted paths into allowed ones.
        if (!is_string($path) || strlen($path) > 255
            || !preg_match('#\A(?:assets/img/brand/(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*|uploads/carousel/)[A-Za-z0-9_-][A-Za-z0-9._-]*\.(?i:png|jpe?g|webp|gif)\z#D', $path)
        ) {
            return null;
        }

        return $path;
    }
}
