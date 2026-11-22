<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\TeamCalendarController;
use App\Controllers\TeamsController;
use CodeIgniter\Config\Factories;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\Security\SecurityInterface;
use CodeIgniter\Session\SessionInterface;
use Config\App;
use Config\RefreshRegions;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\TeamWorkspaceResponseGuard;

/**
 * Response-only regression: invoke the actual final controllers' private success
 * methods, never their DB-backed public operations or controller initialization.
 * Run with --no-configuration --do-not-cache-result and the isolated bootstrap at
 * tests/_support/team-workspace-response-bootstrap.php.
 */
final class TeamWorkspaceResponseTest extends TestCase
{
    private const BASE = 'https://teams-response.invalid/fixture/';
    private const CSRF = ['name' => 'fixture_csrf', 'hash' => 'synthetic-current-csrf'];

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('TEAM_WORKSPACE_RESPONSE_ISOLATED')) {
            self::fail('This test requires the isolated no-dotenv response bootstrap.');
        }
        self::assertSame([], TeamWorkspaceResponseGuard::$blocked, 'No DB or notification dependency may be attempted.');
    }

    protected function tearDown(): void
    {
        foreach (['request', 'response', 'security', 'session'] as $name) {
            Services::resetSingle($name);
        }
        Factories::reset('config');
        self::assertSame([], TeamWorkspaceResponseGuard::$blocked, 'No DB or notification dependency may be attempted.');
        parent::tearDown();
    }

    public static function taskResponses(): iterable
    {
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            $owner = 'teams.tasks.' . $unit;
            $other = $unit === 'clinic' ? 'guidance' : 'clinic';
            yield $unit . ' matching tasks owner' => [$unit, $owner, null, true, true, 'regions'];
            foreach ([
                'foreign chat owner' => 'teams.chat.' . $unit,
                'foreign calendar owner' => 'teams.calendar.' . $unit,
                'other unit tasks owner' => 'teams.tasks.' . $other,
                'direct form no owner header' => null,
                'explicit empty owner header' => '',
            ] as $label => $header) {
                yield $unit . ' ' . $label => [$unit, $header, null, true, true, 'navigate'];
            }
            yield $unit . ' comment matching board owner keeps detail redirect' => [$unit, $owner, 71, true, true, 'regions'];
            yield $unit . ' comment direct detail keeps detail redirect' => [$unit, null, 71, true, true, 'navigate'];
            yield $unit . ' comment foreign calendar keeps detail redirect' => [$unit, 'teams.calendar.' . $unit, 71, true, true, 'navigate'];
            yield $unit . ' rollout disabled matching owner' => [$unit, $owner, null, false, true, null];
            yield $unit . ' rollout disabled direct' => [$unit, null, null, false, true, null];
            yield $unit . ' owner outside rollout' => [$unit, $owner, null, true, false, null];
        }
    }

    #[DataProvider('taskResponses')]
    public function testTaskResponseUsesOnlyMatchingWorkspaceOwner(
        string $unit,
        ?string $header,
        ?int $id,
        bool $enabled,
        bool $allowOwner,
        ?string $mode,
    ): void {
        $message = $id === null ? 'Team task updated.' : 'Comment added.';
        $redirect = self::BASE . $unit . '/teams' . ($id === null ? '' : '/' . $id);
        $owner = 'teams.tasks.' . $unit;
        $payload = $this->successPayload(TeamsController::class, $unit, $header, $enabled, $allowOwner, [$message, $unit, $id]);
        $this->assertPayload($payload, $message, $redirect, $owner, ['navigation', 'results'], $mode, $header);
    }

    public static function calendarResponses(): iterable
    {
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            $owner = 'teams.calendar.' . $unit;
            $other = $unit === 'clinic' ? 'guidance' : 'clinic';
            yield $unit . ' matching calendar owner' => [$unit, $owner, true, true, 'regions'];
            foreach ([
                'foreign tasks owner' => 'teams.tasks.' . $unit,
                'foreign chat owner' => 'teams.chat.' . $unit,
                'other unit calendar owner' => 'teams.calendar.' . $other,
                'direct event edit no owner header' => null,
                'explicit empty owner header' => '',
            ] as $label => $header) {
                yield $unit . ' ' . $label => [$unit, $header, true, true, 'navigate'];
            }
            yield $unit . ' rollout disabled matching owner' => [$unit, $owner, false, true, null];
            yield $unit . ' rollout disabled direct event edit' => [$unit, null, false, true, null];
            yield $unit . ' owner outside rollout' => [$unit, $owner, true, false, null];
        }
    }

    #[DataProvider('calendarResponses')]
    public function testCalendarResponseUsesOnlyMatchingWorkspaceOwner(
        string $unit,
        ?string $header,
        bool $enabled,
        bool $allowOwner,
        ?string $mode,
    ): void {
        $message = 'Team event updated.';
        // Synthetic UTC event starts on the preceding date; redirect is Manila's date.
        $event = ['starts_at' => '2026-11-20 16:30:00'];
        $redirect = self::BASE . $unit . '/teams/calendar?date=2026-11-21';
        $owner = 'teams.calendar.' . $unit;
        $payload = $this->successPayload(TeamCalendarController::class, $unit, $header, $enabled, $allowOwner, [$message, $unit, $event]);
        $this->assertPayload($payload, $message, $redirect, $owner, ['notice', 'summary', 'results'], $mode, $header);
    }

    public static function feedbackResponses(): iterable
    {
        foreach ([TeamsController::class, TeamCalendarController::class] as $controller) {
            foreach (['clinic', 'guidance', 'facilities'] as $unit) {
                yield $controller . ' ' . $unit => [$controller, $unit];
            }
        }
    }

    #[DataProvider('feedbackResponses')]
    public function testNonToastJsonUsesOnlyMockFlashSession(string $controller, string $unit): void
    {
        $message = 'Synthetic saved response.';
        $calendar = $controller === TeamCalendarController::class;
        $owner = ($calendar ? 'teams.calendar.' : 'teams.tasks.') . $unit;
        $args = [$message, $unit, $calendar ? ['starts_at' => '2026-11-20 16:30:00'] : null];
        $payload = $this->successPayload($controller, $unit, $owner, true, true, $args, false);
        self::assertSame($message, $payload['message']);
        self::assertSame('regions', $payload['refresh']['mode']);
    }

    /** @param class-string<Controller> $class */
    private function successPayload(
        string $class,
        string $unit,
        ?string $header,
        bool $enabled,
        bool $allowOwner,
        array $arguments,
        bool $toast = true,
    ): array {
        $calendar = $class === TeamCalendarController::class;
        $owner = ($calendar ? 'teams.calendar.' : 'teams.tasks.') . $unit;
        $gate = (new ReflectionClass(RefreshRegions::class))->newInstanceWithoutConstructor();
        $gate->enabled = $enabled;
        $gate->owners = $allowOwner ? [$owner] : ['records.unrelated'];
        Factories::injectMock('config', RefreshRegions::class, $gate);

        $app = (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
        $app->baseURL = self::BASE;
        $app->indexPage = '';
        $app->allowedHostnames = [];
        Factories::injectMock('config', App::class, $app);
        $request = $this->createMock(IncomingRequest::class);
        $headers = ['accept' => 'application/json'];
        if ($toast) {
            $headers['x-synapse-feedback'] = 'toast';
        }
        if ($header !== null) {
            $headers['x-synapse-refresh-owner'] = $header;
        }
        $request->method('getHeaderLine')->willReturnCallback(static fn (string $name): string => $headers[strtolower($name)] ?? '');
        $request->method('isAJAX')->willReturn(false);
        $request->method('getUri')->willReturn(new SiteURI($app, $unit . ($calendar ? '/teams/calendar/events/71/edit' : '/teams/71/edit')));
        Services::injectMock('request', $request);

        $security = $this->createMock(SecurityInterface::class);
        $security->expects($this->once())->method('getTokenName')->willReturn(self::CSRF['name']);
        $security->expects($this->once())->method('getHash')->willReturn(self::CSRF['hash']);
        Services::injectMock('security', $security);

        $session = $this->createMock(SessionInterface::class);
        $session->expects($toast ? $this->never() : $this->once())->method('setFlashdata')->with('success', $arguments[0]);
        Services::injectMock('session', $session);

        $payload = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'no-store, private')->willReturnSelf();
        $response->expects($this->once())->method('setJSON')->willReturnCallback(static function ($body) use (&$payload, $response) {
            $payload = $body;
            return $response;
        });
        Services::injectMock('response', $response);

        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Controller::class, 'request'))->setValue($controller, $request);
        (new ReflectionProperty(Controller::class, 'response'))->setValue($controller, $response);
        $success = new ReflectionMethod($class, 'success');
        $result = (new ReflectionMethod($class, 'respond'))->invoke($controller, static fn () => $success->invokeArgs($controller, $arguments));
        self::assertSame($response, $result, 'The actual controller must return its JSON response.');
        self::assertIsArray($payload);
        return $payload;
    }

    private function assertPayload(
        array $payload,
        string $message,
        string $redirect,
        string $owner,
        array $regions,
        ?string $mode,
        ?string $header,
    ): void {
        $expected = ['success' => true, 'message' => $message, 'redirect' => $redirect, 'csrf' => self::CSRF];
        if ($mode !== null) {
            $expected['refresh'] = $mode === 'regions'
                ? ['mode' => 'regions', 'owner' => $owner, 'regions' => $regions]
                : ['mode' => 'navigate', 'url' => $redirect, 'history' => 'push'];
        }
        self::assertSame($expected, $payload, 'Owner header ' . json_encode($header) . ' must reconcile only its matching workspace; direct/foreign pages navigate to the ordinary redirect.');
    }
}
