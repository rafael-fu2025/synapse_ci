<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProductionSessionConfigTest extends CIUnitTestCase
{
    public function testOnlyProductionDefaultsToSecureCookiesAndHttps(): void
    {
        foreach (['production' => '[true,true]', 'development' => '[false,false]', 'testing' => '[false,false]'] as $environment => $expected) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, TESTPATH . '_support/security_config_defaults.php', $environment],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $this->assertSame($expected, stream_get_contents($pipes[1]));
            $this->assertSame('', stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process));
        }
    }

    public function testProductionExplicitInsecureOverridesAreNotSilentlyOverridden(): void
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, TESTPATH . '_support/security_config_defaults.php', 'production', 'false'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $this->assertSame('[true,true][false,false]', stream_get_contents($pipes[1]));
        $this->assertSame('', stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process));
    }

    public function testSessionFactorySignatureMatchesInstalledFramework(): void
    {
        $actual = new \ReflectionMethod(\Config\Services::class, 'session');
        $framework = new \ReflectionMethod(\CodeIgniter\Config\Services::class, 'session');
        foreach ($framework->getParameters() as $index => $expected) {
            $parameter = $actual->getParameters()[$index];
            $this->assertSame($expected->getName(), $parameter->getName());
            $this->assertSame((string) $expected->getType(), (string) $parameter->getType());
            $this->assertSame($expected->getDefaultValue(), $parameter->getDefaultValue());
        }
        $this->assertSame($framework->isStatic(), $actual->isStatic());
        $this->assertSame((string) $framework->getReturnType(), (string) $actual->getReturnType());
    }

    public function testExistingEnvironmentOverridesRemainEffective(): void
    {
        foreach (['true' => true, 'false' => false] as $value => $expected) {
            foreach (['cookie.secure', 'app.forceGlobalSecureRequests'] as $key) {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv($key . '=' . $value);
            }
            $this->assertSame($expected, (new \Config\Cookie())->secure);
            $this->assertSame($expected, (new \Config\App())->forceGlobalSecureRequests);
        }
        $_ENV['session.authIdleTimeout'] = $_SERVER['session.authIdleTimeout'] = '60';
        $_ENV['session.authAbsoluteTimeout'] = $_SERVER['session.authAbsoluteTimeout'] = '120';
        $config = new \Config\Session();
        $this->assertSame(60, $config->authIdleTimeout);
        $this->assertSame(120, $config->authAbsoluteTimeout);
    }
}
