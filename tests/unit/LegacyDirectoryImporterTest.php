<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Directory\LegacyDirectoryImporter;
use PHPUnit\Framework\TestCase;

/** @internal Database-free tests: never instantiate a database connection. */
final class LegacyDirectoryImporterTest extends TestCase
{
    private function student(array $overrides = []): array
    {
        return array_replace([
            'id' => '999', 'tenant_id' => '1', 'kind' => 'student',
            'student_number' => ' 0000123 ', 'first_name' => ' Ada ', 'last_name' => ' Lovelace ',
            'middle_name' => null, 'archived_at' => null, 'deleted_at' => null, 'date_of_birth' => '2000-02-29',
            'gender' => 'female', 'course' => 'BSIT', 'section' => 'A', 'year_level' => '3',
            'address' => 'Local address',
        ], $overrides);
    }

    private function map(array $row): array
    {
        return LegacyDirectoryImporter::mapRow($row, 1, '2026-10-16');
    }

    public function testGeneratedIdIsCapturedBeforeWarningQueryResetsIt(): void
    {
        $source = $this->createMock(\CodeIgniter\Database\BaseConnection::class);
        $target = $this->createMock(\CodeIgniter\Database\BaseConnection::class);
        $builder = $this->createMock(\CodeIgniter\Database\BaseBuilder::class);
        $result = $this->createMock(\CodeIgniter\Database\BaseResult::class);
        $id = 123;
        $target->method('table')->with('people')->willReturn($builder);
        $builder->method('insert')->willReturn(true);
        $target->method('affectedRows')->willReturn(1);
        $target->method('insertID')->willReturnCallback(static function () use (&$id): int { return $id; });
        $target->method('query')->with('SHOW WARNINGS', [])->willReturnCallback(static function () use (&$id, $result) {
            $id = 0;
            return $result;
        });
        $result->method('getResultArray')->willReturn([]);
        $importer = new LegacyDirectoryImporter($source, $target);
        $inserted = (new \ReflectionMethod(LegacyDirectoryImporter::class, 'insert'))->invoke($importer, 'people', ['first_name' => 'Fixture']);
        self::assertSame(123, $inserted);
        self::assertSame(0, $id);
    }

    public function testStudentMappingPreservesLeadingZeroAndUsesOnlyAllowedFields(): void
    {
        $row = $this->student([
            'email' => 'private@example.test', 'phone' => 'secret', 'blood_type' => 'O',
            'emergency_contact_name' => 'Secret', 'emergency_contact_phone' => 'secret',
                        'emergency_contact_relationship' => 'Secret', 'password' => 'secret', 'qr' => 'secret',
                        'auth_flags' => 'secret', 'roles' => ['admin'], 'user_id' => 42,
                        'allergies' => 'Secret', 'chronic_conditions' => 'Secret',
                        'clinical_records' => ['secret'], 'guidance_records' => ['secret'],
            'mis_synced_at' => '2020-01-01', 'mis_payload_hash' => 'secret',
            'department' => ' Academic department ', 'is_teaching' => 1,
                        'position' => 'Employee-only position', 'employment_status' => 'active',
        ]);
        $mapped = $this->map($row);
        self::assertNull($mapped['reason']);
        self::assertSame([
            'tenant_id' => 1, 'kind' => 'student', 'mis_id' => '0000123',
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => null, 'phone' => null,
            'middle_name' => null, 'address' => 'Local address', 'gender' => 'female',
            'date_of_birth' => '2000-02-29',
        ], $mapped['person']);
        self::assertSame([
                    'tenant_id' => 1, 'program' => 'BSIT', 'section' => 'A',
                    'department' => 'Academic department', 'year_level' => 3,
                ], $mapped['profile']);
        self::assertSame([], $mapped['warnings']);
    }

