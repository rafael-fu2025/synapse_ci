<?php

// CLI-only view renderer: no application boot, dotenv, session, database or settings files.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Common.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$options = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
function site_url(string $path): string { return 'http://127.0.0.1:47991/' . ltrim($path, '/'); }
function base_url(string $path): string { return site_url($path); }
function csrf_field(): string { return '<input type="hidden" name="csrf_fixture" value="initial-token">'; }
final class AiSettingsFixtureView
{
    public function extend(string $layout): string { return ''; }
    public function section(string $section): string { return ''; }
    public function endSection(): string { return ''; }
    public function render(array $settings, array $access): string
    {
        ob_start();
        try {
            require dirname(__DIR__, 2) . '/app/Views/admin/ai.php';
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
$settings = array_replace([
    'saved' => false, 'version' => '', 'base_url' => '', 'model' => '', 'has_api_key' => false,
    'reasoning_effort' => '', 'thinking_mode' => '', 'max_input_tokens' => 8192, 'max_output_tokens' => 2000,
    'auth_mode' => 'bearer', 'token_field' => 'max_tokens', 'structured_output' => 'none',
    'allow_private_network' => false, 'connection_tested' => false, 'tested_at' => null,
    'enabled' => false, 'storage_key_ready' => false, 'approved_units' => ['clinic'],
], $options['settings'] ?? []);
echo json_encode(['html' => (new AiSettingsFixtureView())->render($settings, $options['access'] ?? ['ready' => true, 'grants' => []]), 'settings' => $settings], JSON_THROW_ON_ERROR);
