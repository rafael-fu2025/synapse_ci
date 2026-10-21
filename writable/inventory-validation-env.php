<?php

declare(strict_types=1);

$schema = getenv('HUMUS_INVENTORY_VALIDATION_SCHEMA') ?: 'humus_inventory_20261016_validation_test';
if (!preg_match('/\Ahumus_inventory_20261016_validation(?:_full)?(?:_[0-9]+)?_test\z/', $schema)
    || in_array($schema, ['humus_db', 'humus_db_test'], true)) {
    throw new RuntimeException('Invalid disposable inventory validation schema.');
}
foreach (['CI_ENVIRONMENT' => 'testing', 'database.tests.database' => $schema] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
unset($schema, $key, $value);