    public function testRestrictedFieldsCannotChangeEitherDirectoryMapping(): void
    {
        $restricted = [
            'id' => 12345, 'email' => 'private@example.test', 'phone' => 'secret',
            'blood_type' => 'O', 'emergency_contact_name' => 'Secret',
            'emergency_contact_phone' => 'secret', 'emergency_contact_relationship' => 'Secret',
            'password' => 'secret', 'qr' => 'secret', 'auth_flags' => ['admin'],
            'roles' => ['admin'], 'user_id' => 42, 'allergies' => 'Secret',
            'chronic_conditions' => 'Secret', 'clinical_records' => ['secret'],
            'guidance_records' => ['secret'], 'mis_payload_hash' => 'secret',
            'mis_synced_at' => '2026-10-16 00:00:00',
        ];
        foreach (['student', 'employee'] as $kind) {
            $row = $this->student(['kind' => $kind, 'employee_number' => 'E01']);
            self::assertSame($this->map($row), $this->map(array_replace($row, $restricted)));
        }
    }

    public function testNullEmploymentStatusIsUnknownAndNullTeachingDefaultsToZero(): void
    {
        $mapped = $this->map($this->student([
            'kind' => 'employee', 'employee_number' => '000007',
            'employment_status' => null, 'is_teaching' => null,
            'department' => 'College', 'position' => 'Staff',
        ]));
        self::assertNull($mapped['reason']);
        self::assertSame('000007', $mapped['person']['mis_id']);
        self::assertSame([
            'tenant_id' => 1, 'department' => 'College', 'position' => 'Staff',
            'employment_status' => 'unknown', 'is_teaching' => 0,
        ], $mapped['profile']);
        self::assertSame(['employment_status_unknown', 'is_teaching_defaulted'], $mapped['warnings']);
    }

    public function testValidEmploymentStatusesAreNotInferredOrRewritten(): void
    {
        foreach (['active', 'inactive', 'on_leave'] as $status) {
            $mapped = $this->map($this->student([
                'kind' => 'employee', 'employee_number' => 'E01',
                'employment_status' => $status, 'is_teaching' => '1',
            ]));
            self::assertSame($status, $mapped['profile']['employment_status']);
            self::assertSame(1, $mapped['profile']['is_teaching']);
        }
        foreach (['', 'unknown', 'ACTIVE', 'terminated', 1] as $status) {
            self::assertSame('invalidfield', $this->map($this->student([
                'kind' => 'employee', 'employee_number' => 'E01', 'employment_status' => $status,
            ]))['reason']);
        }
    }

    public function testMalformedTeachingFlagsAreRejected(): void
    {
        foreach (['yes', 'false', 2, -1, 1.0] as $flag) {
            self::assertSame('invalidfield', $this->map($this->student([
                'kind' => 'employee', 'employee_number' => 'E01', 'is_teaching' => $flag,
            ]))['reason']);
        }
    }

    public function testInvalidDatesAreNullWithWarningsWithoutNormalization(): void
    {
        foreach (['0000-00-00', '2025-02-29', '2000-02-30', '2026-10-17', '2020-2-01', '0999-01-01', '', "2000-01-01\0", 123] as $dob) {
            $mapped = $this->map($this->student(['date_of_birth' => $dob]));
            self::assertNull($mapped['person']['date_of_birth']);
            self::assertContains('date_of_birth_invalid', $mapped['warnings']);
        }
        foreach (['2000-02-29', '2026-10-16', null] as $dob) {
            $mapped = $this->map($this->student(['date_of_birth' => $dob]));
            self::assertSame($dob, $mapped['person']['date_of_birth']);
            self::assertNotContains('date_of_birth_invalid', $mapped['warnings']);
        }
    }

    public function testInvalidGenderIsNotInferred(): void
    {
        foreach (['M', 'Female', '', 1] as $gender) {
            $mapped = $this->map($this->student(['gender' => $gender]));
            self::assertNull($mapped['person']['gender']);
            self::assertContains('gender_invalid', $mapped['warnings']);
        }
    }

    public function testUnsupportedAndArchivedRowsNeverProducePeople(): void
    {
        foreach ([null, 'contractor', 'alumni', 'guest', 'Student'] as $kind) {
            $mapped = $this->map($this->student(['kind' => $kind]));
            self::assertSame('unsupportedkind', $mapped['reason']);
            self::assertNull($mapped['person']);
            self::assertNull($mapped['profile']);
        }
        foreach (['archived_at', 'deleted_at'] as $field) {
            self::assertSame('archived', $this->map($this->student([$field => '2020-01-01']))['reason']);
        }
    }

