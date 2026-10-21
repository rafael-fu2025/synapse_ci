<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Integrations\Mis\MisUnavailableException;
use App\Services\AuthenticatedUser;
use App\Services\AuthService;
use App\Services\Carousel\CarouselService;
use App\Services\PortalContext;

final class AuthController extends BaseController
{
    private AuthService $authService;
    private CarouselService $carouselService;

    public function __construct()
    {
        $this->authService     = new AuthService();
        $this->carouselService = new CarouselService();
    }

    /**
     * Display login form tailored to the active department portal.
     */
    public function login()
    {
        $user = (new AuthenticatedUser())->current();
        if ($user !== null) {
            $role = (string) ($user['role'] ?? 'student');
            if (!PortalContext::compatibleHost($role)) return PortalContext::accessNotice($role);
            return redirect()->to(PortalContext::loginLanding($role))->setHeader('Cache-Control', 'private, no-store');
        }

        $portal = PortalContext::current();
        $portalMeta = PortalContext::metadata($portal);

        return view('auth/login', [
            'title'      => 'Sign In - ' . $portalMeta['title'],
            'portal'     => $portal,
            'portalMeta' => $portalMeta,
            'slides'     => $this->carouselService->getSlidesForLogin(),
        ]);
    }

    /**
     * Process login submission with portal authorization checks.
     */
    public function attemptLogin()
    {
        // Never retain credentials in CodeIgniter's old-input flashdata.
        session()->remove('_ci_old_input');
        $identifier = $this->request->getPost('identifier');
        $password = $this->request->getPost('password');

        if (!is_string($identifier) || !is_string($password) || trim($identifier) === '' || trim($password) === '') {
            return redirect()->back()->with('error', 'Please enter your ID number/email and password.');
        }

        try {
            $sessionData = $this->authService->login($identifier, $password);
        } catch (MisUnavailableException $e) {
            // Never expose upstream bodies, credentials, API keys, or transport details.
            return redirect()->back()->with('error', 'MIS sign-in is currently unavailable. Connect to the university network and try again, or contact support.');
        }
        if ($sessionData === null) {
            return redirect()->back()->with('error', 'Invalid ID or password. Please verify your credentials with the MIS directory.');
        }

        $role = (string) $sessionData['role'];
        if (!PortalContext::compatibleHost($role)) {
            // A correct password on the wrong host must not leave an authenticated
            // department-host session behind. Never transfer credentials in a URL.
            AuthenticatedUser::clear();
            return PortalContext::accessNotice($role);
        }
        return redirect()->to(PortalContext::loginLanding($role))
            ->setHeader('Cache-Control', 'private, no-store')
            ->with('success', 'Welcome back, ' . $sessionData['name'] . '!');
    }

    /**
     * Sign out current user.
     */
    public function logout()
    {
        $this->authService->logout();
        return redirect()->to('/auth/login')->with('info', 'You have been safely signed out.');
    }
}
