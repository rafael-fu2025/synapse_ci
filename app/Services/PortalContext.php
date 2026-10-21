<?php

declare(strict_types=1);

namespace App\Services;

final class PortalContext
{
    public const PORTAL_MAIN     = 'main';     // synapse.foundationu.com (Student & Employee Self-Service)
    public const PORTAL_CLINIC   = 'clinic';   // clinic.foundationu.com (Clinic Staff Console)
    public const PORTAL_GUIDANCE = 'guidance'; // guidance.foundationu.com (Guidance Counselor Console)
    public const PORTAL_BMG      = 'bmg';      // bmg.foundationu.com (Facilities & Composting Console)

    /** Host selection is presentation/routing context, never an authorization grant. */
    public static function current(): string
    {
        return self::hostPortal() ?? self::routePortal() ?? self::PORTAL_MAIN;
    }

    /** Null means single-host/path mode, including plain localhost. */
    public static function hostPortal(): ?string
    {
        return self::site()['portal'] ?? null;
    }

    private static function site(): ?array
    {
        $authority = service('request')->getServer('HTTP_HOST');
        $host = $authority ? parse_url('http://' . $authority, PHP_URL_HOST) : service('uri')->getHost();
        $host = strtolower((string) $host);
        $config = config('PortalSites');
        foreach ($config->families as $family => $sites) {
            foreach ($sites as $portal => $configuredHost) {
                if ($host === $configuredHost) return ['family' => $family, 'portal' => $portal];
            }
        }
        return $config->aliases[$host] ?? null;
    }

    public static function routePortal(?string $path = null): ?string
    {
        $path = trim($path ?? service('uri')->getRoutePath(), '/');
        return match (true) {
            preg_match('~^clinic(?:/|$)~', $path) === 1 => self::PORTAL_CLINIC,
            preg_match('~^guidance(?:/|$)~', $path) === 1 => self::PORTAL_GUIDANCE,
            preg_match('~^(facilities|bmg)(?:/|$)~', $path) === 1 => self::PORTAL_BMG,
            preg_match('~^(portal|admin|reports)(?:/|$)~', $path) === 1 => self::PORTAL_MAIN,
            default => null,
        };
    }

