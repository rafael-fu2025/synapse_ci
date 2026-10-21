<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Mis\MisProfileMapper;
use App\Integrations\Mis\MisUnavailableException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class MisProfileMapperTest extends TestCase
{
    private MisProfileMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new MisProfileMapper();
    }

    public function testMapStudentMatchesLoginContractFixture(): void
    {
        // Synthetic contract fixture: an actual campus payload is not available.
        $contractFixture = [
            'data' => [
                'student_id'  => '20230001',
                'last_name'   => 'DELA CRUZ',
                'first_name'  => 'JUAN',
                'middle_name' => 'REYES ',
                'program'     => 'BSIT',
                'level'       => '3',
                'department'  => 'CCS',
            ],
            'access_token'  => 'mock-token',
            'refresh_token' => 'mock-refresh',
            'expires_at'    => '2026-09-08 11:16:02',
        ];

        $mapped = $this->mapper->mapStudent($contractFixture);

        $this->assertSame('20230001', $mapped['mis_id']);
        $this->assertSame('JUAN', $mapped['first_name']);
        $this->assertSame('REYES', $mapped['middle_name'], 'Trailing whitespace in middle_name must be trimmed.');
        $this->assertSame('DELA CRUZ', $mapped['last_name']);
        $this->assertSame('BSIT', $mapped['program']);
        $this->assertSame(3, $mapped['year_level'], 'Level string "3" must parse to integer 3.');
        $this->assertSame('CCS', $mapped['department']);
        $this->assertNotEmpty($mapped['raw_hash']);
    }

    public function testMapStudentFallbackKeys(): void
    {
        $payload = [
            'id'         => '20259999',
            'firstName'  => 'Ana',
            'lastName'   => 'Lim',
            'course'     => 'BSN',
            'year_level' => 4,
            'block'      => 'Block A',
        ];

        $mapped = $this->mapper->mapStudent($payload);

        $this->assertSame('20259999', $mapped['mis_id']);
        $this->assertSame('Ana', $mapped['first_name']);
        $this->assertNull($mapped['middle_name']);
        $this->assertSame('Lim', $mapped['last_name']);
        $this->assertSame('BSN', $mapped['program']);
        $this->assertSame(4, $mapped['year_level']);
        $this->assertSame('Block A', $mapped['section']);
    }

    /** Synthetic contracts, not evidence of a campus MIS schema. */
    public function testStudentEnrollmentTypeAcceptsOnlyCanonicalExplicitValues(): void
    {
        foreach (['student_type', 'enrollment_type'] as $key) {
            foreach (['new', 'continuing', 'transferee'] as $type) {
                foreach ([$type, strtoupper($type), ' ' . ucfirst($type) . ' '] as $value) {
                    $mapped = $this->mapper->mapStudent(['student_id' => '001', $key => $value]);
                    $this->assertSame($type, $mapped['enrollment_type']);
                    $this->assertTrue($mapped['enrollment_type_provided']);
                    $this->assertFalse($mapped['is_graduating_provided']);
                    $this->assertNull($mapped['is_graduating']);
                }
            }
            foreach ([null, '', ' ', 'old', 'freshman', 'transfer', 'new student', 'regular', 'graduating', 1, true, [], new \stdClass()] as $value) {
                $mapped = $this->mapper->mapStudent(['student_id' => '001', $key => $value]);
                $this->assertNull($mapped['enrollment_type']);
                $this->assertTrue($mapped['enrollment_type_provided']);
            }
        }
        $this->assertSame('transferee', $this->mapper->mapStudent([
            'student_id' => '001', 'student_type' => 'TRANSFEREE', 'enrollment_type' => 'transferee',
        ])['enrollment_type']);
        foreach (['continuing', 'unknown', true, null] as $conflict) {
            foreach ([
                ['student_type' => 'new', 'enrollment_type' => $conflict],
                ['student_type' => $conflict, 'enrollment_type' => 'new'],
            ] as $fields) {
                $mapped = $this->mapper->mapStudent(['student_id' => '001'] + $fields);
                $this->assertNull($mapped['enrollment_type']);
                $this->assertTrue($mapped['enrollment_type_provided']);
            }
        }
    }

    /** @dataProvider teachingValues */
    public function testStudentGraduatingUsesStrictExplicitBoolean(mixed $value, ?bool $expected): void
    {
        $mapped = $this->mapper->mapStudent(['student_id' => '001', 'is_graduating' => $value]);
        $this->assertSame($expected, $mapped['is_graduating']);
        $this->assertTrue($mapped['is_graduating_provided']);
        $this->assertFalse($mapped['enrollment_type_provided']);
    }

    public function testStudentAudienceIsNotInferredFromAmbiguousFields(): void
    {
        $expected = $this->mapper->mapStudent(['student_id' => '20260001', 'year_level' => 4]);
        $this->assertNull($expected['enrollment_type']);
        $this->assertNull($expected['is_graduating']);
        $this->assertSame($expected, $this->mapper->mapStudent([
            'student_id' => '20260001', 'year_level' => 4,
            'status' => 'new', 'student_status' => 'graduating', 'enrollment_status' => 'continuing',
            'type' => 'transferee', 'isGraduating' => true, 'graduating' => true,
            'created_at' => '2026-10-17 12:00:00',
        ]));
    }

    public function testStudentAudiencePresenceComesOnlyFromExplicitSourceFields(): void
    {
        $omitted = $this->mapper->mapStudent(['student_id' => '001']);
        $this->assertFalse($omitted['enrollment_type_provided']);
        $this->assertFalse($omitted['is_graduating_provided']);
        $this->assertSame($omitted, $this->mapper->mapStudent([
            'student_id' => '001', 'enrollment_type_provided' => true, 'is_graduating_provided' => true,
        ]));
        foreach (['student_type', 'enrollment_type', 'is_graduating'] as $field) {
            $cleared = $this->mapper->mapStudent(['student_id' => '001', $field => null]);
            $unknown = $this->mapper->mapStudent(['student_id' => '001', $field => 'unknown']);
            $this->assertSame($cleared, $unknown);
            $this->assertNotSame($omitted['raw_hash'], $cleared['raw_hash']);
            $this->assertSame($cleared, $this->mapper->mapStudent([
                'data' => ['student_id' => '001', $field => null],
                'access_token' => 'secret', 'refresh_token' => 'secret',
            ]));
        }
    }

    public function testStudentAudienceHashIncludesCanonicalValuesButNotSecrets(): void
    {
        $base = ['student_id' => '001'];
        $unknown = $this->mapper->mapStudent($base);
        $enrolled = $this->mapper->mapStudent($base + ['student_type' => 'new']);
        $graduating = $this->mapper->mapStudent($base + ['student_type' => 'new', 'is_graduating' => true]);
        $notGraduating = $this->mapper->mapStudent($base + ['student_type' => 'new', 'is_graduating' => false]);
        $this->assertCount(4, array_unique(array_column([$unknown, $enrolled, $graduating, $notGraduating], 'raw_hash')));
        $this->assertSame($notGraduating, $this->mapper->mapStudent($base + [
            'enrollment_type' => ' NEW ', 'is_graduating' => 'NO',
            'password' => 'secret', 'access_token' => 'secret', 'refresh_token' => 'secret',
        ]));
    }

    public function testMapStudentRejectsEmptyPayload(): void
    {
        $this->expectException(MisUnavailableException::class);
        $this->mapper->mapStudent([]);
    }

    public function testMapEmployeeMatchesContractFixture(): void
    {
        $payload = [
            'data' => [
                'employee_id'       => '20269001',
                'first_name'        => 'ROBERTO',
                'middle_name'       => 'M.',
                'last_name'         => 'HERNANDEZ',
                'department'        => 'College of Nursing',
                'position'          => 'Associate Professor',
                'employment_status' => 'active',
                'is_teaching'       => 1,
            ],
        ];

        $mapped = $this->mapper->mapEmployee($payload);

        $this->assertSame('20269001', $mapped['mis_id']);
        $this->assertSame('ROBERTO', $mapped['first_name']);
        $this->assertSame('M.', $mapped['middle_name']);
        $this->assertSame('HERNANDEZ', $mapped['last_name']);
        $this->assertSame('College of Nursing', $mapped['department']);
        $this->assertSame('Associate Professor', $mapped['position']);
        $this->assertSame('active', $mapped['employment_status']);
        $this->assertTrue($mapped['is_teaching']);
    }

    public function testMapEmployeeCoercesBooleansAndRejectsUnknownStatus(): void
    {
        $payload = [
            'employee_id'       => '20269002',
            'first_name'        => 'Elena',
            'last_name'         => 'Bautista',
            'is_teaching'       => 'yes',
            'employment_status' => 'INVALID_STATUS',
        ];

        $mapped = $this->mapper->mapEmployee($payload);

        $this->assertTrue($mapped['is_teaching']);
        $this->assertNull($mapped['employment_status']);
    }

    public function testBareAndLoginEnvelopesMapIdentically(): void
    {
        foreach (['mapStudent' => 'student_id', 'mapEmployee' => 'employee_id'] as $method => $idKey) {
            $profile = [$idKey => ' 000123 ', 'first_name' => ' Ana ', 'last_name' => ' Lim '];
            $expected = $this->mapper->{$method}($profile);
            $this->assertSame('000123', $expected['mis_id']);
            $this->assertSame('Ana', $expected['first_name']);
            $this->assertSame('Lim', $expected['last_name']);
            $this->assertSame($expected, $this->mapper->{$method}([
                'data' => $profile, 'access_token' => 'secret',
            ]));
            $this->assertSame($expected, $this->mapper->{$method}([
                'data' => ['data' => $profile, 'access_token' => 'secret'],
                'refresh_token' => 'another-secret',
            ]));
        }
    }

    /** @dataProvider malformedProfiles */
    public function testMalformedOrMissingIdentifierRaisesUnavailable(array $payload): void
    {
        foreach (['mapStudent', 'mapEmployee'] as $method) {
            try {
                $this->mapper->{$method}($payload);
                $this->fail('Expected an unavailable error for ' . $method);
            } catch (MisUnavailableException $exception) {
                $this->assertSame((new MisUnavailableException())->getMessage(), $exception->getMessage());
            }
        }
    }

    public static function malformedProfiles(): iterable
    {
        yield 'empty' => [[]];
        yield 'list' => [[['student_id' => '001', 'employee_id' => '002']]];
        yield 'scalar list' => [['001']];
        yield 'mixed keys' => [['student_id' => '001', 'employee_id' => '002', 0 => 'bad']];
        yield 'null data with outer id' => [['id' => '001', 'data' => null, 'access_token' => 'secret']];
        yield 'string data' => [['data' => 'secret']];
        yield 'object data' => [['data' => (object) ['id' => '001']]];
        yield 'empty data' => [['data' => []]];
        yield 'list data' => [['data' => [['id' => '001']]]];
        yield 'nested list' => [['data' => ['data' => [['id' => '001']]]]];
        yield 'nested scalar' => [['data' => ['data' => false]]];
        yield 'too deep' => [['data' => ['data' => ['data' => ['id' => '001']]]]];
        yield 'no identifier' => [['first_name' => 'Ana']];
        yield 'token envelope' => [['access_token' => 'secret', 'refresh_token' => 'secret']];
        yield 'nested token envelope' => [['id' => 'outer-id', 'data' => ['access_token' => 'secret']]];
        yield 'blank identifiers' => [['student_id' => ' ', 'employee_id' => '', 'id' => null]];
        yield 'array identifiers' => [['student_id' => ['001'], 'employee_id' => ['002']]];
        yield 'invalid identifier types' => [['student_id' => true, 'employee_id' => 1.5, 'id' => false]];
    }

    public function testOptionalValuesRemainNullForPartialUpdates(): void
    {
        foreach (['mapStudent' => 'student_id', 'mapEmployee' => 'employee_id'] as $method => $idKey) {
            $expected = $this->mapper->{$method}([$idKey => '001']);
            foreach ($expected as $key => $value) {
                if (in_array($key, ['enrollment_type_provided', 'is_graduating_provided'], true)) {
                    $this->assertFalse($value, $method . ': ' . $key);
                } elseif (! in_array($key, ['mis_id', 'raw_hash'], true)) {
                    $this->assertNull($value, $method . ': ' . $key);
                }
            }
            $this->assertSame($expected, $this->mapper->{$method}([
                $idKey => '001',
                'first_name' => ' ', 'middle_name' => [], 'last_name' => null,
                'program' => [], 'level' => 'unknown', 'section' => false,
                'department' => '', 'position' => [],
                'employment_status' => 'unknown', 'is_teaching' => 'unknown',
            ]));
        }
    }

    public function testEmployeeAliasesAndStatusNormalization(): void
    {
        foreach (['active', 'inactive', 'on_leave'] as $status) {
            $mapped = $this->mapper->mapEmployee([
                'employeeId' => '0007', 'fname' => 'Ana', 'middlename' => 'M', 'lname' => 'Lim',
                'departmentName' => ' Nursing ', 'job_title' => ' Professor ',
                'employmentStatus' => ' ' . strtoupper($status) . ' ', 'isTeaching' => 'n',
            ]);
            $this->assertSame('0007', $mapped['mis_id']);
            $this->assertSame('Nursing', $mapped['department']);
            $this->assertSame('Professor', $mapped['position']);
            $this->assertSame($status, $mapped['employment_status']);
            $this->assertFalse($mapped['is_teaching']);
        }
    }

    /** @dataProvider teachingValues */
    public function testStrictTeachingBoolean(mixed $value, ?bool $expected): void
    {
        $mapped = $this->mapper->mapEmployee(['employee_id' => '001', 'is_teaching' => $value]);
        $this->assertSame($expected, $mapped['is_teaching']);
    }

    public static function teachingValues(): iterable
    {
        foreach ([true, 1, 'true', 'TRUE', ' yes ', 'y', '1'] as $value) {
            yield [$value, true];
        }
        foreach ([false, 0, 'false', 'FALSE', ' no ', 'n', '0'] as $value) {
            yield [$value, false];
        }
        foreach ([null, '', ' ', 'unknown', 2, -1, 1.0, 0.0, 0.5, '2', '-1', '1.0', '01', '1e0', [], new \stdClass()] as $value) {
            yield [$value, null];
        }
    }

    public function testOfficialIdentifierAliasesPreserveStrings(): void
    {
        foreach ([
            'mapStudent' => ['student_id', 'id', 'studentId', 'student_number'],
            'mapEmployee' => ['employee_id', 'id', 'employeeId', 'employee_number'],
        ] as $method => $aliases) {
            foreach ($aliases as $alias) {
                $this->assertSame('00042', $this->mapper->{$method}([$alias => '00042'])['mis_id']);
                $this->assertSame('42', $this->mapper->{$method}([$alias => 42])['mis_id']);
            }
        }
    }

    public function testHashAndReturnContainOnlyCanonicalMappedFields(): void
    {
        foreach (['mapStudent' => 'student_id', 'mapEmployee' => 'employee_id'] as $method => $idKey) {
            $profile = [$idKey => '001', 'first_name' => 'Ana', 'last_name' => 'Lim'];
            $expected = $this->mapper->{$method}($profile);
            $withSecrets = $profile + [
                'password' => 'password-secret', 'access_token' => 'access-secret',
                'refresh_token' => 'refresh-secret', 'unmapped' => 'ignored',
            ];
            $this->assertSame($expected, $this->mapper->{$method}($withSecrets));
            $withSecrets['password'] = 'changed';
            $withSecrets['access_token'] = 'changed';
            $this->assertSame($expected, $this->mapper->{$method}(['data' => ['data' => $withSecrets]]));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $expected['raw_hash']);
            $this->assertSame(
                array_keys($expected),
                $method === 'mapStudent'
                    ? ['mis_id', 'first_name', 'middle_name', 'last_name', 'program', 'year_level', 'section', 'department', 'enrollment_type', 'is_graduating', 'enrollment_type_provided', 'is_graduating_provided', 'raw_hash']
                    : ['mis_id', 'first_name', 'middle_name', 'last_name', 'department', 'position', 'employment_status', 'is_teaching', 'raw_hash'],
            );
            $this->assertNotSame($expected['raw_hash'], $this->mapper->{$method}([$idKey => '002'])['raw_hash']);
            $this->assertNotSame(
                $this->mapper->{$method}([$idKey => '001', 'first_name' => 'AB', 'last_name' => 'C'])['raw_hash'],
                $this->mapper->{$method}([$idKey => '001', 'first_name' => 'A', 'last_name' => 'BC'])['raw_hash'],
            );
            $this->assertNotSame(
                $this->mapper->{$method}([$idKey => '001', 'first_name' => 'Ana'])['raw_hash'],
                $this->mapper->{$method}([$idKey => '001', 'last_name' => 'Ana'])['raw_hash'],
            );
        }
    }
}
