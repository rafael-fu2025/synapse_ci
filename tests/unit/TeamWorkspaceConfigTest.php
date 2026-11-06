<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\TeamWorkspace;

final class TeamWorkspaceConfigTest extends CIUnitTestCase
{
    private array $env;
    private array $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = $_ENV;
        $this->server = $_SERVER;
        unset($_ENV['teamWorkspace.enabled'], $_SERVER['teamWorkspace.enabled']);
        putenv('teamWorkspace.enabled');
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        $_SERVER = $this->server;
        putenv('teamWorkspace.enabled');
        parent::tearDown();
    }

    public function testRolloutIsDisabledByDefault(): void
    {
        self::assertFalse((new TeamWorkspace())->enabled);
    }

    public function testRolloutRequiresAnExplicitTrueValue(): void
    {
        foreach (['true', '1', 'yes', 'on', 'TRUE'] as $value) {
            $_ENV['teamWorkspace.enabled'] = $value;
            self::assertTrue((new TeamWorkspace())->enabled, $value);
        }
        foreach (['false', '0', 'no', 'off', '', 'unexpected'] as $value) {
            $_ENV['teamWorkspace.enabled'] = $value;
            self::assertFalse((new TeamWorkspace())->enabled, $value);
        }
    }
}