    /** Keep the configured base path/index/port; replace hosts only from our explicit map. */
    public static function urlFor(string $portal, string $path = ''): string
    {
        if (!in_array($portal, ['main', 'clinic', 'guidance', 'bmg'], true)
            || str_contains($path, '://') || str_starts_with($path, '//') || preg_match('/[\\\\\r\n]/', $path)) {
            throw new \InvalidArgumentException('Invalid portal destination.');
        }
        $url = site_url(ltrim($path, '/'));
        $site = self::site();
        if ($site === null) return $url;
        $host = config('PortalSites')->families[$site['family']][$portal];
        $parts = parse_url($url);
        return $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public static function preferredPortal(string $role): string
    {
        return match ($role) {
            'clinic_admin', 'clinic_staff' => self::PORTAL_CLINIC,
            'guidance_admin', 'guidance_supervisor', 'counsellor' => self::PORTAL_GUIDANCE,
            'bmg_admin', 'facilities_op' => self::PORTAL_BMG,
            default => self::PORTAL_MAIN,
        };
    }

    public static function homePath(string $portal): string
    {
        return match ($portal) { 'clinic' => 'clinic', 'guidance' => 'guidance', 'bmg' => 'facilities/dashboard', default => 'portal' };
    }

    public static function compatibleHost(string $role): bool
    {
        $host = self::hostPortal();
        return $host === null || self::isRolePermitted($role, $host);
    }

    public static function landing(string $role): string
    {
        $host = self::hostPortal();
        if ($host !== null && $host !== self::PORTAL_MAIN && self::isRolePermitted($role, $host)) {
            return self::urlFor($host, self::homePath($host));
        }
        $preferred = self::preferredPortal($role);
        if ($preferred !== self::PORTAL_MAIN) return self::urlFor($preferred, self::homePath($preferred));
        return self::urlFor(self::PORTAL_MAIN, in_array($role, ['superadmin', 'audit_reader', 'report_viewer'], true) ? 'dashboard' : 'portal');
    }

    /** Finish form navigation on this origin; workspace switching is a separate GET. */
    public static function loginLanding(string $role): string
    {
        $destination = self::hostPortal() === self::PORTAL_MAIN && self::preferredPortal($role) !== self::PORTAL_MAIN
            ? self::urlFor(self::PORTAL_MAIN, 'portal') : self::landing($role);
        $uri = service('request')->getUri();
        return $uri->getScheme() . '://' . $uri->getAuthority() . (parse_url($destination, PHP_URL_PATH) ?: '/');
    }

    /** Dedicated hosts scope the sidebar; path mode already shows all authorized workstations. */
    public static function hasDedicatedHost(): bool
    {
        return self::hostPortal() !== null;
    }

    public static function showsWorkstation(string $portal, string $role): bool
    {
        $host = self::hostPortal();
        return self::isRolePermitted($role, $portal) && ($host === null || $host === $portal);
    }

    public static function accessNotice(string $role)
    {
        $preferred = self::preferredPortal($role);
        return service('response')->setStatusCode(403)->setHeader('Cache-Control', 'private, no-store')
            ->setBody(view('portal/access_notice', [
                'portalTitle' => self::metadata()['title'],
                'destination' => self::urlFor($preferred, self::homePath($preferred)),
                'destinationLabel' => $preferred === 'main' ? 'Open personal portal' : 'Open your workstation',
                'mainUrl' => self::urlFor('main', 'portal'),
            ]));
    }

    /**
     * Returns allowed roles for a given portal.
     *
     * @return array<int, string>
     */
    public static function allowedRoles(string $portal): array
    {
        return match ($portal) {
            self::PORTAL_CLINIC => [
                'superadmin',
                'clinic_admin',
                'clinic_staff',
            ],
            self::PORTAL_GUIDANCE => [
                'superadmin',
                'guidance_admin',
                'guidance_supervisor',
                'counsellor',
            ],
            self::PORTAL_BMG => [
                'superadmin',
                'bmg_admin',
                'facilities_op',
            ],
            // Main portal allows everyone (students, employees, and staff using self-service)
            default => [
                'superadmin',
                'clinic_admin',
                'clinic_staff',
                'guidance_admin',
                'guidance_supervisor',
                'counsellor',
                'bmg_admin',
                'facilities_op',
                'audit_reader',
                'report_viewer',
                'student',
                'employee',
            ],
        };
    }

    /**
     * Checks if a user role is permitted on a portal.
     */
    public static function isRolePermitted(string $role, string $portal): bool
    {
        $allowed = self::allowedRoles($portal);
        return in_array($role, $allowed, true);
    }

    /**
     * Returns human-friendly portal title and badge.
     *
     * @return array{title: string, badge: string, logo: string}
     */
    public static function metadata(?string $portal = null): array
    {
        $p = $portal ?? self::current();

        return match ($p) {
            self::PORTAL_CLINIC => [
                'title' => 'University Health Services',
                'badge' => 'Clinic Staff Console',
                'logo'  => 'assets/img/brand/health-services.png',
            ],
            self::PORTAL_GUIDANCE => [
                'title' => 'Guidance & Counseling Center',
                'badge' => 'Counselor Workspace',
                'logo'  => 'assets/img/brand/guidance-center.png',
            ],
            self::PORTAL_BMG => [
                'title' => 'Biomechanical Goat (BMG)',
                'badge' => 'Facilities Operations (BMG)',
                'logo'  => 'assets/img/brand/FoundationU.png',
            ],
            default => [
                'title' => 'Campus Health & Guidance',
                'badge' => 'Student & Employee Portal',
                'logo'  => 'assets/img/brand/synapse-maroon.png',
            ],
        };
    }
}
