<?php

declare(strict_types=1);

namespace Tests\Support {
    // This bootstrap is only for explicitly selected, constructor-free unit tests.
    // It must not follow an ordinary application/PHPUnit bootstrap or read dotenv.
    if (defined('WRITEPATH') || class_exists(\Config\Database::class, false)) {
        throw new \LogicException('Teams response tests require a fresh --no-configuration process.');
    }

    final class TeamWorkspaceResponseGuard
    {
        /** @var list<string> */
        public static array $blocked = [];

        public static function reject(string $operation): never
        {
            self::$blocked[] = $operation;
            throw new \LogicException('Isolated Teams unit test blocked ' . $operation . '.');
        }
    }

    spl_autoload_register(static function (string $class): void {
        $name = strtolower($class);
        if ((str_starts_with($name, 'codeigniter\\database\\') && str_ends_with($name, '\\connection'))
            || str_starts_with($name, 'app\\database\\migrations\\')
            || in_array($name, [
                'app\\services\\realtime\\publisher',
                'app\\services\\push\\pushpublisher',
                'app\\services\\teamsnotifications',
                'app\\services\\teamchatmentionnotifications',
                'app\\services\\notificationservice',
            ], true)) {
            TeamWorkspaceResponseGuard::reject('autoload ' . $class);
        }
    }, true, true);
}

namespace Config {
    // Replace only the database boundary in this fresh test process. No connection
    // object, credentials, SQLite handle, migration runner, or real records exist.
    if (!class_exists(Database::class, false)) {
        final class Database
        {
            public static function connect($group = null, bool $getShared = true): never
            {
                \Tests\Support\TeamWorkspaceResponseGuard::reject('Database::connect');
            }

            public static function forge($group = null): never
            {
                \Tests\Support\TeamWorkspaceResponseGuard::reject('Database::forge');
            }

            public static function seeder(?string $group = null): never
            {
                \Tests\Support\TeamWorkspaceResponseGuard::reject('Database::seeder');
            }

            public static function getConnections(): array
            {
                return [];
            }
        }
    }
}

namespace {
    $runtime = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'humus-teams-response-' . bin2hex(random_bytes(8));
    foreach (['', '/cache', '/logs', '/session', '/debugbar'] as $directory) {
        if (!mkdir($runtime . $directory, 0700, true)) {
            throw new \RuntimeException('Cannot initialize isolated Teams temporary runtime.');
        }
    }
    define('WRITEPATH', $runtime . DIRECTORY_SEPARATOR);
    define('TEAM_WORKSPACE_RESPONSE_ISOLATED', true);
    register_shutdown_function(static function () use ($runtime): void {
        if (!is_dir($runtime)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($runtime, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($runtime);
    });

    foreach ([
        'CI_ENVIRONMENT' => 'testing',
        'FUMIS_ENABLED' => 'false',
        'FUMIS_FAKE_ENABLED' => 'false',
        'realtime.enabled' => 'false',
        'push.enabled' => 'false',
        'refreshRegions.enabled' => 'false',
        'refreshRegions.owners' => '',
        'teamWorkspace.enabled' => 'false',
        'logger.threshold' => '0',
    ] as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    // Read-verified framework entry: its Boot subclass overrides loadDotEnv with
    // no filesystem access. It registers routes but does not dispatch them.
    require __DIR__ . '/fresh-framework-bootstrap.php';
    helper('url');

    if (\Tests\Support\TeamWorkspaceResponseGuard::$blocked !== []) {
        throw new \LogicException('The isolated bootstrap attempted a forbidden dependency.');
    }
}
