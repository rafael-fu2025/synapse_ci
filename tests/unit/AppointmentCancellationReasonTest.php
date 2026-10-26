<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\AppointmentService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/** Invalid reasons must fail before any database access or transaction. */
final class AppointmentCancellationReasonTest extends CIUnitTestCase
{
    public static function invalidReasons(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'ASCII whitespace' => [" \t\r\n "];
        yield 'Unicode separators' => ["\u{00A0}\u{2003}\u{2028}\u{2029}\u{3000}"];
        yield 'zero width space' => ["\u{200B}"];
        yield 'BOM' => ["\u{FEFF}"];
        yield '256 ASCII characters' => [str_repeat('a', 256)];
        yield '256 Unicode characters' => [str_repeat('界', 256)];
        yield 'invalid UTF-8' => ["reason\xC3\x28"];
        yield 'NUL at edge' => ["reason\0"];
        yield 'embedded control' => ["rea\x01son"];
        yield 'DEL' => ["reason\x7F"];
        yield 'C1 control' => ["reason\u{0085}"];
        yield 'bidi control' => ["reason\u{202E}"];
    }

    #[DataProvider('invalidReasons')]
    public function testInvalidReasonIsRejectedBeforeDatabaseAccess(string $reason): void
    {
        $service = (new ReflectionClass(AppointmentService::class))->newInstanceWithoutConstructor();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cancellation reason must');
        $service->cancelAppointment(1, $reason);
    }
}
