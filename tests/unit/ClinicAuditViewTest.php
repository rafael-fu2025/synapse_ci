<?php

declare(strict_types=1);

namespace Tests\Unit {
    // Private helpers keep these template tests independent of CI boot and real sessions.
    function clinicAuditEsc($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    function clinicAuditUrl(string $path): string { return 'https://clinic.test' . $path; }
    function clinicAuditCsrf(): string { return '<input type="hidden" name="csrf" value="test">'; }
    function clinicAuditOld(string $field, mixed $default = '', bool $escape = true): mixed { return $default; }
    function clinicAuditSession(): object { return new class { public function get(string $key): array { return []; } }; }
    function clinicAuditView(string $name, array $data = [], array $options = []): string {
        return (new ClinicAuditViewRenderer())->render($name, $data);
    }
    use App\Services\Clinic\EncounterService;
    use PHPUnit\Framework\TestCase;

    final class ClinicAuditViewRenderer
    {
        public function extend(string $name): string { return ''; }
        public function section(string $name): string { return ''; }
        public function endSection(): string { return ''; }
        public function render(string $name, array $data): string
        {
            extract($data, EXTR_SKIP);
            ob_start();
            try {
                $template = file_get_contents(__DIR__ . '/../../app/Views/' . $name . '.php');
                $helpers = ['esc' => 'clinicAuditEsc', 'site_url' => 'clinicAuditUrl', 'csrf_field' => 'clinicAuditCsrf',
                    'old' => 'clinicAuditOld', 'session' => 'clinicAuditSession', 'view' => 'clinicAuditView'];
                foreach ($helpers as $original => $replacement) {
                    $template = preg_replace('/\\b' . $original . '\\(/', '\\\\Tests\\\\Unit\\\\' . $replacement . '(', $template);
                }
                eval('?>' . $template);
                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        }
    }

    final class ClinicAuditViewTest extends TestCase
    {
        private function package(string $status): array
        {
            return [
                'encounter' => [
                    'encounter' => ['id' => 7, 'status' => $status, 'disposition' => 'discharged',
                        'chief_complaint' => '<script>alert(1)</script>', 'assessment_notes' => 'Clinical observation',
                        'disposition_notes' => 'Rest advised', 'follow_up_date' => '2026-11-01'],
                    'patient' => ['id' => 42, 'kind' => 'student', 'first_name' => 'Test', 'last_name' => 'Patient', 'mis_id' => 'TEST'],
                    'vitals' => ['pulse_bpm' => 72, 'spo2_pct' => 0],
                    'treatment' => ['diagnosis' => 'Diagnosis retained', 'treatment_plan' => 'Treatment retained'],
                    'dispensations' => [['medicine_name' => 'Medicine retained', 'strength' => '500mg', 'quantity' => 2,
                        'unit_of_measure' => 'tablet', 'batch_number' => 'LOT', 'expiration_date' => '2027-01-01', 'instructions' => '<b>Directions</b>']],
                ],
                'canDispense' => true, 'availableMedicines' => [], 'dispenseKey' => 'test-key',
            ];
        }

        public function testClosedEncounterShowsRecordsWithoutAnyWriteForms(): void
        {
            foreach (['completed', 'referred', 'cancelled'] as $status) {
                $html = (new ClinicAuditViewRenderer())->render('clinic/encounters/stepper', $this->package($status));
                $this->assertStringNotContainsString('<form', $html);
                $this->assertStringNotContainsString('type="submit"', $html);
                foreach (['read-only', 'Clinical observation', 'Diagnosis retained', 'Treatment retained', 'Medicine retained', 'Rest advised', '2026-11-01', '&lt;script&gt;', '&lt;b&gt;Directions&lt;/b&gt;'] as $text) {
                    $this->assertStringContainsString($text, $html);
                }
                $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            }
        }

        public function testOpenEncounterFormsMatchServerBoundsAndLengths(): void
        {
            $html = (new ClinicAuditViewRenderer())->render('clinic/encounters/stepper', $this->package('in_progress'));
            $this->assertSame(4, substr_count($html, '<form'));
            foreach (EncounterService::VITAL_BOUNDS as $field => [$min, $max]) {
                $this->assertMatchesRegularExpression('/name="' . $field . '"[^>]*min="' . preg_quote((string) $min, '/') . '"[^>]*max="' . preg_quote((string) $max, '/') . '"/', $html);
            }
            foreach (EncounterService::TEXT_LIMITS as $field => $limit) {
                $this->assertStringContainsString('name="' . $field . '" maxlength="' . $limit . '"', $html);
            }
            foreach (EncounterService::DISPOSITIONS as $disposition) {
                $this->assertStringContainsString('value="' . $disposition . '"', $html);
            }
        }

        public function testDashboardInventoryPendingDoesNotHideOtherPanels(): void
        {
            $html = (new ClinicAuditViewRenderer())->render('clinic/dashboard', [
                'user' => [], 'inventoryInstalled' => false, 'medicinesCount' => null,
                'queueWaiting' => 3, 'queueCalled' => 2, 'queueCompleted' => 4,
                'encountersCount' => 12, 'appointmentsToday' => 6, 'recentEncounters' => [],
                'triageCounts' => ['emergency' => 0, 'urgent' => 1, 'routine' => 4],
                'chartDays' => ['Mon'], 'chartSeries' => [12],
            ]);
            $this->assertStringContainsString('Medicine inventory unavailable.', $html);
            $this->assertStringContainsString('Administrator must complete', $html);
            $this->assertStringContainsString('3 waiting, 2 called', $html);
            $this->assertStringContainsString('Appointments Today', $html);
            $this->assertStringContainsString('Recent Clinical Encounters', $html);
            $this->assertStringNotContainsString('FEFO inventory active', $html);
        }

        public function testPatientChartResumesOpenVisitAndEscapesTreatmentHistory(): void
        {
            $package = $this->package('in_progress')['encounter'];
            $chart = [
                'person' => $package['patient'], 'encounters' => [$package['encounter'] + ['started_at' => '2026-10-19 10:00:00', 'attending_name' => 'Nurse']],
                'treatment_history' => [['encounter_id' => 7, 'diagnosis' => '<script>diagnosis</script>', 'treatment_plan' => 'Plan retained', 'services_rendered' => 'Care retained']],
                'dispensation_history' => [$package['dispensations'][0] + ['encounter_id' => 7]],
            ];
            $html = (new ClinicAuditViewRenderer())->render('clinic/patients/chart', ['chart' => $chart, 'canStartVisit' => true]);
            $this->assertStringContainsString('Resume Open Visit', $html);
            $this->assertStringNotContainsString('start-direct', $html);
            $this->assertStringContainsString('&lt;script&gt;diagnosis&lt;/script&gt;', $html);
            $this->assertStringContainsString('Plan retained', $html);
            $this->assertStringContainsString('Medicine retained', $html);
        }
    }
}
