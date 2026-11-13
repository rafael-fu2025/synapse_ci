<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/** Server-side rollout gates for authoritative named-region refreshes. */
final class RefreshRegions extends BaseConfig
{
    /** Disabled until the shared browser and screen gates pass. */
    public bool $enabled = false;

    /** @var list<string> */
    public array $owners = [];

    public function __construct()
    {
        parent::__construct();

        $enabled = $this->getEnvValue('enabled', self::class, 'refreshRegions');
        if ($enabled !== null && $enabled !== '') {
            $this->enabled = in_array(strtolower((string) $enabled), ['1', 'true', 'yes', 'on'], true);
        }

        $owners = $this->getEnvValue('owners', self::class, 'refreshRegions');
        if (is_string($owners) && $owners !== '') {
            $this->owners = array_values(array_unique(array_filter(array_map(
                static fn (string $owner): string => trim($owner),
                explode(',', $owners),
            ), static fn (string $owner): bool => self::validToken($owner))));
        }
    }

    public function enables(string $owner): bool
    {
        return $this->enabled && (in_array('*', $this->owners, true) || in_array($owner, $this->owners, true));
    }

    private static function validToken(string $value): bool
    {
        return $value === '*' || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $value) === 1;
    }
}
