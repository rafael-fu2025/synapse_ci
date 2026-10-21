<?php

// A separate PHP process is necessary to exercise FileHandler's real flock.
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$config = config('Session');
$config->savePath = $argv[1];
$handler = new CodeIgniter\Session\Handlers\FileHandler($config, '127.0.0.1');
$handler->setLogger(service('logger'));
session_set_save_handler($handler, true);
ini_set('session.use_strict_mode', '1');
session_name('synapse_security_test');
session_id($argv[2]);
file_put_contents($argv[1] . '/waiting', 'ready');
session_start();
echo json_encode([
    'authenticated' => isset($_SESSION['user']) || isset($_SESSION['_auth']) || isset($_SESSION['tenant_id']),
    'strict_mode' => ini_get('session.use_strict_mode') === '1',
    'same_id' => session_id() === $argv[2],
]);
session_write_close();
