<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Throwable;

/** One native cURL handle per request; never the shared CI HTTP client. */
final class CurlTransport
{
    /**
     * @param array $endpoint Validated EndpointPolicy result, obtained immediately before dispatch.
     * @param list<string> $headers Application-generated headers only.
     * @param callable(string): void $receive Bounded incremental body consumer.
     * @return int HTTP status. No response/error text escapes the transport.
     */
    public function __invoke(#[\SensitiveParameter] Profile $profile, array $endpoint, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body, callable $receive, string $method = 'POST'): int
    {
        if (! extension_loaded('curl')) {
            throw new AiFailure('transport_unavailable');
        }
        $handle = null;
        $failure = null;
        try {
            $options = $this->options($profile, $endpoint, $headers, $body, $receive, $failure, $method);
            $handle = curl_init();
            if ($handle === false || ! @curl_setopt_array($handle, $options)) {
                throw new AiFailure('transport_failed');
            }
            $ok = @curl_exec($handle);
            if ($failure !== null) {
                throw new AiFailure($failure);
            }
            if ($ok === false) {
                throw new AiFailure('transport_failed');
            }

            return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } catch (Throwable $error) {
            throw new AiFailure($error instanceof AiFailure ? $error->reason : 'transport_failed');
        } finally {
            if ($handle instanceof \CurlHandle) {
                curl_close($handle);
            }
        }
    }

    /** Public for offline contract tests; does not open a connection. */
    public function options(#[\SensitiveParameter] Profile $profile, array $endpoint, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body, callable $receive, ?string &$failure = null, string $method = 'POST'): array
    {
        $profile->assertUnchanged();
        if (!in_array($method, ['POST', 'GET'], true) || ($method === 'GET' && $body !== '')) {
            throw new AiFailure('invalid_request');
        }
        $c = $profile->config;
        if (strlen($body) > $c->maxRequestBytes) {
            throw new AiFailure('request_too_large');
        }
        $received = 0;
        $consume = static function (#[\SensitiveParameter] string $chunk, bool $isBody) use (&$received, &$failure, $c, $receive): int {
            if ($failure !== null) {
                return 0;
            }
            $length = strlen($chunk);
            if ($length > $c->maxResponseBytes - $received) {
                $failure = 'response_too_large';

                return 0;
            }
            $received += $length;
            if ($isBody) {
                try {
                    $receive($chunk);
                } catch (Throwable $error) {
                    $failure = $error instanceof AiFailure ? $error->reason : 'transport_failed';

                    return 0;
                }
            }

            return $length;
        };
        $options = [
            CURLOPT_URL => $endpoint['url'],

            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $c->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $c->requestTimeoutSeconds,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_UNRESTRICTED_AUTH => false,
            CURLOPT_HTTPAUTH => CURLAUTH_NONE,
            CURLOPT_PROXYAUTH => CURLAUTH_NONE,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_RESOLVE => $endpoint['resolve'],
            CURLOPT_DNS_CACHE_TIMEOUT => 0,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_VERBOSE => false,
            CURLOPT_WRITEFUNCTION => static fn (mixed $handle, #[\SensitiveParameter] string $chunk): int => $consume($chunk, true),
            CURLOPT_HEADERFUNCTION => static fn (mixed $handle, #[\SensitiveParameter] string $chunk): int => $consume($chunk, false),
        ];
        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        // A new handle has no cookie engine, cookie file/jar, shared session, client cert or user credentials.
        if ($c->caBundle !== '') {
            $options[CURLOPT_CAINFO] = $c->caBundle;
        }

        return $options;
    }
}
