<?php

declare(strict_types=1);

// Finite loopback-only compatibility fixture. No framework, dotenv, records, or persistent request logs.
$mode = $argv[1] ?? '';
if (!in_array($mode, ['success', 'redirect', 'oversized'], true)) { exit(2); }
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($server)) { exit(3); }
$address = stream_socket_get_name($server, false);
if (!is_string($address) || !preg_match('/\A127\.0\.0\.1:([1-9][0-9]*)\z/', $address, $match)) { exit(4); }
echo json_encode(['port' => (int) $match[1]], JSON_THROW_ON_ERROR), "\n";
fflush(STDOUT);
$client = @stream_socket_accept($server, 5);
if (!is_resource($client)) { fclose($server); exit(5); }
stream_set_timeout($client, 3);
$headers = '';
while (!str_contains($headers, "\r\n\r\n") && strlen($headers) <= 8192) {
    $byte = fread($client, 1);
    if ($byte === false || $byte === '') { break; }
    $headers .= $byte;
}
$valid = preg_match('~\APOST /team-a/v1/chat/completions HTTP/1\.[01]\r\n~', $headers)
    && preg_match('/\r\nAuthorization: Bearer SYNTHETIC-LOOPBACK-KEY\r\n/i', $headers)
    && !preg_match('/\r\n(?:Cookie|Proxy-Authorization):/i', $headers)
    && preg_match('/\r\nContent-Length: ([0-9]+)\r\n/i', $headers, $length);
$body = '';
$size = $valid ? (int) $length[1] : 0;
if ($size > 8192) { $valid = false; }
while ($valid && strlen($body) < $size) {
    $chunk = fread($client, $size - strlen($body));
    if ($chunk === false || $chunk === '') { $valid = false; break; }
    $body .= $chunk;
}
$input = json_decode($body, true);
$valid = $valid && is_array($input) && ($input['model'] ?? null) === 'organization/synthetic@revision'
    && ($input['stream'] ?? null) === false && isset($input['messages'][1]['content'])
    && str_contains($input['messages'][1]['content'], 'No personal or clinical data is present.');
if (!$valid) {
    fwrite($client, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
} elseif ($mode === 'redirect') {
    fwrite($client, "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:1/forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
} else {
    $response = $mode === 'oversized' ? str_repeat('x', 4096) : json_encode(['choices' => [[
        'index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"ok":true}'],
    ]]], JSON_THROW_ON_ERROR);
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);
}
fclose($client);
fclose($server);
exit($valid ? 0 : 6);
