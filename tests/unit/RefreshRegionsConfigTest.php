<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\RefreshRegions;

final class RefreshRegionsConfigTest extends CIUnitTestCase
{
    private array $env;
    private array $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = $_ENV;
        $this->server = $_SERVER;
        unset(
            $_ENV['refreshRegions.enabled'],
            $_ENV['refreshRegions.owners'],
            $_SERVER['refreshRegions.enabled'],
            $_SERVER['refreshRegions.owners'],
        );
        putenv('refreshRegions.enabled');
        putenv('refreshRegions.owners');
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        $_SERVER = $this->server;
        putenv('refreshRegions.enabled');
        putenv('refreshRegions.owners');
        parent::tearDown();
    }

    public function testRegionalRefreshIsDisabledByDefault(): void
    {
        $config = new RefreshRegions();

        self::assertFalse($config->enabled);
        self::assertSame([], $config->owners);
        self::assertFalse($config->enables('announcements.staff.clinic'));
    }

    public function testRolloutRequiresTheMasterFlagAndAnAllowedOwner(): void
    {
        $_ENV['refreshRegions.enabled'] = 'true';
        $_ENV['refreshRegions.owners'] = ' announcements.staff.clinic, surveys.staff, invalid owner, surveys.staff ';
        $config = new RefreshRegions();

        self::assertSame(['announcements.staff.clinic', 'surveys.staff'], $config->owners);
        self::assertTrue($config->enables('announcements.staff.clinic'));
        self::assertTrue($config->enables('surveys.staff'));
        self::assertFalse($config->enables('inventory.item.42'));
    }

    public function testWildcardEnablesAnyValidDeclaredOwner(): void
    {
        $_ENV['refreshRegions.enabled'] = 'on';
        $_ENV['refreshRegions.owners'] = '*';
        $config = new RefreshRegions();

        self::assertTrue($config->enables('facilities.unit.4'));
    }
}
