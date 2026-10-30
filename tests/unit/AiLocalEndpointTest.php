<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiFailure;
use App\Services\Ai\ChatClient;
use App\Services\Ai\Profile;
use Config\Ai;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Actual cURL to a finite synthetic loopback fixture only. Never loads dotenv or contacts an external provider. */
final class AiLocalEndpointTest extends TestCase
{
    public static function modes(): iterable
    {
        yield 'preserved custom prefix and bearer authentication' => ['success', null];
        yield 'redirects are rejected, never followed' => ['redirect', 'provider_rejected'];
        yield 'receive-time response bound' => ['oversized', 'response_too_large'];
    }

    #[DataProvider('modes')]
    public function testNativeCustomEndpointProbe(string $mode, ?string $expected): void
    {
        if (!extension_loaded('curl') || !function_exists('proc_open')) {
            $this->markTestSkipped('Native synthetic compatibility test requires cURL and process support.');
        }
        $output = tmpfile();
        $process = null;
        try {
            $env = [];
            if (PHP_OS_FAMILY === 'Windows' && is_string(getenv('SystemRoot'))) { $env['SystemRoot'] = getenv('SystemRoot'); }
            $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $process = proc_open([PHP_BINARY, TESTPATH . '_support/ai-compatible-endpoint.php', $mode],
                [0 => ['file', $null, 'r'], 1 => ['file', stream_get_meta_data($output)['uri'], 'w'], 2 => ['file', $null, 'w']],
                $pipes, ROOTPATH, $env, ['bypass_shell' => true]);
            self::assertIsResource($process);
            $deadline = hrtime(true) + 5_000_000_000;
            $port = null;
            while (hrtime(true) < $deadline) {
                fseek($output, 0);
                $ready = json_decode(trim(stream_get_contents($output, 256)), true);
                if (is_int($ready['port'] ?? null)) { $port = $ready['port']; break; }
                $status = proc_get_status($process);
                if (!$status['running']) { break; }
                usleep(5000);
            }
            self::assertIsInt($port, 'Finite synthetic loopback fixture did not become ready.');
            $config = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
            $origin = 'http://127.0.0.1:' . $port;
            $config->baseUrl = $origin . '/team-a/v1';
            $config->allowedOrigins = $origin;
            $config->privateOrigins = $origin;
            $config->allowLocalHttp = true;
            $config->model = 'organization/synthetic@revision';
            $config->apiKey = 'SYNTHETIC-LOOPBACK-KEY';
            $config->requestTimeoutSeconds = 5;
            $config->maxResponseBytes = 512;
            $client = new ChatClient(Profile::fromConfig($config));
            if ($expected === null) {
                self::assertSame(['success' => true, 'verified' => true], $client->probe());
            } else {
                try { $client->probe(); self::fail('Expected a safe compatibility failure.'); }
                catch (AiFailure $error) { self::assertSame($expected, $error->reason); self::assertNull($error->getPrevious()); }
            }
        } finally {
            if (is_resource($process)) {
                $deadline = hrtime(true) + 1_000_000_000;
                do {
                    $status = proc_get_status($process);
                    if (!$status['running']) { proc_close($process); $process = null; break; }
                    proc_terminate($process, 9);
                    usleep(5000);
                } while (hrtime(true) < $deadline);
            }
            if (is_resource($output)) { fclose($output); }
        }
    }
}
