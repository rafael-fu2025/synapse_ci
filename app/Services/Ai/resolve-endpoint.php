<?php

declare(strict_types=1);

// Standalone CLI only: deliberately no autoloader, framework, dotenv, credentials or application state.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');

try {
    if ($argc !== 2 || ! is_string($argv[1]) || $argv[1] === '' || strlen($argv[1]) > 253
        || ! preg_match('/\A[a-z0-9.-]+\z/D', $argv[1])
        || preg_match('/\A(?:[0-9]+|0x[0-9a-f]+)(?:\.(?:[0-9]+|0x[0-9a-f]+))*\z/D', $argv[1])) {
        exit(1);
    }
    foreach (explode('.', $argv[1]) as $label) {
        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label)) {
            exit(1);
        }
    }
    // Keep standalone resolution consistent with BoundedResolver, without a DNS/hosts-file dependency.
    $records = $argv[1] === 'localhost'
        ? [['type' => 'A', 'ip' => '127.0.0.1']]
        : @dns_get_record($argv[1], DNS_A | DNS_AAAA);
    if ($records === false) {
        exit(1);
    }
    $addresses = [];
    foreach ($records as $record) {
        if (($record['type'] ?? '') === 'A' || ($record['type'] ?? '') === 'AAAA') {
            $address = ($record['type'] === 'A') ? ($record['ip'] ?? null) : ($record['ipv6'] ?? null);
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                exit(1);
            }
            $addresses[] = $address;
            if (count($addresses) > 64) {
                exit(1);
            }
        }
    }
    if ($addresses === []) {
        exit(1);
    }
    $json = json_encode($addresses, JSON_THROW_ON_ERROR);
    if (strlen($json) > 8192 || fwrite(STDOUT, $json) !== strlen($json)) {
        exit(1);
    }
    exit(0);
} catch (Throwable) {
    exit(1);
}
