<?php
// Read raw old input so each output is escaped exactly once, including hidden keys.
$value = static function (string $field, mixed $default = ''): string {
    $input = old($field, $default, false);
    return is_string($input) || is_int($input) ? (string) $input : '';
};
