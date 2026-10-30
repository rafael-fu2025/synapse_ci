<?php

declare(strict_types=1);

// Windows subprocess environments can drop dotted variable names. Transfer only
// this test's ephemeral realtime settings under one ordinary environment key,
// then seed BaseConfig's highest-priority source before Spark/dotenv boots.
$settings = json_decode((string) getenv('HUMUS_REALTIME_TEST_CONFIG'), true, 32, JSON_THROW_ON_ERROR);
foreach ($settings as $name => $value) {
    if (is_scalar($value)) {
        $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $_ENV['realtime.' . $name] = $_SERVER['realtime.' . $name] = $value;
    }
}
unset($settings, $name, $value);
