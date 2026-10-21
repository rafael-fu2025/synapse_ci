<?php

declare(strict_types=1);

// Isolated boot: unlike system/Test/bootstrap.php, this never loads a .env file.
$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
defined('ENVIRONMENT') || define('ENVIRONMENT', 'testing');
define('CI_DEBUG', true);
define('HOMEPATH', $root);
define('CONFIGPATH', $root . 'app/Config/');
require CONFIGPATH . 'Paths.php';
$paths = new \Config\Paths();
foreach ([
    'APPPATH' => $paths->appDirectory, 'ROOTPATH' => $root,
    'SYSTEMPATH' => $paths->systemDirectory, 'WRITEPATH' => $paths->writableDirectory,
    'TESTPATH' => $root . 'tests', 'PUBLICPATH' => $root . 'public',
    'FCPATH' => $root . 'public', 'SUPPORTPATH' => $root . 'tests/_support',
    'VENDORPATH' => $root . 'vendor', 'CIPATH' => $root . 'vendor/codeigniter4/framework',
] as $name => $path) {
    define($name, rtrim($path, '/\\') . DIRECTORY_SEPARATOR);
}
define('COMPOSER_PATH', $root . 'vendor/autoload.php');
require SYSTEMPATH . 'Boot.php';
final class LocalTestAccountsBoot extends \CodeIgniter\Boot
{
    protected static function loadDotEnv(\Config\Paths $paths): void
    {
        // Deliberately disabled. All database settings below are synthetic.
    }
}
LocalTestAccountsBoot::bootTest($paths);
define('SYNAPSE_LOCAL_TEST_ACCOUNTS_ISOLATED', true);
$config = config('Database');
$settings = [
    'DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:',
    'hostname' => '', 'username' => '', 'password' => '', 'DBPrefix' => '',
    'DBDebug' => true, 'failover' => [], 'foreignKeys' => true, 'busyTimeout' => 1000,
];
$config->default = $config->tests = $settings;
$config->defaultGroup = 'tests';
