<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /** CI's session factory, with the fail-closed regeneration implementation. */
    public static function session(?Session $config = null, bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('session', $config);
        }
        $config ??= config(Session::class);
        $driverName = $config->driver;
        if ($driverName === \CodeIgniter\Session\Handlers\DatabaseHandler::class) {
            $platform = Database::connect($config->DBGroup ?? config(Database::class)->defaultGroup)->getPlatform();
            $driverName = match ($platform) {
                'MySQLi' => \CodeIgniter\Session\Handlers\MySQLiHandler::class,
                'Postgre' => \CodeIgniter\Session\Handlers\PostgreHandler::class,
                default => throw new \InvalidArgumentException('Unsupported database session platform.'),
            };
        }
        if (!is_a($driverName, \CodeIgniter\Session\Handlers\BaseHandler::class, true)) {
            throw new \InvalidArgumentException('Invalid session handler.');
        }
        $logger = service('logger');
        $driver = new $driverName($config, service('request')->getIPAddress());
        $driver->setLogger($logger);
        $session = new \App\Services\AuthenticatedSession($driver, $config);
        $session->setLogger($logger);
        if (session_status() === PHP_SESSION_NONE) {
            service('response')->removeHeader('Cache-Control');
            $session->start();
        }
        return $session;
    }

    public static function loginRateLimiter(bool $getShared = true): \App\Services\LoginRateLimiter
    {
        if ($getShared) {
            return static::getSharedInstance('loginRateLimiter');
        }
        return new \App\Services\LoginRateLimiter();
    }

    public static function misClient(bool $getShared = true): \App\Integrations\Mis\MisClientInterface
    {
        if ($getShared) {
            return static::getSharedInstance('misClient');
        }

        $enabled = filter_var(env('FUMIS_ENABLED', false), FILTER_VALIDATE_BOOL);
        if ($enabled) {
            return new \App\Integrations\Mis\HttpMisClient();
        }

        $fakeEnabled = filter_var(env('FUMIS_FAKE_ENABLED', false), FILTER_VALIDATE_BOOL);
        if ($fakeEnabled && in_array(ENVIRONMENT, ['development', 'testing'], true)) {
            return new \App\Integrations\Mis\FakeMisClient();
        }

        return new \App\Integrations\Mis\DisabledMisClient();
    }
}
