<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class BmgFirmware extends BaseConfig
{
    /**
     * Manually approved releases only; no upload, build, discovery, or remote URLs.
     * Each entry: id (lowercase slug), name (plain text), chip_family ('ESP32-S3'),
     * flash_size ('16MB'), parts (nonempty list of name, filename, address, sha256,
     * optional size). Addresses and sizes are integers in bytes, never hex strings.
     * Addresses must be 4096-byte sector aligned. Each part reserves its full erase
     * range (size rounded up to 4096 bytes); ranges must not overlap or exceed 16MiB.
     * filename is relative to WRITEPATH/firmware/bmg/{id}/. SHA-256 is lowercase hex.
     * Chip approval is explicit operator curation, not automatic board acceptance.
     */
    public array $releases = [];
}
