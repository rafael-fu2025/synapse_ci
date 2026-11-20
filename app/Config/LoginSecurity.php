<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class LoginSecurity extends BaseConfig
{
    // All submissions count, including successful authentication and MIS outages.
    // Broad IP ceiling accounts for shared campus Wi-Fi NAT traffic while keeping per-IP+identity tight.
    public int $ipAttempts = 120;
    public int $ipWindowSeconds = 60;
    public int $identityAttempts = 5;
    public int $identityWindowSeconds = 300;
}
