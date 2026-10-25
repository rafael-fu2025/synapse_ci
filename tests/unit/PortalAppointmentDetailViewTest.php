<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

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
}
