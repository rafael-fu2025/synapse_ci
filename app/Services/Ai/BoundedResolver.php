<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Closure;
use Throwable;

/** DNS runs in a disposable, secret-free CLI process, never on the worker's blocking DNS stack. */
final class BoundedResolver
{
    private readonly Closure $processFactory;
    /** @var resource|null A failed OS kill must not cause blocking proc_close or unlimited child creation. */
    private static $unreaped = null;

    /**
     * Factory is an offline test seam, with proc_open's command/descriptors/cwd/env/options arguments.
     * It returns a native process resource. No shell, credentials, or application input is passed to the child.
     */
    public function __construct(#[\SensitiveParameter] private readonly Profile $profile, ?callable $processFactory = null)
    {
        $this->processFactory = Closure::fromCallable($processFactory ?? static function (array $command, array $descriptors, string $cwd, array $env, array $options) {
            return @proc_open($command, $descriptors, $pipes, $cwd, $env, $options);
        });
    }

    /** @return list<string> */
    public function __invoke(string $host): array
    {
        $this->profile->assertUnchanged();
        // DNS does not consult hosts files. Only this exact name has a deterministic local address;
        // EndpointPolicy must still authorize the origin and loopback address before connecting.
        if ($host === 'localhost') {
            return ['127.0.0.1'];
        }
        $timeout = $this->profile->config->connectTimeoutSeconds;
        $deadline = hrtime(true) + $timeout * 1000000000;
        // Reserve cleanup time inside the resolver budget, not in addition to it.
        $workDeadline = $deadline - 100000000;
        $process = null;
        $output = null;
        try {
            if (! function_exists('proc_open') || ! function_exists('proc_get_status') || ! function_exists('proc_terminate')
                || ! function_exists('proc_close') || ! self::validHost($host)) {
                throw new AiFailure('dns_failed');
            }
            if (is_resource(self::$unreaped)) {
                self::stop(self::$unreaped, hrtime(true));
                if (is_resource(self::$unreaped)) {
                    throw new AiFailure('dns_failed');
                }
            }
            $binary = self::phpBinary($this->profile->config->resolverPhpBinary);
            $command = [$binary, '-n', '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'memory_limit=16M',
                __DIR__ . DIRECTORY_SEPARATOR . 'resolve-endpoint.php', $host];
            $env = [];
            if (PHP_OS_FAMILY === 'Windows') {
                $root = getenv('SystemRoot');
                if (is_string($root) && $root !== '' && ! str_contains($root, "\0")) {
                    $env['SystemRoot'] = $root;
                }
            }
            $output = @tmpfile();
            if (! is_resource($output)) {
                throw new AiFailure('dns_failed');
            }
            // Anonymous proc pipes can block on Windows even after stream_set_blocking(false).
            // Regular-file reads never wait for child output; the trusted helper itself emits <=8192 bytes.
            $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $descriptors = [0 => ['file', $null, 'r'],
                1 => ['file', stream_get_meta_data($output)['uri'], 'w'], 2 => ['file', $null, 'w']];
            $process = ($this->processFactory)($command, $descriptors, __DIR__, $env, ['bypass_shell' => true]);
            if (! is_resource($process) || get_resource_type($process) !== 'process') {
                throw new AiFailure('dns_failed');
            }
            $json = '';
            while (true) {
                if (hrtime(true) >= $workDeadline) {
                    throw new AiFailure('dns_failed');
                }
                $status = @proc_get_status($process);
                if (! is_array($status)) {
                    throw new AiFailure('dns_failed');
                }
                // Seek clears the EOF indicator when the child appends between polls.
                if (@fseek($output, strlen($json)) !== 0) {
                    throw new AiFailure('dns_failed');
                }
                $chunk = @fread($output, 8193 - strlen($json));
                if ($chunk === false) {
                    throw new AiFailure('dns_failed');
                }
                $json .= $chunk;
                if (strlen($json) > 8192) {
                    throw new AiFailure('dns_failed');
                }
                if (! $status['running']) {
                    if ($status['exitcode'] !== 0) {
                        throw new AiFailure('dns_failed');
                    }
                    break;
                }
                usleep(5000);
            }
            $addresses = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
            if (! is_array($addresses) || ! array_is_list($addresses) || $addresses === [] || count($addresses) > 64) {
                throw new AiFailure('dns_failed');
            }
            foreach ($addresses as $address) {
                if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                    throw new AiFailure('dns_failed');
                }
            }

            return $addresses;
        } catch (Throwable) {
            throw new AiFailure('dns_failed');
        } finally {
            if (is_resource($process) && get_resource_type($process) === 'process') {
                self::stop($process, $deadline);
                if (is_resource($process)) {
                    self::$unreaped = $process;
                }
            }
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    /** Deployment-owned executable; no PATH search, PHP_BINARY/FPM fallback, wrappers or UNC paths. */
    public static function phpBinary(string $configured = ''): string
    {
        $path = $configured !== '' ? $configured : PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (strlen($path) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $path)
            || ! preg_match('~\A(?:[A-Za-z]:[\\\\/]|/(?!/))~D', $path)
            || ! is_file($path) || ! is_readable($path) || ! is_executable($path)) {
            throw new AiFailure('invalid_config');
        }
        $real = realpath($path);
        if ($real === false) {
            throw new AiFailure('invalid_config');
        }

        return $real;
    }

    public static function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || ! preg_match('/\A[a-z0-9.-]+\z/D', $host)
            || preg_match('/\A(?:[0-9]+|0x[0-9a-f]+)(?:\.(?:[0-9]+|0x[0-9a-f]+))*\z/D', $host)) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label)) {
                return false;
            }
        }

        return true;
    }

    /** Never call proc_close on a running child: it waits without a PHP timeout. */
    private static function stop(&$process, int $deadline): void
    {
        do {
            $status = @proc_get_status($process);
            if (is_array($status) && ! $status['running']) {
                @proc_close($process);
                $process = null;

                return;
            }
            @proc_terminate($process, 9);
            $status = @proc_get_status($process);
            if (is_array($status) && ! $status['running']) {
                @proc_close($process);
                $process = null;

                return;
            }
            if (hrtime(true) >= $deadline) {
                return;
            }
            usleep(1000);
        } while (true);
    }
}
