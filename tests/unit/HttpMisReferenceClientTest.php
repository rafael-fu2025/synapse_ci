<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Mis\HttpMisClient;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class HttpMisReferenceClientTest extends CIUnitTestCase
{
    private array $requests = [];
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->injectTokenCache('cached-access');
    }

    private function injectTokenCache(?string $token, ?string $saved = null): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->with('fumis_service_access_token')->willReturn($token);
        if ($saved === null) {
            $cache->expects(self::never())->method('save');
        } else {
            $cache->expects(self::once())->method('save')->with('fumis_service_access_token', $saved, 480)->willReturn(true);
        }
        Services::injectMock('cache', $cache);
    }

    private function client(array $payloads): HttpMisClient
    {
        $this->responses = $payloads;
        return new HttpMisClient('https://mis.example.test/sandbox/', 'test-key', 7,
            function (...$request): array {
                $this->requests[] = $request;
                self::assertNotEmpty($this->responses, 'Unexpected request (no network transport is used).');
                $payload = array_shift($this->responses);
                if ($payload instanceof \Throwable) {
                    throw $payload;
                }
                return ['status' => 200, 'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
            });
    }

    private static function page(array $rows, int $page = 1, int $max = 2, int $limit = 1): array
    {
        return ['data' => ['data' => $rows, 'limit' => $limit, 'current_page' => $page, 'max_page' => $max]];
    }

    public static function tokenEnvelopes(): iterable
    {
        yield 'live nested' => [true];
        yield 'legacy top level' => [false];
    }

    #[DataProvider('tokenEnvelopes')]
    public function testTokenLifecycleAndTransport(bool $nested): void
    {
        $this->injectTokenCache(null, 'access');
        $generate = ['refresh_token' => 'refresh'];
        $refresh = ['access_token' => 'access', 'refresh_token' => 'next', 'expires_at' => '2099-01-01'];
        $rows = [['code' => ' CCS ', 'name' => ' Computing ']];
        $client = $this->client([
            $nested ? ['status' => 'success', 'message' => 'ok', 'data' => $generate] : $generate,
            $nested ? ['data' => $refresh] : $refresh,
            ['data' => $rows],
        ]);
        self::assertSame([['code' => 'CCS', 'name' => 'Computing']], $client->fetchDepartments());
        self::assertCount(3, $this->requests);
        foreach ($this->requests as $request) {
            self::assertContains('API-Key: test-key', $request[2]);
            self::assertSame(7, $request[4]);
        }
        self::assertSame(['POST', 'https://mis.example.test/sandbox/api/tokens/generate'], array_slice($this->requests[0], 0, 2));
        self::assertSame('[]', $this->requests[0][3]);
        self::assertSame('https://mis.example.test/sandbox/api/tokens/refresh', $this->requests[1][1]);
        self::assertContains('Authorization: Bearer refresh', $this->requests[1][2]);
        self::assertSame('GET', $this->requests[2][0]);
        self::assertNull($this->requests[2][3]);
        self::assertContains('Authorization: Bearer access', $this->requests[2][2]);
        self::assertSame([], $this->responses);
    }

    public function testDepartmentsFetchAllPagesWithCachedToken(): void
    {
        $client = $this->client([
            self::page([['code' => ' CCS ', 'name' => ' Computing ']]),
            self::page([['code' => 'CON', 'name' => 'Nursing']], 2),
        ]);
        self::assertSame([
            ['code' => 'CCS', 'name' => 'Computing'], ['code' => 'CON', 'name' => 'Nursing'],
        ], $client->fetchDepartments());
        self::assertSame('https://mis.example.test/sandbox/api/v1/employees/departments', $this->requests[0][1]);
        self::assertSame('https://mis.example.test/sandbox/api/v1/employees/departments?page=2&limit=1', $this->requests[1][1]);
        self::assertCount(2, $this->requests);
    }

    public function testProgramsMapAllThreePages(): void
    {
        $payloads = [];
        $expected = [];
        for ($page = 1; $page <= 3; ++$page) {
            $payloads[] = self::page([['course' => ' P' . $page . ' ', 'coursedesc' => ' Program ' . $page . ' ', 'department' => ' CCS ']], $page, 3);
            $expected[] = ['code' => 'P' . $page, 'name' => 'Program ' . $page];
        }
        self::assertSame($expected, $this->client($payloads)->fetchPrograms());
        self::assertSame('https://mis.example.test/sandbox/api/v1/students/programs', $this->requests[0][1]);
        self::assertSame([], $this->responses);
    }

    public function testLiveAcademicGroupDoesNotInferEmployeeDepartmentLink(): void
    {
        self::assertSame([['code' => 'BSIT', 'name' => 'Information Technology']],
            $this->client([['data' => [[
                'course' => ' BSIT ', 'coursedesc' => ' Information Technology ',
                'department' => ' Academic Group 16 ',
            ]]]])->fetchPrograms());
    }

    public function testCanonicalExplicitDepartmentLinkIsPreserved(): void
    {
        self::assertSame([
            ['code' => 'BSIT', 'name' => 'Information Technology', 'department_code' => 'CCS'],
        ], $this->client([['data' => [[
            'code' => ' BSIT ', 'name' => ' Information Technology ', 'department_code' => ' CCS ',
        ]]]])->fetchPrograms());
    }

    public static function liveProgramDuplicates(): iterable
    {
        $program = ['department' => 'CCS', 'course' => 'BSIT', 'coursedesc' => 'Information Technology'];
        yield 'exact within page' => [$program, $program, false];
        yield 'exact across pages' => [$program, $program, true];
        $trimmed = ['department' => ' CCS ', 'course' => ' BSIT ', 'coursedesc' => ' Information Technology '];
        yield 'normalized within page' => [$program, $trimmed, false];
        yield 'normalized across pages' => [$trimmed, $program, true];
    }

    #[DataProvider('liveProgramDuplicates')]
    public function testIdenticalLiveProgramsCoalesce(array $first, array $duplicate, bool $acrossPages): void
    {
        $distinct = ['department' => 'CCS', 'course' => 'BSCS', 'coursedesc' => 'Computer Science'];
        $payloads = $acrossPages
            ? [self::page([$first, $distinct], 1, 2, 2), self::page([$duplicate], 2, 2, 2)]
            : [self::page([$first, $duplicate, $distinct], 1, 1, 3)];
        self::assertSame([
            ['code' => 'BSIT', 'name' => 'Information Technology'],
            ['code' => 'BSCS', 'name' => 'Computer Science'],
        ], $this->client($payloads)->fetchPrograms());
        self::assertSame([], $this->responses);
    }

    public static function rejectedProgramDuplicates(): iterable
    {
        $live = ['department' => 'CCS', 'course' => 'BSIT', 'coursedesc' => 'Information Technology'];
        $canonical = ['department_code' => 'CCS', 'code' => 'BSIT', 'name' => 'Information Technology'];
        foreach ([
            'conflicting name' => [$live, array_replace($live, ['coursedesc' => 'Different'])],
            'conflicting department' => [$live, array_replace($live, ['department' => 'CON'])],
            'canonical duplicate' => [$canonical, $canonical],
            'canonical then live' => [$canonical, $live],
            'live then canonical' => [$live, $canonical],
        ] as $label => [$first, $second]) {
            yield $label . ' within page' => [$first, $second, false];
            yield $label . ' across pages' => [$first, $second, true];
        }
    }

    #[DataProvider('rejectedProgramDuplicates')]
    public function testOtherProgramDuplicatesFailAtomically(array $first, array $second, bool $acrossPages): void
    {
        $payloads = $acrossPages
            ? [self::page([$first]), self::page([$second], 2)]
            : [self::page([$first, $second], 1, 1, 2)];
        self::assertSame([], $this->client($payloads)->fetchPrograms());
        self::assertSame([], $this->responses);
    }

    public function testCanonicalCatalogDuplicatesRemainRejected(): void
    {
        $row = ['code' => 'A', 'name' => 'Alpha'];
        foreach (['fetchDepartments', 'fetchYearLevels'] as $method) {
            self::assertSame([], $this->client([['data' => [$row, $row]]])->$method());
            self::assertSame([], $this->client([self::page([$row]), self::page([$row], 2)])->$method());
        }
    }

    public function testGroupedLevelsKeepSchoolIdentity(): void
    {
        $groups = ['College' => [1 => ' First Year ', 2 => 'Second Year'], 'KIN' => [1 => 'Kindergarten'],
            'ELEM' => [1 => 'Grade 1'], 'HS' => [1 => 'Grade 7'], 'SHS' => [1 => 'Grade 11']];
        self::assertSame([
            ['code' => 'College:1', 'name' => 'College — First Year'],
            ['code' => 'College:2', 'name' => 'College — Second Year'],
            ['code' => 'KIN:1', 'name' => 'KIN — Kindergarten'],
            ['code' => 'ELEM:1', 'name' => 'ELEM — Grade 1'],
            ['code' => 'HS:1', 'name' => 'HS — Grade 7'],
            ['code' => 'SHS:1', 'name' => 'SHS — Grade 11'],
        ], $this->client([['data' => $groups]])->fetchYearLevels());
        self::assertSame('https://mis.example.test/sandbox/api/v1/students/levels', $this->requests[0][1]);
    }

    public function testLegacyCanonicalListsForEveryCatalog(): void
    {
        foreach (['fetchDepartments', 'fetchPrograms', 'fetchYearLevels'] as $method) {
            $row = ['code' => ' A ', 'name' => ' Alpha '];
            $expected = ['code' => 'A', 'name' => 'Alpha'];
            if ($method === 'fetchPrograms') {
                $row['department_code'] = ' CCS ';
                $expected['department_code'] = 'CCS';
            }
            self::assertSame([$expected], $this->client([['data' => [$row]]])->$method());
        }
        self::assertSame([['code' => 'P', 'name' => 'Program']],
            $this->client([['data' => [['code' => 'P', 'name' => 'Program']]]])->fetchPrograms());
    }

    public static function invalidPages(): iterable
    {
        $row = ['code' => 'B', 'name' => 'Beta'];
        yield 'missing response data' => [[]];
        yield 'missing page' => [new RuntimeException('sensitive upstream URL and token')];
        yield 'wrong current page' => [self::page([$row], 1)];
        yield 'changed max page' => [self::page([$row], 2, 3)];
        yield 'changed limit' => [self::page([$row], 2, 2, 2)];
        yield 'empty last page' => [self::page([], 2)];
        yield 'legacy response after pagination' => [['data' => [$row]]];
        yield 'malformed row' => [self::page([['code' => 'B', 'name' => []]], 2)];
        yield 'duplicate row' => [self::page([['code' => 'A', 'name' => 'Alpha']], 2)];
        yield 'too many rows' => [self::page([$row, $row], 2)];
        $bad = self::page([$row], 2);
        unset($bad['data']['limit']);
        yield 'missing metadata' => [$bad];
        $bad = self::page([$row], 2);
        $bad['data']['current_page'] = '1';
        yield 'inconsistent string current page' => [$bad];
        $bad = self::page([$row], 2);
        $bad['data']['limit'] = '2';
        yield 'inconsistent string limit' => [$bad];
        $bad = self::page([$row], 2);
        $bad['data']['max_page'] = '3';
        yield 'inconsistent string max page' => [$bad];
    }

    #[DataProvider('invalidPages')]
    public function testInvalidLaterPageDiscardsEarlierRows(mixed $second): void
    {
        self::assertSame([], $this->client([
            self::page([['code' => 'A', 'name' => 'Alpha']]), $second,
        ])->fetchDepartments());
        self::assertCount(2, $this->requests);
    }

    public function testMixedPaginationMetadataTypesAcrossPages(): void
    {
        $payloads = [];
        $expected = [];
        for ($page = 1; $page <= 3; ++$page) {
            $rows = [];
            for ($i = 0; $i < 25; ++$i) {
                $rows[] = ['code' => $page . '-' . $i, 'name' => 'Department'];
            }
            array_push($expected, ...$rows);
            $payload = self::page($rows, $page, 3, 25);
            if ($page === 2) {
                $payload['data']['limit'] = '25';
                $payload['data']['current_page'] = '2';
            } elseif ($page === 3) {
                $payload['data']['max_page'] = '3';
            }
            $payloads[] = $payload;
        }
        self::assertSame($expected, $this->client($payloads)->fetchDepartments());
        self::assertSame('https://mis.example.test/sandbox/api/v1/employees/departments?page=3&limit=25', $this->requests[2][1]);
        self::assertSame([], $this->responses);
    }

    public function testCanonicalStringMetadataOnFirstPage(): void
    {
        $row = ['code' => 'A', 'name' => 'Alpha'];
        $payload = ['data' => ['data' => [$row], 'limit' => '10000', 'current_page' => '1', 'max_page' => '1']];
        self::assertSame([$row], $this->client([$payload])->fetchDepartments());
    }

    public static function invalidPaginationIntegers(): iterable
    {
        foreach (['limit', 'current_page', 'max_page'] as $field) {
            foreach ([0, -1, 1.5, true, false, null, [], '', '0', '-1', '+1', '01', ' 1', '1 ',
                "1\n", '1.0', '1e0', '1junk', '999999999999999999999999999999999999'] as $index => $value) {
                yield $field . '-' . $index => [$field, $value];
            }
            yield $field . '-out-of-bounds-int' => [$field, $field === 'limit' ? 10001 : 401];
            yield $field . '-out-of-bounds-string' => [$field, $field === 'limit' ? '10001' : '401'];
        }
    }

    #[DataProvider('invalidPaginationIntegers')]
    public function testInvalidMetadataIsRejectedAtomically(string $field, mixed $value): void
    {
        $second = self::page([['code' => 'B', 'name' => 'Beta']], 2);
        $second['data'][$field] = $value;
        self::assertSame([], $this->client([
            self::page([['code' => 'A', 'name' => 'Alpha']]), $second,
        ])->fetchDepartments());
        self::assertCount(2, $this->requests);
    }

    public function testIntegralJsonFloatMetadataIsRejected(): void
    {
        foreach (['limit', 'current_page', 'max_page'] as $field) {
            $payload = self::page([['code' => 'A', 'name' => 'Alpha']], 1, 1);
            $payload['data'][$field] = 1.0;
            $client = new HttpMisClient('https://mis.example.test', 'key', 5,
                static fn (): array => ['status' => 200, 'body' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
            self::assertSame([], $client->fetchDepartments());
        }
    }

    public function testBoundsAndIncompleteFirstPages(): void
    {
        $row = ['code' => 'A', 'name' => 'Alpha'];
        foreach ([self::page([$row], 1, 401), self::page([$row], 1, 2, 2),
            self::page([$row], 1, 1, 10001), self::page([$row], 0),
            ['data' => array_fill(0, 10001, $row)]] as $payload) {
            self::assertSame([], $this->client([$payload])->fetchDepartments());
        }
        $rows = [];
        for ($i = 0; $i < 5000; ++$i) {
            $rows[] = ['code' => 'A' . $i, 'name' => 'Alpha'];
        }
        $more = array_map(static fn (array $row): array => ['code' => 'B' . $row['code'], 'name' => 'Beta'], $rows);
        self::assertSame([], $this->client([
            self::page($rows, 1, 3, 5000), self::page($more, 2, 3, 5000),
            self::page([['code' => 'C', 'name' => 'Gamma']], 3, 3, 5000),
        ])->fetchDepartments());
    }

    public function testInclusivePageAndRowBounds(): void
    {
        $payloads = [];
        for ($page = 1; $page <= 400; ++$page) {
            $rows = [];
            for ($i = 0; $i < 25; ++$i) {
                $rows[] = ['code' => $page . '-' . $i, 'name' => 'Department'];
            }
            $payloads[] = self::page($rows, $page, 400, 25);
        }
        self::assertCount(10000, $this->client($payloads)->fetchDepartments());
        self::assertCount(400, $this->requests);
    }

    public function testMalformedProgramsAndLevelsFailAtomically(): void
    {
        foreach ([['department' => [], 'course' => 'P', 'coursedesc' => 'Program'],
            ['department' => 'CCS', 'course' => 123, 'coursedesc' => 'Program'],
            ['course' => 'P', 'coursedesc' => 'Program'], ['code' => 'P', 'name' => ' ']] as $row) {
            self::assertSame([], $this->client([['data' => [$row]]])->fetchPrograms());
        }
        foreach ([['College' => [1 => 'First'], 'HS' => [1 => []]], ['College' => 'invalid'],
            ['College' => [1 => '']], ['College' => [1 => 123]]] as $groups) {
            self::assertSame([], $this->client([['data' => $groups]])->fetchYearLevels());
        }
    }

    public function testTokenFailuresStopBeforeDirectoryRequests(): void
    {
        foreach ([[], ['data' => ['refresh_token' => []]], ['data' => ['refresh_token' => '']],
            new RuntimeException('secret')] as $generate) {
            $this->injectTokenCache(null);
            self::assertSame([], $this->client([$generate])->fetchDepartments());
        }
        $this->injectTokenCache(null);
        self::assertSame([], $this->client([['data' => ['refresh_token' => 'refresh']],
            ['data' => ['access_token' => []]]])->fetchDepartments());
        self::assertSame([], $this->responses);
    }

    public function testBadHttpResponsesAndTransportFailuresReturnEmpty(): void
    {
        foreach ([['status' => 302, 'body' => 'secret'], ['status' => 503, 'body' => 'secret'],
            ['status' => 200, 'body' => '{secret'], ['status' => '200', 'body' => '{}'],
            ['status' => 200, 'body' => []], null] as $response) {
            $client = new HttpMisClient('https://mis.example.test', 'key', 5, static fn () => $response);
            self::assertSame([], $client->fetchDepartments());
        }
        self::assertSame([], $this->client([new RuntimeException('secret')])->fetchDepartments());
    }
}
