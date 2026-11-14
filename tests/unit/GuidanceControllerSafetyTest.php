<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\GuidanceController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockSession;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/** DB-free: bypass the constructor and the final, DB-backed service. */
final class GuidanceControllerSafetyTest extends CIUnitTestCase
{
    private GuidanceController $controller;
    private IncomingRequest $request;
    private Response $response;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertInstanceOf(MockSession::class, session());
        session()->remove('user');
        $this->request = Services::incomingrequest(null, false);
        $this->request->setMethod('POST');
        foreach (['HX-Request', 'X-Requested-With', 'Accept', 'Content-Type'] as $header) {
            $this->request->removeHeader($header);
        }
        $this->request->setGlobal('post', []);
        $this->request->setGlobal('get', []);
        Services::injectMock('request', $this->request);
        $this->response = new Response(config('App'));
        Services::injectMock('response', $this->response);
        $this->controller = (new ReflectionClass(GuidanceController::class))->newInstanceWithoutConstructor();
        $this->controller->initController($this->request, $this->response, new NullLogger());
        $this->setActor(['id' => 37]);
    }

    private function setActor(?array $user, array $permissions = ['guidance.cases.create', 'guidance.cases.write', 'guidance.cases.read']): void
    {
        session()->set('user', $user);
        $resolver = function (?string $permission = null) use ($user, $permissions): array {
            if (!is_array($user) || empty($user['id']) || !is_numeric($user['id']) || (int) $user['id'] <= 0) {
                throw new \DomainException('An authenticated guidance account is required.', 403);
            }
            if ($permission !== null && !in_array($permission, $permissions, true)) {
                throw new \DomainException('Guidance permission forbidden.', 403);
            }
            return [
                'id' => (int) $user['id'],
                'auth_version' => 1,
                'person_id' => 10,
                'tenant_id' => 1,
                'username' => 'counsellor',
                'email' => 'counsellor@example.com',
                'name' => 'Guidance Counsellor',
                'role' => $user['role'] ?? 'counsellor',
                'permissions' => $permissions,
                'logged_in' => true,
            ];
        };
        $prop = (new ReflectionClass(GuidanceController::class))->getProperty('actorResolver');
        $prop->setAccessible(true);
        $prop->setValue($this->controller, $resolver);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(GuidanceController::class, $method))->invoke($this->controller, ...$arguments);
    }

    public static function invalidActors(): iterable
    {
        foreach ([null, [], 'staff', ['id' => 0], ['id' => -1], ['id' => '7garbage'], ['id' => true], ['id' => []]] as $i => $user) {
            yield 'invalid ' . $i => [$user];
        }
    }

    #[DataProvider('invalidActors')]
    public function testActorNeverDefaultsOrUsesPostedIdentity(mixed $user): void
    {
        $this->setActor(is_array($user) ? $user : null);
        $this->request->setGlobal('post', ['user_id' => 1, 'counsellor_user_id' => 1]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionCode(403);
        $this->invoke('freshActor');
    }

    public function testActorUsesSessionArray(): void
    {
        foreach ([37, '82'] as $id) {
            $this->setActor(['id' => $id]);
            $actor = $this->invoke('freshActor');
            self::assertSame((int) $id, $actor['id']);
        }
    }

    public function testOnlyExistingPolicyGrantsReadAny(): void
    {
        $this->setActor(['id' => 37, 'role' => 'counsellor'], ['guidance.cases.read', 'guidance.cases.read_any']);
        $actor = $this->invoke('freshActor', 'guidance.cases.read_any');
        self::assertTrue(in_array('guidance.cases.read_any', $actor['permissions'], true));

        $this->setActor(['id' => 37, 'role' => 'counsellor'], ['guidance.cases.read']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionCode(403);
        $this->invoke('freshActor', 'guidance.cases.read_any');
    }

    public static function failures(): iterable
    {
        foreach ([403, 404, 409, 422, 0, 500] as $code) {
            foreach (['htmx', 'ajax', 'json', 'form'] as $transport) {
                yield $code . ' ' . $transport => [$code, $transport];
            }
        }
    }

    #[DataProvider('failures')]
    public function testMutationFailuresAreSafe(int $code, string $transport): void
    {
        session()->set('user', ['id' => 37]);
        $this->setTransport($transport);
        $response = $this->invoke('mutate', static function () use ($code): never {
            throw new \RuntimeException('SQL secret encryption.key note plaintext', $code);
        });
        if ($transport === 'form') {
            self::assertInstanceOf(RedirectResponse::class, $response);
            $message = session()->getFlashdata('error');
            self::assertNotEmpty($message);
            self::assertNull(session()->get('_ci_old_input'));
        } else {
            self::assertSame($code === 0 ? 500 : $code, $response->getStatusCode());
            self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $data = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($data['success']);
            $message = $data['message'];
        }
        self::assertStringNotContainsString('SQL', $message);
        self::assertStringNotContainsString('encryption.key', $message);
        self::assertStringNotContainsString('plaintext', $message);
    }

    private function setTransport(string $transport): void
    {
        match ($transport) {
            'htmx' => $this->request->setHeader('HX-Request', 'true'),
            'ajax' => $this->request->setHeader('X-Requested-With', 'XMLHttpRequest'),
            'json' => $this->request->setHeader('Accept', 'application/json'),
            default => null,
        };
    }

    public function testMutationRequiresActorBeforeCallback(): void
    {
        $this->setActor(null);
        $this->setTransport('htmx');
        $response = $this->invoke('mutate', static function (): never {
            self::fail('An unauthenticated mutation was executed.');
        });
        self::assertSame(403, $response->getStatusCode());
    }

    public function testSuccessfulMutationRunsOnceAndPreservesFormAndJsonContracts(): void
    {
        $this->setActor(['id' => 37]);
        $calls = 0;
        $operation = static function (array $actor) use (&$calls): array {
            self::assertSame(37, $actor['id']);
            ++$calls;
            return ['/guidance/cases/9', 'Saved.'];
        };
        $response = $this->invoke('mutate', $operation);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(site_url('/guidance/cases/9'), $response->getHeaderLine('Location'));
        $this->setTransport('htmx');
        $response = $this->invoke('mutate', $operation);
        self::assertSame(2, $calls);
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'success' => true,
            'message' => 'Saved.',
            'redirect' => site_url('/guidance/cases/9'),
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ], $payload);
        self::assertNotEmpty($payload['csrf']['name']);
        self::assertNotEmpty($payload['csrf']['hash']);
    }

    public function testNoteErrorsPreserveExpectedStatusAndHideUnexpectedDetails(): void
    {
        $this->request->setGlobal('get', ['fragment' => '1']);
        foreach ([403, 404, 500, 0] as $code) {
            $response = $this->invoke('noteFailure', new \RuntimeException('encryption.key SQL private note', $code), 91);
            self::assertSame($code === 0 ? 500 : $code, $response->getStatusCode());
            self::assertStringContainsString('modal-body', $response->getBody());
            self::assertStringNotContainsString('encryption.key', $response->getBody());
            self::assertStringNotContainsString('private note', $response->getBody());
        }
    }

    public function testFinalServiceIntegrationSourceContracts(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/GuidanceController.php');
        // History now resolves scoped case metadata after the audited read for its oversight marker.
        self::assertSame(4, substr_count($source, '$this->caseService->getSession($sessionId)'));
        self::assertStringNotContainsString("get('user.id')", $source);
        self::assertStringNotContainsString('$this->sessionModel->find(', $source);
        self::assertStringContainsString('(new UserModel())->scoped()->find(', $source);
        self::assertStringContainsString('(new \\App\\Models\\GuidanceFollowupModel())->scoped()->find(', $source);
        self::assertStringNotContainsString('getTraceAsString', $source);
        self::assertStringNotContainsString('$e->getMessage()', $source);
        $method = new ReflectionMethod(GuidanceController::class, 'startForTicket');
        $lines = file(APPPATH . 'Controllers/GuidanceController.php');
        $ticketSource = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringContainsString('openSessionForTicket($queueEntryId, (int) $actor[\'id\']', $ticketSource);
        self::assertStringNotContainsString('createCase(', $ticketSource);
        self::assertStringNotContainsString('openSession(', $ticketSource);
        self::assertStringNotContainsString('->find(', $ticketSource);
        self::assertFalse((new ReflectionClass(GuidanceController::class))->getProperty('caseService')->isInitialized($this->controller));
    }
}
