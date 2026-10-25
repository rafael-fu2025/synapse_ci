<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class BmgBuild extends BaseConfig
{
    // CI4 environment overrides: bmgbuild.enabled, .cli, .signingKey, .timeoutSeconds.
    public bool $enabled = false;
    /** Absolute path to a locally provisioned Arduino CLI executable; never a command line. */
    public string $cli = '';
    /** Independent secret: at least 32 bytes, not the application's encryption key. */
    public string $signingKey = '';
    public int $timeoutSeconds = 180;
    public string $fqbn = 'esp32:esp32:esp32s3:FlashMode=qio,FlashSize=16M,PartitionScheme=app3M_fat9M_16MB,PSRAM=disabled';
    public const FQBN = 'esp32:esp32:esp32s3:FlashMode=qio,FlashSize=16M,PartitionScheme=app3M_fat9M_16MB,PSRAM=disabled';
    public const VERSION = 'bmg-commissioning-0.1.0';
    public const TTL = 1800;
    /** Reviewed, credential-free sources only. Changes require a fresh source review. */
    public const SOURCES = [
        'compost.ino' => 'a4f97b6bbe8db7337102f9624ef9c783c5270be3d297136cb4b51527ec24eae3',
        'commissioning_policy.h' => '4541a47945d36c359935f69517e6353de78e0575ea6fff65293f3e2224b0c64d',
    ];
}
