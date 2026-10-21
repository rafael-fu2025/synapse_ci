<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Guards the custom widget layer (BalaySelect dropdown + BalayDatepicker).
 *
 * Two things are easy to break silently and hard to notice in review:
 * the widget scripts must load in dependency order, and date inputs must
 * keep their original POST/GET parameter names after the two-input split.
 *
 * @internal
 */
final class CustomWidgetsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private function adminSession(): array
    {
        return [
            'user' => [
                'id'          => 1,
                'name'        => 'Widget Admin',
                'username'    => 'admin@foundationu.com',
                'role'        => 'superadmin',
                'permissions' => ['rbac.manage'],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
        ];
    }

    /**
     * BalayDatepicker upgrades its month/year controls through BalaySelect,
     * so custom-dropdown.js must be evaluated first.
     */
    private function assertWidgetScriptOrder(string $body): void
    {
        $dropdown = strpos($body, 'custom-dropdown.js');
        $picker   = strpos($body, 'datepicker.js');

        $this->assertNotFalse($dropdown, 'custom-dropdown.js is not loaded.');
        $this->assertNotFalse($picker, 'datepicker.js is not loaded.');
        $this->assertLessThan($picker, $dropdown, 'custom-dropdown.js must load before datepicker.js.');
    }

    public function testAppLayoutLoadsWidgetScriptsInOrder(): void
    {
        $res = $this->withSession($this->adminSession())->get('/dashboard');

        $res->assertStatus(200);
        $this->assertWidgetScriptOrder($res->getBody());
    }

    public function testAuthLayoutLoadsWidgetScriptsInOrder(): void
    {
        $res = $this->get('auth/login');

        $res->assertStatus(200);
        $this->assertWidgetScriptOrder($res->getBody());
    }

    /**
     * The picker is purely opt-in via the data-datepicker attribute, so a
     * converted date field must ship both halves of the contract.
     */
    public function testConvertedDateFilterKeepsItsParameterName(): void
    {
        $res = $this->withSession([
            'user' => [
                'id'          => 10,
                'name'        => 'Clinic Nurse',
                'username'    => 'nurse_widget',
                'role'        => 'clinic_staff',
                'permissions' => ['clinic.encounters.read', 'clinic.patients.read'],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
        ])->get('clinic/encounters');

        $res->assertStatus(200);
        $body = $res->getBody();

        // No native date inputs remain anywhere in the app.
        $this->assertStringNotContainsString('type="date"', $body);

        // The trigger and its mirrored ISO value.
        $this->assertStringContainsString('data-datepicker', $body);
        $this->assertStringContainsString('data-target="#encounterDateFilter_iso"', $body);
        $this->assertStringContainsString('name="date" id="encounterDateFilter_iso"', $body);

        // The visible trigger keeps no name so it cannot submit a display string.
        $this->assertDoesNotMatchRegularExpression(
            '/<input(?![^>]*data-datepicker)[^>]*type="text"[^>]*name="date"/',
            $body
        );
    }

    /**
     * Filter selects must stay plain form-select so BalaySelect upgrades them;
     * a bespoke class here would silently leave a native select behind.
     */
    public function testSelectsRemainUpgradableByTheCustomDropdown(): void
    {
        $res = $this->withSession([
            'user' => [
                'id'          => 10,
                'name'        => 'Clinic Nurse',
                'username'    => 'nurse_widget',
                'role'        => 'clinic_staff',
                'permissions' => ['clinic.encounters.read'],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
        ])->get('clinic/encounters');

        $body = $res->getBody();
        preg_match_all('/<select\b[^>]*>/s', $body, $matches);

        $this->assertNotEmpty($matches[0], 'Expected at least one select on the encounters page.');
        foreach ($matches[0] as $select) {
            $this->assertStringContainsString('form-select', $select);
            $this->assertStringNotContainsString('multiple', $select);
        }
    }

    public function testWidgetAssetsExistAndAreImported(): void
    {
        $root = FCPATH;

        foreach (['assets/js/custom-dropdown.js', 'assets/js/datepicker.js', 'assets/css/datepicker.css', 'assets/css/core/synapse-widgets.css'] as $asset) {
            $this->assertFileExists($root . $asset, "Missing widget asset: {$asset}");
        }

        $style = (string) file_get_contents($root . 'assets/css/style.css');
        $this->assertStringContainsString('datepicker.css', $style);
        $this->assertStringContainsString('core/synapse-widgets.css', $style);
    }
}