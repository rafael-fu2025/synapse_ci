<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Integrations\Mis\MisAccountException;
use App\Integrations\Mis\MisInactiveAccountException;
use App\Integrations\Mis\MisUnavailableException;
use App\Services\Audit\AuditService;
use App\Services\AuthenticatedUser;
use App\Services\AuthService;
use App\Services\Carousel\CarouselService;
use App\Services\PortalContext;
use Throwable;

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
        service('response')->setHeader('Cache-Control', 'private, no-store');
        $user = (new AuthenticatedUser())->current();
        if ($user !== null) {
            $role = (string) ($user['role'] ?? 'student');
            if (!PortalContext::compatibleHost($role)) return PortalContext::accessNotice($role);
            return redirect()->to(PortalContext::loginLanding($role))->setHeader('Cache-Control', 'private, no-store');
        }

        // A followed fetch redirect must not render HTML or acknowledge the notice.
        // denied() reads the recorded reason, never calls current() recursively.
        $request = service('request');
        if (AuthenticatedUser::isBackgroundRequest($request) || strtoupper($request->getMethod()) !== 'GET') {
            return AuthenticatedUser::denied($request, false, AuthenticatedUser::AUTHENTICATION_MESSAGE, site_url('auth/login'));
        }

        $notice = AuthenticatedUser::noticeReason();
        // Do not repeat an already rendered auth warning on a later document visit.
        if (in_array(session()->getFlashdata('warning'), [AuthenticatedUser::EXPIRED_MESSAGE,
            AuthenticatedUser::REAUTHENTICATION_MESSAGE, AuthenticatedUser::AUTHENTICATION_MESSAGE], true)) {
            session()->remove('warning');
        }
        if ($notice !== null) {
            session()->remove('error');
            session()->setFlashdata('warning', AuthenticatedUser::noticeMessage($notice));
        }

        $portal = PortalContext::current();
        $portalMeta = PortalContext::metadata($portal);

        $html = view('auth/login', [
            'title'      => 'Sign In - ' . $portalMeta['title'],
            'portal'     => $portal,
            'portalMeta' => $portalMeta,
            'slides'     => $this->carouselService->getSlidesForLogin(),
        ]);
        // Acknowledge only after the complete login document rendered successfully.
        session()->remove(['_auth_notice', '_auth_expired']);
        return $html;
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
        $allowStudentMis = PortalContext::allowsStudentLogin();

        $identifierMissing = !is_string($identifier) || trim($identifier) === '';
        $passwordMissing = !is_string($password) || trim($password) === '';
        if ($identifierMissing || $passwordMissing) {
            $fieldErrors = [];
            if ($identifierMissing) {
                $fieldErrors['identifier'] = $allowStudentMis
                    ? 'Enter your student or employee ID number.'
                    : 'Enter your employee ID number.';
            }
            if ($passwordMissing) {
                $fieldErrors['password'] = 'Enter your password.';
            }
            $message = $allowStudentMis
                ? 'Please enter your student or employee ID number and password.'
                : 'Please enter your employee ID number and password.';
            return redirect()->back()
                ->with('error', $message)
                ->with('auth_field_errors', $fieldErrors);
        }

        try {
            $sessionData = $this->authService->login($identifier, $password, $allowStudentMis);
        } catch (MisInactiveAccountException $e) {
            // The MIS directory does not list this account as active. Say so in the words the
            // university systems use, so the employee goes to HR instead of to the network.
            return redirect()->back()->with('error', $e->getMessage());
        } catch (MisAccountException $e) {
            // The upstream answered and could not verify the account. Blaming the network here
            // sends staff down the wrong path: an inactive or unregistered MIS account is the
            // usual cause, and HR owns that outcome.
            return redirect()->back()->with('error', 'MIS could not verify this account. If your MIS account is inactive or not yet registered, contact the HR Department. Otherwise try again shortly.');
        } catch (MisUnavailableException $e) {
            // Never expose upstream bodies, credentials, API keys, or transport details.
            return redirect()->back()->with('error', 'MIS sign-in is currently unavailable. Connect to the university network and try again, or contact support.');
        }
        if ($sessionData === null) {
            $this->recordAuthAudit('auth.login_failed', '0', 'login_failed', [
                'identifier_hash' => hash('sha256', strtolower(trim($identifier))),
                'portal' => PortalContext::current(),
            ]);
            return redirect()->back()->with('error', 'Invalid ID or password. Please verify your credentials with the MIS directory.');
        }

        $role = (string) $sessionData['role'];
        if (!PortalContext::compatibleHost($role)) {
            // A correct password on the wrong host must not leave an authenticated
            // department-host session behind. Never transfer credentials in a URL.
            $this->recordAuthAudit('auth.login_rejected', (string) ($sessionData['id'] ?? '0'), 'host_mismatch', [
                'portal' => PortalContext::current(),
            ], $sessionData['id'] ?? null, $role);
            AuthenticatedUser::clear();
            return PortalContext::accessNotice($role);
        }
        $this->recordAuthAudit('auth.login_succeeded', (string) ($sessionData['id'] ?? '0'), 'login', [
            'portal' => PortalContext::current(),
        ], $sessionData['id'] ?? null, $role);
        return redirect()->to(PortalContext::loginLanding($role))
            ->setHeader('Cache-Control', 'private, no-store')
            ->with('success', 'Welcome back, ' . $sessionData['name'] . '!');
    }

    public function confirmLogout()
    {
        service('response')->setHeader('Cache-Control', 'private, no-store');
        return view('auth/logout', ['title' => 'Confirm Sign Out']);
    }

    /**
     * Sign out current user.
     */
    public function logout()
    {
        $user = (new AuthenticatedUser())->current();
        $this->authService->logout();
        if ($user !== null) {
            $this->recordAuthAudit('auth.logout', (string) ($user['id'] ?? '0'), 'logout', [
                'portal' => PortalContext::current(),
            ], $user['id'] ?? null, (string) ($user['role'] ?? ''));
        }
        return redirect()->to('/auth/login')->with('info', 'You have been safely signed out.');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordAuthAudit(
        string $eventType,
        string $entityId,
        string $action,
        array $payload = [],
        int|string|null $actorUserId = null,
        ?string $actorRole = null
    ): void {
        try {
            (new AuditService())->log($eventType, 'user', $entityId, $action, $payload, $actorUserId, $actorRole);
        } catch (Throwable $e) {
            log_message('warning', 'Auth audit logging skipped: ' . $e->getMessage());
        }
    }
}
