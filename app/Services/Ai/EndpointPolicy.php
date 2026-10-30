<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Closure;
use Throwable;

/** Exact origins, strict URL parsing, all-answer DNS validation, and a fresh connection pin. */
final class EndpointPolicy
{
    private readonly Closure $resolver;

    /** @param null|callable(string): list<string> $resolver No sockets are opened by an injected resolver. */
    public function __construct(private readonly Profile $profile, ?callable $resolver = null)
    {
        $this->resolver = Closure::fromCallable($resolver ?? new BoundedResolver($profile));
    }

    /** @return array{url:string,origin:string,host:string,port:int,scheme:string,addresses:list<string>,resolve:list<string>} */
    public function validate(): array
    {
        $this->profile->assertUnchanged();
        $c = $this->profile->config;
        $endpoint = self::parseBaseUrl($c->baseUrl);
        $private = in_array($endpoint['origin'], self::origins($c->privateOrigins), true);
        if (! in_array($endpoint['origin'], self::origins($c->allowedOrigins), true)
            || ($endpoint['scheme'] === 'http' && (! $private || ! $c->allowLocalHttp))) {
            throw new AiFailure('endpoint_not_allowed');
        }
        $literal = filter_var($endpoint['host'], FILTER_VALIDATE_IP) !== false;
        try {
            $addresses = $literal ? [$endpoint['host']] : ($this->resolver)($endpoint['host']);
        } catch (Throwable) {
            throw new AiFailure('dns_failed');
        }
        if (! is_array($addresses) || ! array_is_list($addresses) || $addresses === [] || count($addresses) > 64) {
            throw new AiFailure('dns_failed');
        }
        foreach ($addresses as $address) {
            if (! is_string($address) || ! self::addressAllowed($address, $private)) {
                throw new AiFailure('address_not_allowed');
            }
        }
        // Validate ALL answers, then pin one. A fresh handle prevents stale pooled connections or DNS fallback.
        $pin = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
        $endpoint['addresses'] = array_values(array_unique($addresses));
        $endpoint['resolve'] = $literal ? [] : [$endpoint['host'] . ':' . $endpoint['port'] . ':' . $pin];

        return $endpoint;
    }

    public function __invoke(): array
    {
        return $this->validate();
    }

    /** @return list<string> Canonical origins, never host suffixes or wildcards. */
    public static function origins(string $value): array
    {
        $origins = [];
        foreach (Profile::csv($value) as $origin) {
            $parts = self::parse($origin);
            if ($parts['path'] !== '') {
                throw new AiFailure('invalid_config');
            }
            $origins[] = $parts['origin'];
        }
        if (count(array_unique($origins)) !== count($origins)) {
            throw new AiFailure('invalid_config');
        }

        return $origins;
    }

    /** @return array{url:string,origin:string,host:string,port:int,scheme:string} */
    public static function parseBaseUrl(string $url): array
    {
        $parts = self::parse($url);
        $path = rtrim($parts['path'], '/');
        if (str_ends_with(strtolower($path), '/chat/completions')) {
            throw new AiFailure('invalid_config');
        }
        unset($parts['path']);
        $parts['url'] = $parts['origin'] . $path . '/chat/completions';

        return $parts;
    }

    private static function parse(string $url): array
    {
        // Reject parser differentials before parse_url can silently replace control characters.
        if ($url === '' || strlen($url) > 2048 || preg_match('/[^\x21-\x7e]|[\\\\%?#@]/', $url)
            || ! preg_match('~\Ahttps?://~D', $url)) {
            throw new AiFailure('invalid_config');
        }
        try {
            $p = parse_url($url);
        } catch (Throwable) {
            throw new AiFailure('invalid_config');
        }
        if (! is_array($p) || ! isset($p['scheme'], $p['host'])
            || array_diff(array_keys($p), ['scheme', 'host', 'port', 'path']) !== []) {
            throw new AiFailure('invalid_config');
        }
        $host = strtolower($p['host']);
        $ipv6 = str_starts_with($host, '[') && str_ends_with($host, ']');
        if ($ipv6) {
            $host = substr($host, 1, -1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new AiFailure('invalid_config');
            }
            $host = inet_ntop(inet_pton($host));
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            if (strlen($host) > 253 || ! preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D', $host)
                || preg_match('/\A(?:[0-9]+|0x[0-9a-f]+)(?:\.(?:[0-9]+|0x[0-9a-f]+))*\z/iD', $host)) {
                throw new AiFailure('invalid_config');
            }
            foreach (explode('.', $host) as $label) {
                if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label)) {
                    throw new AiFailure('invalid_config');
                }
            }
        }
        $port = $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80);
        $authority = substr($url, strpos($url, '://') + 3);
        $authority = explode('/', $authority, 2)[0];
        if ($port < 1 || $port > 65535 || str_ends_with($authority, ':')
            || (isset($p['port']) && ! str_ends_with($authority, ':' . $port))) {
            throw new AiFailure('invalid_config');
        }
        $path = $p['path'] ?? '';
        // Conservative unescaped path grammar: no encoded separators, dot segments, matrix params, or //.
        if ($path !== '' && (! preg_match('#\A/[A-Za-z0-9._~/-]*\z#D', $path)
            || str_contains($path, '//') || in_array('.', explode('/', $path), true) || in_array('..', explode('/', $path), true))) {
            throw new AiFailure('invalid_config');
        }
        $origin = $p['scheme'] . '://' . ($ipv6 ? '[' . $host . ']' : $host)
            . ($port === ($p['scheme'] === 'https' ? 443 : 80) ? '' : ':' . $port);

        return ['origin' => $origin, 'host' => $host, 'port' => $port, 'scheme' => $p['scheme'], 'path' => $path];
    }

    /** Exceptions authorize only RFC1918, ULA, or loopback, never metadata/link-local/special-use space. */
    public static function addressAllowed(string $address, bool $private = false): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $v4 = ! str_contains($address, ':');
        // AWS/GCP metadata uses ULA too; a private-origin exception must never admit it.
        if (! $v4 && (self::inRange($address, 'fd00:ec2::254/128') || self::inRange($address, 'fd20:ce::254/128'))) {
            return false;
        }
        $privateRanges = $v4 ? ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8'] : ['fc00::/7', '::1/128'];
        foreach ($privateRanges as $range) {
            if (self::inRange($address, $range)) {
                return $private;
            }
        }
        if ($private) {
            return false;
        }
        if (! $v4 && ! self::inRange($address, '2000::/3')) {
            return false;
        }
        $denied = $v4 ? [
            '0.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24',
            '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
            '224.0.0.0/4', '240.0.0.0/4', '168.63.129.16/32',
        ] : ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        foreach ($denied as $range) {
            if (self::inRange($address, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $address, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $ip = inet_pton($address);
        $net = inet_pton($network);
        if ($ip === false || $net === false || strlen($ip) !== strlen($net)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $remaining = (int) $bits % 8;

        return substr($ip, 0, $bytes) === substr($net, 0, $bytes)
            && ($remaining === 0 || ((ord($ip[$bytes]) ^ ord($net[$bytes])) & (255 << (8 - $remaining))) === 0);
    }

}
