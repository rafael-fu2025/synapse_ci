<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared HTML error page renderer for browser requests hitting HTTP status
 * filters, guards, or non-AJAX controller errors.
 */
final class ErrorPageRenderer
{
    /**
     * Returns true when a browser document navigation (Accept: text/html)
     * should receive a styled HTML error page instead of JSON/plain text.
     */
    public static function wantsHtml(IncomingRequest $request): bool
    {
        if ($request->isAJAX() || strtolower($request->getHeaderLine('HX-Request')) === 'true') {
            return false;
        }

        return str_contains(strtolower($request->getHeaderLine('Accept')), 'text/html');
    }

    /**
     * Renders the standalone SYNAPSE error card view for a given HTTP status.
     *
     * @param array<string, mixed> $options
     */
    public static function render(int $status, string $message, array $options = []): string
    {
        if (function_exists('helper')) {
            helper('url');
        }

        $defaults = match ($status) {
            400 => [
                'title'   => 'Bad request',
                'heading' => 'Invalid request',
                'icon'    => 'bi-exclamation-circle-fill',
                'tone'    => 'warning',
            ],
            401 => [
                'title'   => 'Sign in required',
                'heading' => 'Sign in required',
                'icon'    => 'bi-person-lock',
                'tone'    => 'maroon',
            ],
            403 => [
                'title'   => 'Access restricted',
                'heading' => 'Access restricted',
                'icon'    => 'bi-shield-lock-fill',
                'tone'    => 'maroon',
            ],
            404 => [
                'title'   => 'Page not found',
                'heading' => 'Page not found',
                'icon'    => 'bi-compass',
                'tone'    => 'maroon',
            ],
            405 => [
                'title'   => 'Method not allowed',
                'heading' => 'Action cannot be opened directly',
                'icon'    => 'bi-slash-circle-fill',
                'tone'    => 'warning',
            ],
            409 => [
                'title'   => 'Conflict detected',
                'heading' => 'Action could not be completed',
                'icon'    => 'bi-exclamation-diamond-fill',
                'tone'    => 'warning',
            ],
            422 => [
                'title'   => 'Unable to process',
                'heading' => 'Please review your request',
                'icon'    => 'bi-exclamation-triangle-fill',
                'tone'    => 'warning',
            ],
            429 => [
                'title'   => 'Too many sign-in attempts',
                'heading' => 'Too many sign-in attempts',
                'icon'    => 'bi-hourglass-split',
                'tone'    => 'warning',
            ],
            503 => [
                'title'   => 'Service temporarily unavailable',
                'heading' => 'Service temporarily unavailable',
                'icon'    => 'bi-cloud-slash-fill',
                'tone'    => 'warning',
            ],
            default => [
                'title'   => 'Something went wrong',
                'heading' => 'Unable to complete request',
                'icon'    => 'bi-exclamation-octagon-fill',
                'tone'    => 'maroon',
            ],
        };

        $retryAfter = isset($options['retryAfter']) ? max(0, (int) $options['retryAfter']) : 0;
        $retryText = '';
        if ($retryAfter > 0) {
            $retryText = 'Please wait ' . self::formatWait($retryAfter) . ' before trying again.';
        }

        $hasHome = function_exists('site_url');
        $defaultPrimaryHref = $hasHome ? site_url('/') : '/';
        $defaultPrimaryLabel = 'Return to portal';
        $defaultSecondaryHref = $hasHome ? site_url('/auth/login') : '/auth/login';
        $defaultSecondaryLabel = 'Sign in';

        if ($status === 429 || $status === 401) {
            $defaultPrimaryHref = $defaultSecondaryHref;
            $defaultPrimaryLabel = 'Return to sign in';
            $defaultSecondaryHref = '';
            $defaultSecondaryLabel = '';
        }

        $data = [
            'status'         => $status,
            'message'        => $message,
            'title'          => $options['title'] ?? $defaults['title'],
            'heading'        => $options['heading'] ?? $defaults['heading'],
            'badge'          => $options['badge'] ?? ('HTTP ' . $status),
            'icon'           => $options['icon'] ?? $defaults['icon'],
            'tone'           => $options['tone'] ?? $defaults['tone'],
            'retryAfter'     => $retryAfter,
            'retryText'      => $options['retryText'] ?? $retryText,
            'primaryHref'    => $options['primaryHref'] ?? $defaultPrimaryHref,
            'primaryLabel'   => $options['primaryLabel'] ?? $defaultPrimaryLabel,
            'secondaryHref'  => $options['secondaryHref'] ?? $defaultSecondaryHref,
            'secondaryLabel' => $options['secondaryLabel'] ?? $defaultSecondaryLabel,
            'eyebrow'        => $options['eyebrow'] ?? 'Foundation University · SYNAPSE',
        ];

        return view('errors/html/status', $data);
    }

    /**
     * Populates a ResponseInterface with a styled HTML status page and no-store caching.
     *
     * @param array<string, mixed> $options
     */
    public static function htmlResponse(ResponseInterface $response, int $status, string $message, array $options = []): ResponseInterface
    {
        return $response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'private, no-store')
            ->setContentType('text/html; charset=UTF-8')
            ->setBody(self::render($status, $message, $options));
    }

    private static function formatWait(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? '1 second' : $seconds . ' seconds';
        }
        $minutes = (int) ceil($seconds / 60);
        return $minutes === 1 ? '1 minute' : $minutes . ' minutes';
    }
}
