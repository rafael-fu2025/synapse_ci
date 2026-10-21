<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\QueueController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockSession;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;


/** No DatabaseTestTrait: the service constructor and all service calls are bypassed. */
final class QueueControllerSafetyTest extends CIUnitTestCase
{
    private QueueController $controller;
    private IncomingRequest $request;
    private Response $response;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertInstanceOf(MockSession::class, session());
        session()->remove('user');
        $this->request = Services::incomingrequest(null, false);
        $this->request->setMethod('POST');
        $this->request->removeHeader('HX-Request');
        $this->request->setGlobal('post', []);
        $this->request->setGlobal('get', []);
        Services::injectMock('request', $this->request);
        $this->response = new Response(config('App'));
        Services::injectMock('response', $this->response);

        // QueueController::__construct() creates a DB-backed final QueueService.
        $this->controller = (new ReflectionClass(QueueController::class))->newInstanceWithoutConstructor();
        $this->controller->initController($this->request, $this->response, new NullLogger());
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(QueueController::class, $method))->invoke($this->controller, ...$arguments);
    }

    public function testServiceRemainsUninitialized(): void
    {
        self::assertFalse((new ReflectionClass(QueueController::class))
            ->getProperty('queueService')->isInitialized($this->controller));
    }

    public static function supportedErrors(): iterable
    {
        yield 'forbidden' => [new DomainException('Staff authentication required.', 403)];
        yield 'not found' => [new RuntimeException('Ticket not found.', 404)];
        yield 'conflict' => [new DomainException('Ticket already completed.', 409)];
        yield 'invalid input' => [new InvalidArgumentException('Invalid queue date.', 422)];
    }

    #[DataProvider('supportedErrors')]
    public function testHtmxMutationReturnsSupportedStatusAndJsonError(\Exception $error): void
    {
        $this->request->setHeader('HX-Request', 'true');
        $response = $this->invoke('mutate', 'clinic', static function () use ($error): never {
            throw $error;
        });

        self::assertSame($this->response, $response);
        self::assertSame($error->getCode(), $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => $error->getMessage()], json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('supportedErrors')]
    public function testOrdinaryMutationRedirectsAndFlashesSupportedError(\Exception $error): void
    {
        $response = $this->invoke('mutate', 'guidance', static function () use ($error): never {
            throw $error;
        });

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertContains($response->getStatusCode(), [302, 303, 307]);
        self::assertSame(site_url('/guidance/queue'), $response->getHeaderLine('Location'));
        self::assertSame($error->getMessage(), session()->getFlashdata('error'));
    }

    public function testSuccessfulOrdinaryMutationExecutesOnceAndRedirectsWithoutService(): void
    {
        $calls = 0;
        $response = $this->invoke('mutate', 'clinic', static function () use (&$calls): void {
            ++$calls;
        });

        self::assertSame(1, $calls);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(site_url('/clinic/queue'), $response->getHeaderLine('Location'));
    }

    public static function unexpectedErrors(): iterable
    {
        foreach ([false, true] as $htmx) {
            yield ($htmx ? 'HX ' : 'ordinary ') . 'uncoded exception' => [new RuntimeException('Unexpected failure.'), $htmx];
            yield ($htmx ? 'HX ' : 'ordinary ') . 'unsupported status' => [new RuntimeException('Storage failure.', 500), $htmx];
            yield ($htmx ? 'HX ' : 'ordinary ') . 'engine error' => [new \Error('Unexpected engine error.'), $htmx];
        }
    }

    #[DataProvider('unexpectedErrors')]
    public function testUnexpectedFailureIsRethrownUnchanged(Throwable $error, bool $htmx): void
    {
        if ($htmx) {
            $this->request->setHeader('HX-Request', 'true');
        }
        try {
            $this->invoke('mutate', 'clinic', static function () use ($error): never {
                throw $error;
            });
            self::fail('Unexpected failure was swallowed.');
        } catch (Throwable $caught) {
            self::assertSame($error, $caught);
        }
    }

    public static function missingActors(): iterable
    {
        foreach ([null, [], ['role' => 'clinic_staff'], ['id' => null], ['id' => 0], ['id' => -1], 'staff'] as $index => $user) {
            yield 'invalid actor ' . $index => [$user];
        }
    }

    #[DataProvider('missingActors')]
    public function testActorIdNeverFallsBackToUserOne(mixed $user): void
    {
        if ($user !== null) {
            session()->set('user', $user);
        }
        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('An authenticated staff account is required.');
        $this->invoke('actorId');
    }

    public function testActorIdAcceptsActualSessionUserId(): void
    {
        foreach ([37, '82'] as $id) {
            session()->set('user', ['id' => $id]);
            self::assertSame((int) $id, $this->invoke('actorId'));
        }
    }

    public function testMissingActorInsideMutationProducesHtmx403(): void
    {
        $this->request->setHeader('HX-Request', 'true');
        $response = $this->invoke('mutate', 'clinic', fn () => $this->invoke('actorId'));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'An authenticated staff account is required.'],
            json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testQueueDateDefaultsToManilaTodayRegardlessOfServerTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('America/Los_Angeles');
            $manila = new DateTimeZone('Asia/Manila');
            $before = (new DateTimeImmutable('now', $manila))->format('Y-m-d');
            $actual = $this->invoke('queueDate');
            $after = (new DateTimeImmutable('now', $manila))->format('Y-m-d');
            self::assertContains($actual, [$before, $after]);
            self::assertSame('America/Los_Angeles', date_default_timezone_get());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public static function selectedDates(): iterable
    {
        yield 'GET day' => [[], ['date' => '2026-10-20'], '2026-10-20'];
        yield 'POST day' => [['date' => '2026-10-21'], [], '2026-10-21'];
        yield 'POST wins' => [['date' => '2026-10-21'], ['date' => '2026-10-20'], '2026-10-21'];
        yield 'empty POST falls back to GET' => [['date' => ''], ['date' => '2026-10-20'], '2026-10-20'];
        yield 'leap day' => [['date' => '2028-02-29'], [], '2028-02-29'];
    }

    #[DataProvider('selectedDates')]
    public function testQueueDateUsesSelectedPostOrGetDay(array $post, array $get, string $expected): void
    {
        $this->request->setGlobal('post', $post);
        $this->request->setGlobal('get', $get);
        self::assertSame($expected, $this->invoke('queueDate'));
    }

    public static function invalidDates(): iterable
    {
        foreach (['2026-02-29', '2026-02-30', '2026-13-01', '2026-10-00', '2026-1-01',
            'tomorrow', '2026-10-19T00:00:00+08:00', '2026-10-19 ', ['2026-10-19']] as $index => $date) {
            yield 'invalid date ' . $index => [$date];
        }
        yield 'null byte' => ["2026-10-19\0"];
    }

    #[DataProvider('invalidDates')]
    public function testQueueDateRejectsInvalidInputWith422(mixed $date): void
    {
        $this->request->setGlobal('post', ['date' => $date]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('Invalid queue date.');
        $this->invoke('queueDate');
    }

    public function testNullByteDateInsideMutationProducesHtmx422(): void
    {
        $this->request->setHeader('HX-Request', 'true');
        $this->request->setGlobal('post', ['date' => "2026-10-19\0"]);
        $response = $this->invoke('mutate', 'clinic', fn () => $this->invoke('queueDate'));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'Invalid queue date.'],
            json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }
}
