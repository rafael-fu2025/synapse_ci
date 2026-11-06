<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/** Server-side rollout gates for the bearer Team Workspace. */
final class TeamWorkspace extends BaseConfig
{
    /** Disabled by default until production acceptance gates pass. */
    public bool $enabled = false;

    public function __construct()
    {
        parent::__construct();
        $value = $this->getEnvValue('enabled', self::class, 'teamWorkspace');
        if ($value !== null && $value !== '') {
            $this->enabled = in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        }
    }
}