    public function testDeletedRowsNeverProducePeopleOrProfilesEvenWithDuplicateMetadata(): void
    {
        foreach (['student', 'employee'] as $kind) {
            $mapped = $this->map($this->student([
                'kind' => $kind, 'employee_number' => 'E01',
                'deleted_at' => '2026-01-01 00:00:00', 'duplicate_count' => 2,
            ]));
            self::assertSame('archived', $mapped['reason']);
            self::assertNull($mapped['person']);
            self::assertNull($mapped['profile']);
            self::assertSame([], $mapped['warnings']);
        }
    }

    public function testStudentDepartmentIsOptionalAndTrimmed(): void
    {
        foreach ([null, '', '   ', ' College of Computing '] as $department) {
            $mapped = $this->map($this->student(['department' => $department]));
            self::assertNull($mapped['reason']);
            self::assertSame($department === null || trim($department) === '' ? null : trim($department), $mapped['profile']['department']);
        }
    }

    public function testMissingOfficialIdNeverFallsBackToLegacyPkOrNames(): void
    {
        foreach ([null, '', '   '] as $id) {
            self::assertSame('sourcemissingid', $this->map($this->student(['student_number' => $id]))['reason']);
        }
        foreach ([123, 1.2, true, [], str_repeat('1', 51), "12\t3"] as $id) {
            self::assertSame('invalidfield', $this->map($this->student(['student_number' => $id]))['reason']);
        }
        self::assertSame('sourcemissingid', $this->map($this->student(['kind' => 'employee']))['reason']);
    }

    public function testNamesAreRequiredAndStrictlyBounded(): void
    {
        foreach (['first_name', 'last_name'] as $field) {
            foreach ([null, '', '  '] as $value) {
                self::assertSame('missingnames', $this->map($this->student([$field => $value]))['reason']);
            }
            foreach ([123, [], str_repeat('x', 101), "bad\0name", "\xFF"] as $value) {
                self::assertSame('invalidfield', $this->map($this->student([$field => $value]))['reason']);
            }
            self::assertNull($this->map($this->student([$field => str_repeat('é', 100)]))['reason']);
        }
    }

    public function testOptionalStringsRejectOverlengthOrNonstringRatherThanTruncate(): void
    {
        foreach (['middle_name' => 100, 'course' => 100, 'section' => 50, 'department' => 100] as $field => $limit) {
            self::assertNull($this->map($this->student([$field => str_repeat('é', $limit)]))['reason']);
            foreach ([str_repeat('x', $limit + 1), 100, false, []] as $value) {
                self::assertSame('invalidfield', $this->map($this->student([$field => $value]))['reason']);
            }
        }
        foreach (['department', 'position'] as $field) {
            self::assertSame('invalidfield', $this->map($this->student([
                'kind' => 'employee', 'employee_number' => 'E01', $field => str_repeat('x', 101),
            ]))['reason']);
        }
    }

    public function testAddressUsesTextByteLimitAndIsNotTruncated(): void
    {
        $address = str_repeat('x', 65535);
        self::assertSame($address, $this->map($this->student(['address' => $address]))['person']['address']);
        foreach ([$address . 'x', str_repeat('é', 32768), 123] as $value) {
            self::assertSame('invalidfield', $this->map($this->student(['address' => $value]))['reason']);
        }
    }

    public function testYearLevelUsesStrictUnsignedTinyintValidation(): void
    {
        foreach ([0, 1, 255, '0', '255', '003'] as $year) {
            self::assertSame((int) $year, $this->map($this->student(['year_level' => $year]))['profile']['year_level']);
        }
        foreach ([-1, 256, '256', '1.0', '2abc', '+1', ' 2 ', 1.0, true, [], '1e2'] as $year) {
            $mapped = $this->map($this->student(['year_level' => $year]));
            self::assertNull($mapped['profile']['year_level']);
            self::assertContains('year_level_invalid', $mapped['warnings']);
        }
    }

    public function testPureMappingIsDeterministicAndDoesNotMutateInput(): void
    {
        $row = $this->student();
        $copy = $row;
        self::assertSame($this->map($row), $this->map($row));
        self::assertSame($copy, $row);
    }
}
