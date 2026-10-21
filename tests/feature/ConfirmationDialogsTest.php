<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

final class ConfirmationDialogsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    public function testApplicationRendersOneSharedDialogAfterBootstrap(): void
    {
        $response = $this->withSession([
            'user' => [
                'id' => 1,
                'name' => 'Confirmation Admin',
                'role' => 'superadmin',
                'permissions' => ['rbac.manage'],
                'logged_in' => true,
            ],
            'tenant_id' => 1,
        ])->get('admin/carousel');
        $response->assertStatus(200);
        $body = $response->getBody();
        $this->assertSame(1, substr_count($body, 'id="synapseConfirmModal"'));
        $this->assertSame(1, substr_count($body, 'id="logoutConfirmModal"'));
        $this->assertStringContainsString('aria-describedby="synapseConfirmMessage"', $body);
        $bootstrap = strpos($body, 'bootstrap.bundle.min.js');
        $confirm = strpos($body, 'assets/js/confirm.js"');
        // Match the script URL, not the layout comment describing the asset.
        $this->assertNotFalse($bootstrap);
        $this->assertNotFalse($confirm);
        $this->assertGreaterThan($bootstrap, $confirm);
    }

    public function testAllFormerNativeConfirmationSitesUseTheSharedDialog(): void
    {
        foreach ([
            'admin/carousel/index.php',
            'appointments/index.php',
            'portal/index.php',
            'referrals/index.php',
            'guidance/cases/view.php',
        ] as $view) {
            $source = file_get_contents(APPPATH . 'Views/' . $view);
            $this->assertStringContainsString('data-confirm=', $source, $view);
            $this->assertStringContainsString('data-confirm-title=', $source, $view);
            $this->assertStringContainsString('data-confirm-label=', $source, $view);
            $this->assertDoesNotMatchRegularExpression('/onsubmit\s*=.*\bconfirm\s*\(/', $source, $view);
        }
    }
}
