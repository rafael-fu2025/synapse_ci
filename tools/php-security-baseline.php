<?php

declare(strict_types=1);

// Security floors from the PHP project's 21 October 2026 advisory.
$minimum = match (PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) {
    '8.2' => '8.2.35',
    '8.3' => '8.3.36',
    '8.4' => '8.4.27',
    '8.5' => '8.5.12',
    default => null,
};

printf("PHP runtime: %s (%s)\n", PHP_VERSION, PHP_SAPI);

if ($minimum === null || version_compare(PHP_VERSION, $minimum, '<')) {
    fwrite(STDERR, 'PHP does not meet a verified security baseline. Update the runtime or review this gate for a newer branch.' . PHP_EOL);
    exit(1);
}

printf("PHP security baseline satisfied (minimum %s).\n", $minimum);
