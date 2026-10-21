<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuthenticatedSession;
use App\Services\AuthenticatedUser;
use CodeIgniter\Session\Handlers\FileHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SessionRotationSecurityTest extends CIUnitTestCase
{
    private string $directory;
    private AuthenticatedSession $secureSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = WRITEPATH . 'cache/auth-session-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $config = config('Session');
        $config->savePath = $this->directory;
        $this->secureSession = Services::session($config, false);
        $handler = new FileHandler($config, '127.0.0.1');
        $handler->setLogger(service('logger'));
        session_set_save_handler($handler, true);
        ini_set('session.use_strict_mode', '1');
        session_name('synapse_security_test');
        session_start();
        Services::injectMock('session', $this->secureSession);
        $this->secureSession->set(['theme' => 'dark', 'user' => ['id' => 1, 'logged_in' => true], 'tenant_id' => 1,
            '_auth' => ['session_id' => session_id(), 'started_at' => time() - 100, 'last_seen_at' => time(), 'version' => 1]]);
    }

    protected function tearDown(): void
    {
        session_write_close();
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testRotationLeavesAnonymousPredecessorAndBindsOnlySuccessor(): void
    {
        $oldId = session_id();
        $auth = $this->secureSession->get('_auth');
        $this->assertSame('1', ini_get('session.use_strict_mode'));
        // This is the override dispatched by CI's automatic rotation in start().
        $this->secureSession->regenerate(config('Session')->regenerateDestroy);
        $newId = session_id();
        $this->assertNotSame($oldId, $newId);
        $this->assertSame($newId, $this->secureSession->get('_auth')['session_id']);
        $this->assertSame(array_replace($auth, ['session_id' => $newId]), $this->secureSession->get('_auth'));
        $this->assertSame($oldId, $auth['session_id'], 'Rotation must not mutate the saved parent metadata.');
        $this->assertSame(1, $this->secureSession->get('user')['id']);
        session_write_close();
        session_id($oldId);
        session_start();
        $this->assertNull($this->secureSession->get('user'));
        $this->assertNull($this->secureSession->get('_auth'));
        $this->assertNull($this->secureSession->get('tenant_id'));
        $this->assertSame('dark', $this->secureSession->get('theme'));
    }

    public function testLogoutClearsEveryPredecessorInTheCurrentRotationChain(): void
    {
        $ids = [session_id()];
        $this->secureSession->regenerate(false);
        $ids[] = session_id();
        AuthenticatedUser::clear();
        $this->secureSession->regenerate(true);
        $ids[] = session_id();
        session_write_close();
        foreach ($ids as $id) {
            session_id($id);
            session_start();
            $this->assertNull($this->secureSession->get('user'));
            $this->assertNull($this->secureSession->get('_auth'));
            $this->assertNull($this->secureSession->get('tenant_id'));
            $this->assertSame('dark', $this->secureSession->get('theme'));
            session_write_close();
        }
    }

    public function testLegacyAndMismatchedBindingsCannotBeUpgradedByRotation(): void
    {
        foreach ([null, ['session_id' => 'another-id']] as $auth) {
            $this->secureSession->set(['user' => ['id' => 1], 'tenant_id' => 1, '_auth' => $auth]);
            $this->secureSession->regenerate(false);
            $this->assertNull($this->secureSession->get('user'));
            $this->assertNull($this->secureSession->get('_auth'));
        }
    }

    public function testWaitingConcurrentRequestReadsAnonymousPredecessor(): void
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, TESTPATH . '_support/session_predecessor_probe.php',
            $this->directory, session_id()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($this->directory . '/waiting') && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($this->directory . '/waiting');
            usleep(100000);
            $this->assertTrue(proc_get_status($process)['running'], 'Reader should wait for the predecessor lock.');
            $this->secureSession->regenerate(false);
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFalse(proc_get_status($process)['running'], 'Concurrent session reader timed out.');
            $this->assertSame('', stream_get_contents($pipes[2]));
            $this->assertSame('{"authenticated":false,"strict_mode":true,"same_id":true}', stream_get_contents($pipes[1]));
        } finally {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
