<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Mis\HttpMisClient;
use App\Integrations\Mis\MisUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpMisClientTest extends TestCase
{
    public static function loginMethods(): iterable
    {
        yield 'student' => ['studentLogin', 'students', 'student_id'];
        yield 'employee' => ['employeeLogin', 'employees', 'employee_id'];
    }

    #[DataProvider('loginMethods')]
    public function testExactLoginRequestWithoutTokenPreflight(string $method, string $resource, string $idKey): void
    {
        $requests = [];
        $payload = [$idKey => '12345', 'first_name' => 'Test'];
        $client = new HttpMisClient('https://mis.example.test/sandbox/', 'test-api-key', 7,
            static function (...$request) use (&$requests, $payload): array {
                $requests[] = $request;
                return ['status' => 200, 'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
            });

        self::assertSame($payload, $client->$method(" \t12345\n", ' password untouched '));
        self::assertSame([[
            'POST',
            'https://mis.example.test/sandbox/api/v1/' . $resource . '/login',
            ['Content-Type: application/json', 'Accept: application/json', 'API-Key: test-api-key'],
            json_encode([$idKey => '12345', 'password' => ' password untouched '], JSON_THROW_ON_ERROR),
            7,
        ]], $requests);
    }

    public static function successfulPayloads(): iterable
    {
        yield 'flat' => [['student_id' => '123', 'first_name' => 'Test']];
        yield 'nested with tokens' => [[
            'data' => ['student_id' => '123', 'first_name' => 'Test'],
            'access_token' => 'login-access',
            'refresh_token' => 'login-refresh',
        ]];
        yield 'deeper envelope passed to mapper' => [['data' => ['employee' => ['employee_id' => '123']]]];
    }

    #[DataProvider('successfulPayloads')]
    public function testSuccessfulPayloadIsPassedThroughUnchanged(array $payload): void
    {
        foreach (['studentLogin', 'employeeLogin'] as $method) {
            $client = $this->clientReturning(200, json_encode($payload, JSON_THROW_ON_ERROR));
            self::assertSame($payload, $client->$method('123', 'secret'));
        }
    }

    public static function credentialRejections(): iterable
    {
        yield '401 JSON' => [401, '{"message":"Unauthorized"}'];
        yield '401 non JSON' => [401, 'Unauthorized'];
        yield 'unknown account' => [403, '{"message":"Account not recognized"}'];
        yield 'unknown account with punctuation' => [403, '{"message":"Account not recognized."}'];
        yield 'invalid credentials' => [403, '{"message":"invalid credentials"}'];
        yield 'error key' => [403, '{"error":"Invalid credentials"}'];
    }

    #[DataProvider('credentialRejections')]
    public function testOnlyCredentialRejectionsReturnNull(int $status, string $body): void
    {
        foreach (['studentLogin', 'employeeLogin'] as $method) {
            $calls = 0;
            $client = new HttpMisClient('https://mis.example.test', 'key', 5,
                static function () use ($status, $body, &$calls): array {
                    ++$calls;
                    return ['status' => $status, 'body' => $body];
                });
            self::assertNull($client->$method('123', 'secret'));
            self::assertSame(1, $calls, 'The transport must not perform fallback or token requests.');
        }
    }

    public static function unavailableResponses(): iterable
    {
        yield 'API key forbidden' => [403, '{"message":"Invalid API-Key: sensitive-key"}'];
        yield 'generic forbidden' => [403, '{"message":"Forbidden"}'];
        yield 'credential phrase in unrelated message' => [403, '{"message":"API key forbidden; invalid credentials"}'];
        yield 'HTML forbidden' => [403, '<html>Account not recognized</html>'];
        yield 'rate limited' => [429, '{"message":"invalid credentials"}'];
        yield 'server error' => [500, '{"message":"invalid credentials"}'];
        yield 'unavailable' => [503, 'upstream-secret'];
        yield 'bad request' => [400, '{"message":"invalid credentials"}'];
        yield 'redirect' => [302, 'https://secret.example.test/?token=secret'];
        yield 'no HTTP status' => [0, ''];
        yield 'invalid JSON' => [200, '{broken secret'];
        yield 'empty body' => [204, ''];
        yield 'null' => [200, 'null'];
        yield 'string' => [200, '"secret"'];
        yield 'number' => [200, '42'];
        yield 'boolean' => [200, 'false'];
        yield 'empty array' => [200, '[]'];
        yield 'empty object' => [200, '{}'];
        yield 'list rather than profile' => [200, '[{"student_id":"123"}]'];
    }

    #[DataProvider('unavailableResponses')]
    public function testFailuresThrowSafeUnavailableException(int $status, string $body): void
    {
        foreach (['studentLogin', 'employeeLogin'] as $method) {
            try {
                $this->clientReturning($status, $body)->$method('123', 'secret');
                self::fail('Expected an unavailable exception.');
            } catch (MisUnavailableException $exception) {
                self::assertSame((new MisUnavailableException())->getMessage(), $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
    }

    public static function transportFailures(): iterable
    {
        yield 'timeout' => ['Timeout contacting https://user:password@mis.example.test?api_key=secret'];
        yield 'TLS' => ['TLS certificate verification failed: secret'];
        yield 'connection' => ['Connection refused: secret'];
    }

    #[DataProvider('transportFailures')]
    public function testTransportErrorsDoNotLeakEvenThroughPreviousException(string $error): void
    {
        foreach (['studentLogin', 'employeeLogin'] as $method) {
            $client = new HttpMisClient('https://mis.example.test', 'key', 5,
                static function () use ($error): never {
                    throw new RuntimeException($error);
                });
            try {
                $client->$method('123', 'secret');
                self::fail('Expected an unavailable exception.');
            } catch (MisUnavailableException $exception) {
                self::assertSame((new MisUnavailableException())->getMessage(), $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
    }

    public static function timeoutSettings(): iterable
    {
        yield 'environment' => ['17', null, 17];
        yield 'explicit override' => ['17', 6, 6];
        yield 'minimum' => ['0', null, 2];
        yield 'maximum' => ['999', null, 60];
        yield 'invalid environment' => ['not-a-number', null, 10];
        yield 'explicit minimum' => ['17', -1, 2];
        yield 'explicit maximum' => ['17', 999, 60];
    }

    #[DataProvider('timeoutSettings')]
    public function testTimeoutIsConfiguredAndBounded(string $environment, ?int $explicit, int $expected): void
    {
        $key = 'FUMIS_TIMEOUT_SECONDS';
        $oldEnv = $_ENV[$key] ?? null;
        $oldServer = $_SERVER[$key] ?? null;
        $_ENV[$key] = $environment;
        $_SERVER[$key] = $environment;
        try {
            $received = null;
            $client = new HttpMisClient('https://mis.example.test', 'key', $explicit,
                static function ($method, $url, $headers, $body, $timeout) use (&$received): array {
                    $received = $timeout;
                    return ['status' => 200, 'body' => '{"student_id":"123"}'];
                });
            $client->studentLogin('123', 'secret');
            self::assertSame($expected, $received);
        } finally {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($oldEnv !== null) {
                $_ENV[$key] = $oldEnv;
            }
            if ($oldServer !== null) {
                $_SERVER[$key] = $oldServer;
            }
        }
    }

    public function testExceptionSupportsOptionalPreviousThrowable(): void
    {
        $previous = new RuntimeException('Internal failure');
        $exception = new MisUnavailableException(previous: $previous);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame((new MisUnavailableException())->getMessage(), $exception->getMessage());
    }

    public function testUnsafeOrMissingConfigurationDoesNotTransmitCredentials(): void
    {
        foreach ([['https://mis.example.test', ''], ['http://mis.example.test', 'key'],
            ['https://user:secret@mis.example.test', 'key'], ['https://mis.example.test?token=secret', 'key'],
            ['https://mis.example.test#fragment', 'key'], ['https://mis.example.test', "key\r\nHeader: value"]] as [$url, $key]) {
            $called = false;
            $client = new HttpMisClient($url, $key, 5, static function () use (&$called): array {
                $called = true;
                return ['status' => 200, 'body' => '{"student_id":"123"}'];
            });
            try {
                $client->studentLogin('123', 'secret');
                self::fail('Invalid configuration must fail closed.');
            } catch (MisUnavailableException $e) {
                self::assertFalse($called);
                self::assertSame((new MisUnavailableException())->getMessage(), $e->getMessage());
            }
        }
    }

    private function clientReturning(int $status, string $body): HttpMisClient
    {
        return new HttpMisClient('https://mis.example.test', 'key', 5,
            static fn (): array => ['status' => $status, 'body' => $body]);
    }
}
