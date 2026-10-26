<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PortalAppointmentDetailViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['form', 'url']);
        service('request')->setGlobal('get', ['fragment' => '1']);
    }

    public function testRequestUtcAndConfirmationManilaTimesRenderAsManilaLocalTime(): void
    {
        $html = view('portal/appointment_detail', [
            'title' => 'Appointment details',
            'cancelUrl' => '/',
            'appointment' => [
                'id' => 13,
                'unit' => 'clinic',
                'status' => 'completed',
                'type' => 'referral_based',
                'reason' => '',
                'starts_at' => '2026-10-22 11:00:00',
                'ends_at' => '2026-10-22 11:15:00',
                'created_at' => '2026-10-22 01:57:00',
                'confirmed_at' => '2026-10-22 09:57:00',
                'provider_name' => 'Fixture Provider',
            ],
        ]);

        $this->assertStringContainsString('Oct 22, 2026 · 9:57 AM', $html);
        $this->assertStringNotContainsString('Oct 22, 2026 · 1:57 AM', $html);
        $this->assertStringContainsString('Requested on', $html);
        $this->assertStringContainsString('Confirmed on', $html);
    }

    public static function cancelledReasonCases(): iterable
    {
        foreach (['clinic', 'guidance'] as $unit) {
            yield $unit . ' distinct reasons' => [$unit, 'Follow-up appointment', 'Unable to attend', 'Follow-up appointment', 'Unable to attend'];
            yield $unit . ' cancellation only' => [$unit, '', 'Schedule conflict', 'Not provided', 'Schedule conflict'];
            yield $unit . ' legacy missing reason' => [$unit, 'Original request', null, 'Original request', 'Not recorded'];
            yield $unit . ' legacy blank reason' => [$unit, 'Original request', '   ', 'Original request', 'Not recorded'];
            yield $unit . ' zero is valid text' => [$unit, '0', '0', '0', '0'];
            yield $unit . ' escaped text' => [$unit, '<script>request()</script>', '<img src=x onerror=alert(1)>', '&lt;script&gt;request()&lt;/script&gt;', '&lt;img src=x onerror=alert(1)&gt;'];
        }
    }

    #[DataProvider('cancelledReasonCases')]
    public function testCancelledDetailsDisplayBothReasonsWithoutConflatingThem(string $unit, string $reason, ?string $cancellationReason, string $expectedReason, string $expectedCancellation): void
    {
        $html = $this->detail(['unit' => $unit, 'status' => 'cancelled', 'reason' => $reason, 'cancellation_reason' => $cancellationReason]);
        $this->assertMatchesRegularExpression('/<dt[^>]*>Appointment reason<\/dt>\s*<dd[^>]*>' . preg_quote($expectedReason, '/') . '<\/dd>/', $html);
        $this->assertMatchesRegularExpression('/<dt[^>]*>Cancellation reason<\/dt>\s*<dd[^>]*>' . preg_quote($expectedCancellation, '/') . '<\/dd>/', $html);
        $this->assertStringNotContainsString('<script>request()</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    public function testActiveDetailsDoNotShowStaleCancellationMetadata(): void
    {
        foreach (['requested', 'scheduled', 'confirmed', 'checked_in', 'completed', 'no_show'] as $status) {
            \Config\Services::resetSingle('renderer');
            $html = $this->detail(['status' => $status, 'reason' => 'Appointment request reason', 'cancellation_reason' => 'Stale cancellation metadata']);
            $this->assertStringContainsString('Appointment request reason', $html);
            $this->assertStringNotContainsString('>Cancellation reason<', $html);
            $this->assertStringNotContainsString('Stale cancellation metadata', $html);
        }
    }

    public function testReasonLineBreaksArePreservedAfterEscaping(): void
    {
        $html = $this->detail(['status' => 'cancelled', 'reason' => "Line one\nLine two", 'cancellation_reason' => "First line\n<script>literal text</script>"]);
        $this->assertStringContainsString("Line one<br />\nLine two", $html);
        $this->assertStringContainsString("First line<br />\n&lt;script&gt;literal text&lt;/script&gt;", $html);
    }

    private function detail(array $changes): string
    {
        return view('portal/appointment_detail', [
            'title' => 'Appointment details', 'cancelUrl' => '/portal',
            'appointment' => $changes + [
                'id' => 13, 'unit' => 'clinic', 'status' => 'requested', 'type' => 'routine',
                'reason' => '', 'starts_at' => '2026-10-22 11:00:00', 'ends_at' => '2026-10-22 11:15:00',
                'created_at' => null, 'confirmed_at' => null, 'provider_name' => null,
            ],
        ]);
    }
}
