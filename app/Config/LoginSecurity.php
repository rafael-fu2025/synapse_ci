<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class LoginSecurity extends BaseConfig
{
    // All submissions count, including successful authentication and MIS outages.
    public int $ipAttempts = 60;
    public int $ipWindowSeconds = 60;
    public int $identityAttempts = 5;
    public int $identityWindowSeconds = 300;
}
