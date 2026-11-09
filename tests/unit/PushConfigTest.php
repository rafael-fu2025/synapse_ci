<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Push;

/** No network, no service account: only the environment parsing is exercised. */
final class PushConfigTest extends CIUnitTestCase
{
    private array $backup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->backup;
        parent::tearDown();
    }

    /**
     * The framework also exposes every variable through getenv(), so a key is
     * only really gone once both places are cleared.
     */
    private function clearEnv(string $key): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    public function testPushIsOffUntilItIsExplicitlyEnabled(): void
    {
        unset($_ENV['push.enabled']);
        $config = new Push();
        self::assertFalse($config->enabled);
        self::assertFalse($config->isConfigured());
    }

    public function testTheEnabledFlagAcceptsTheUsualSpellings(): void
    {
        foreach (['true', '1', 'yes', 'on', 'TRUE'] as $value) {
            $_ENV['push.enabled'] = $value;
            self::assertTrue((new Push())->enabled, $value);
        }
        foreach (['false', '0', 'no', 'off', ''] as $value) {
            $_ENV['push.enabled'] = $value;
            self::assertFalse((new Push())->enabled, $value);
        }
    }

    public function testTheProjectAndKeyPathAreReadFromTheEnvironment(): void
    {
        $_ENV['push.enabled'] = 'true';
        $_ENV['push.project_id'] = 'synapse-campus';
        $_ENV['push.service_account_path'] = 'C:/secrets/synapse-campus.json';
        $config = new Push();
        self::assertSame('synapse-campus', $config->projectId);
        self::assertSame('C:/secrets/synapse-campus.json', $config->serviceAccountPath);
        self::assertTrue($config->isConfigured());
    }

    public function testAnEnabledProjectWithoutAKeyIsNeverConfigured(): void
    {
        $_ENV['push.enabled'] = 'true';
        $_ENV['push.project_id'] = 'synapse-campus';
        $this->clearEnv('push.service_account_path');
        $config = new Push();
        self::assertTrue($config->enabled);
        self::assertFalse($config->isConfigured(), 'No key means nothing may be sent');
    }

    public function testDeploymentIdentityIsExplicitAndNeverDerivedFromTheHost(): void
    {
        $_ENV['push.deployment_id'] = '';
        $config = new Push();
        self::assertSame('', $config->deploymentId);
        self::assertFalse($config->hasDeploymentId());

        $_ENV['push.deployment_id'] = '  synapse-university-test  ';
        $config = new Push();
        self::assertSame('synapse-university-test', $config->deploymentId);
        self::assertTrue($config->hasDeploymentId());
    }

    public function testDeploymentIdentifiersAreBoundedOpaqueNamesNotUrls(): void
    {
        $config = new Push();
        foreach (['abcdefgh', 'university-2026', 'home_test.v1', str_repeat('a', 128)] as $id) {
            $config->deploymentId = $id;
            self::assertTrue($config->hasDeploymentId(), $id);
        }
        foreach (['', 'short', '-deployment', 'contains space', 'https://example.test', '../scope',
            "scope-id\n", str_repeat('a', 129), 'deployment/next', 'uni-\u{00e9}'] as $id) {
            $config->deploymentId = $id;
            self::assertFalse($config->hasDeploymentId(), $id);
        }
        $config->enabled = true;
        $config->projectId = 'synthetic-project';
        $config->serviceAccountPath = 'not-read.json';
        $config->deploymentId = '';
        self::assertTrue($config->isConfigured(), 'A missing LAN scope must not disable legacy push.');
    }

    public function testTheTokenCapIsBounded(): void
    {
        $_ENV['push.max_active_tokens'] = '3';
        self::assertSame(3, (new Push())->maxActiveTokens);
        $_ENV['push.max_active_tokens'] = '0';
        self::assertSame(1, (new Push())->maxActiveTokens, 'A cap of zero would disable every device');
    }
}
