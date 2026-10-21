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
        session()->set('user', $user);
        $this->request->setGlobal('post', ['user_id' => 1, 'counsellor_user_id' => 1]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionCode(403);
        $this->invoke('actorId');
    }

    public function testActorUsesSessionArray(): void
    {
        foreach ([37, '82'] as $id) {
            session()->set('user', ['id' => $id]);
            self::assertSame((int) $id, $this->invoke('actorId'));
        }
    }

    public function testOnlyExistingPolicyGrantsReadAny(): void
    {
        foreach ([['role' => 'superadmin'], ['permissions' => ['guidance.cases.read_any']]] as $user) {
            session()->set('user', $user);
            self::assertTrue($this->invoke('canReadAnyCase'));
        }
        foreach ([[], ['role' => 'admin'], ['role' => 'guidance_staff'], ['permissions' => ['guidance.cases.read']]] as $user) {
            session()->set('user', $user);
            self::assertFalse($this->invoke('canReadAnyCase'));
        }
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
        $this->setTransport('htmx');
        $response = $this->invoke('mutate', static function (): never {
            self::fail('An unauthenticated mutation was executed.');
        });
        self::assertSame(403, $response->getStatusCode());
    }

    public function testSuccessfulMutationRunsOnceAndPreservesFormAndJsonContracts(): void
    {
        session()->set('user', ['id' => 37]);
        $calls = 0;
        $operation = static function (int $actor) use (&$calls): array {
            self::assertSame(37, $actor);
            ++$calls;
            return ['/guidance/cases/9', 'Saved.'];
        };
        $response = $this->invoke('mutate', $operation);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(site_url('/guidance/cases/9'), $response->getHeaderLine('Location'));
        $this->setTransport('htmx');
        $response = $this->invoke('mutate', $operation);
        self::assertSame(2, $calls);
        self::assertSame(['success' => true, 'message' => 'Saved.', 'redirect' => site_url('/guidance/cases/9')],
            json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
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
        self::assertSame(3, substr_count($source, '$this->caseService->getSession($sessionId)'));
        self::assertStringNotContainsString("get('user.id')", $source);
        self::assertStringNotContainsString('$this->sessionModel->find(', $source);
        self::assertStringContainsString('(new UserModel())->scoped()->find(', $source);
        self::assertStringContainsString('(new \\App\\Models\\GuidanceFollowupModel())->scoped()->find(', $source);
        self::assertStringNotContainsString('getTraceAsString', $source);
        self::assertStringNotContainsString('$e->getMessage()', $source);
        $method = new ReflectionMethod(GuidanceController::class, 'startForTicket');
        $lines = file(APPPATH . 'Controllers/GuidanceController.php');
        $ticketSource = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringContainsString('openSessionForTicket($queueEntryId, $userId, $this->canReadAnyCase(), $this->postedSessionType())', $ticketSource);
        self::assertStringNotContainsString('createCase(', $ticketSource);
        self::assertStringNotContainsString('openSession(', $ticketSource);
        self::assertStringNotContainsString('->find(', $ticketSource);
        self::assertFalse((new ReflectionClass(GuidanceController::class))->getProperty('caseService')->isInitialized($this->controller));
    }
}
