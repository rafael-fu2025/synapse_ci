<?php

// Inspect defaults without booting the application or loading environment files.
define('ENVIRONMENT', $argv[1]);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Config/BaseConfig.php';
require dirname(__DIR__, 2) . '/app/Config/Cookie.php';
require dirname(__DIR__, 2) . '/app/Config/App.php';
echo json_encode([
    (new ReflectionClass(Config\Cookie::class))->getDefaultProperties()['secure'],
    (new ReflectionClass(Config\App::class))->getDefaultProperties()['forceGlobalSecureRequests'],
]);

if (isset($argv[2])) {
    // Exercise real BaseConfig environment handling without loading .env or
    // discovering registrars, whose services need a full application bootstrap.
    require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Modules/Modules.php';
    require dirname(__DIR__, 2) . '/app/Config/Modules.php';
    $modules = new Config\Modules();
    $modules->enabled = false;
    (new ReflectionProperty(CodeIgniter\Config\BaseConfig::class, 'moduleConfig'))->setValue(null, $modules);
    foreach (['cookie.secure', 'app.forceGlobalSecureRequests'] as $key) {
        $_ENV[$key] = $_SERVER[$key] = $argv[2];
        putenv($key . '=' . $argv[2]);
    }
    echo json_encode([(new Config\Cookie())->secure, (new Config\App())->forceGlobalSecureRequests]);
}
