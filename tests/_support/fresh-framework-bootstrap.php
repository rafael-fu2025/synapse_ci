<?php

declare(strict_types=1);

// Equivalent test initialization to the framework bootstrap, except dotenv is never read.
// Keep this isolated to the guarded fresh-schema runner, not ordinary application boot.
$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$_SERVER['CI_ENVIRONMENT'] = 'testing';
defined('ENVIRONMENT') || define('ENVIRONMENT', 'testing');
defined('CI_DEBUG') || define('CI_DEBUG', true);
defined('HOMEPATH') || define('HOMEPATH', $root);
defined('CONFIGPATH') || define('CONFIGPATH', $root . 'app/Config/');
defined('PUBLICPATH') || define('PUBLICPATH', $root . 'public/');
require_once CONFIGPATH . 'Paths.php';
$paths = new \Config\Paths();
foreach ([
    'APPPATH' => $paths->appDirectory, 'ROOTPATH' => $root,
    'SYSTEMPATH' => $paths->systemDirectory, 'WRITEPATH' => $paths->writableDirectory,
    'TESTPATH' => $root . 'tests', 'CIPATH' => $paths->systemDirectory . '/..',
    'FCPATH' => PUBLICPATH, 'SUPPORTPATH' => __DIR__, 'VENDORPATH' => $root . 'vendor',
] as $name => $directory) {
    defined($name) || define($name, realpath($directory) . DIRECTORY_SEPARATOR);
}
defined('COMPOSER_PATH') || define('COMPOSER_PATH', $root . 'vendor/autoload.php');
require_once SYSTEMPATH . 'Boot.php';
final class FreshSchemaNoDotenvBoot extends \CodeIgniter\Boot
{
    protected static function loadDotEnv(\Config\Paths $paths): void
    {
        // Intentionally no filesystem access: all test settings come from the verified runner.
    }
}
FreshSchemaNoDotenvBoot::bootTest($paths);
service('routes')->loadRoutes();
