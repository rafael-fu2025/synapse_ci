<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CarouselSlideModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

final class ModalFormPagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\AppointmentFixtureCleanup;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    protected function tearDown(): void
    {
        $this->cleanupAppointmentFixtures();
        parent::tearDown();
    }

    private function user(string $role = 'superadmin'): array
    {
        $id = (int) (new UserModel())->insert(['tenant_id' => 1, 'username' => uniqid('modal_tester_'),
            'role' => $role, 'status' => 'active']);
        $this->appointmentFixtureUsers[] = $id;
        return ['user' => ['id' => $id, 'name' => 'Modal Tester', 'role' => $role,
            'permissions' => [], 'logged_in' => true], 'tenant_id' => 1];
    }

    private function checkDialog(string $html, string $title, string $cancel, string $action): void
    {
        $dom = new \DOMDocument();
        $prior = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//*[@id="appFormModal"]')->length);
        $this->assertSame($title, trim($xpath->query('//*[@id="appFormModalTitle"]')->item(0)->textContent));
        $modal = $xpath->query('//*[@id="appFormModal"]')->item(0);
        $this->assertSame('static', $modal->getAttribute('data-bs-backdrop'));
        $this->assertSame('false', $modal->getAttribute('data-bs-keyboard'));
        $this->assertSame(1, $xpath->query('//*[@id="appFormModal"]//form')->length);
        $this->assertSame(site_url($action), $xpath->query('//*[@id="appFormModal"]//form')->item(0)->getAttribute('action'));
        $this->assertSame(2, $xpath->query('//*[@id="appFormModal"]//*[@data-app-modal-close]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="appFormModal"]//button[@aria-label="Close"]')->length);
        $this->assertSame(site_url($cancel), $xpath->query('//*[@id="appFormModal"]//a[@data-app-modal-close]')->item(0)->getAttribute('href'));
        $this->assertSame(1, $xpath->query('//*[@id="appFormModal"]//form//input[@name="' . csrf_token() . '"]')->length);
    }

    public function testDirectAndFragmentFormsUseCorrectDialogContracts(): void
    {
        foreach ([
            ['clinic/appointments/create', 'Book New Clinic Appointment', '/clinic/appointments', '/clinic/appointments/store'],
            ['guidance/appointments/create', 'Book New Guidance Appointment', '/guidance/appointments', '/guidance/appointments/store'],
            ['referrals/create', 'Issue Inter-Module Referral', '/referrals', '/referrals/store'],
            ['admin/carousel/create', 'Add Carousel Slide', '/admin/carousel', '/admin/carousel/store'],
        ] as [$route, $title, $cancel, $action]) {
            foreach (['', '?fragment=1'] as $query) {
                $response = $this->withSession($this->user())->get($route . $query);
                $response->assertStatus(200);
                $html = (string) $response->response()->getBody();
                $this->checkDialog($html, $title, $cancel, $action);
                if ($query === '') {
                    $this->assertStringContainsString('assets/js/form-modal.js', $html);
                    $this->assertStringContainsString('data-modal-direct="true"', $html);
                } else {
                    $this->assertStringNotContainsString('<html', $html);
                    $this->assertStringNotContainsString('<script', $html);
                    $this->assertStringNotContainsString('data-modal-direct', $html);
                }
            }
        }
    }

    public function testCarouselEditFragmentRetainsItsDataAndPermissions(): void
    {
        $model = new CarouselSlideModel();
        $id = $model->insert(['title' => 'Existing Slide', 'light_image' => 'assets/img/brand/fuel-bg.jpg',
            'fit_mode' => 'cover', 'is_active' => 1, 'sort_order' => 1]);
        try {
            $response = $this->withSession($this->user())->get('admin/carousel/' . $id . '/edit?fragment=1');
            $response->assertStatus(200);
            $this->checkDialog($response->getBody(), 'Edit Carousel Slide', '/admin/carousel', '/admin/carousel/' . $id . '/update');
            $this->assertStringContainsString('value="Existing Slide"', $response->getBody());
            $this->withSession($this->user('student'))->get('admin/carousel/' . $id . '/edit?fragment=1')->assertRedirectTo('/');
            $this->withSession($this->user('student'))->get('admin/carousel/create?fragment=1')->assertRedirectTo('/');
        } finally { $model->delete($id); }
    }

    public function testModalValidationReturnsJsonAndFreshCsrfWithoutRedirect(): void
    {
        foreach (['clinic/appointments/store', 'guidance/appointments/store', 'referrals/store'] as $route) {
            $name = csrf_token();
            $token = csrf_hash();
            service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $token);
            \Config\Services::resetSingle('security');
            $response = $this->withSession($this->user())->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post($route, [$name => $token]);
            $response->assertStatus(422);
            $payload = json_decode($response->getJSON(), true);
            $this->assertFalse($payload['success']);
            $this->assertNotEmpty($payload['message']);
            $this->assertSame(csrf_token(), $payload['csrf']['name']);
            $this->assertNotEmpty($payload['csrf']['hash']);
        }
    }

    public function testDirectFormRendersFlashErrorsInsideTheDialog(): void
    {
        $session = $this->user();
        $session['error'] = 'Booking failed: choose a different slot.';
        $session['__ci_vars'] = ['error' => 'new'];
        $response = $this->withSession($session)->withHeaders([])->get('clinic/appointments/create');
        $response->assertStatus(200);
        $dom = new \DOMDocument();
        $prior = libxml_use_internal_errors(true);
        $dom->loadHTML($response->getBody());
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
        $xpath = new \DOMXPath($dom);
        $this->assertStringContainsString('Booking failed: choose a different slot.',
            $xpath->query('//*[@id="appFormModal"]')->item(0)->textContent);
    }
}
