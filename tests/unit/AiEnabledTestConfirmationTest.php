<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\DeploymentControl;
use App\Services\Ai\Profile;
use App\Services\Ai\SettingsStore;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Ai;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;

require_once __DIR__ . '/AiSettingsHttpTest.php';

/** Disposable files and synthetic probe only; no dotenv, database or provider. */
final class AiEnabledTestConfirmationTest extends CIUnitTestCase
{
    private string $directory;
    private SettingsStore $store;
    private DeploymentControl $control;
    private string $version;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        $this->directory = sys_get_temp_dir() . '/humus-ai-confirmation-' . bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->store = new SettingsStore($this->directory . '/settings', (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor());
        $this->control = new DeploymentControl($this->directory . '/control.json', static fn (): string => str_repeat('a', 64));
        $saved = $this->store->save(17, 23, [
            'version' => '', 'base_url' => 'https://synthetic.example.test/v1', 'api_key' => 'synthetic-only-key',
            'remove_api_key' => '0', 'model' => 'synthetic', 'reasoning_effort' => '', 'thinking_mode' => '',
            'max_input_tokens' => '16384', 'max_output_tokens' => '1024', 'auth_mode' => 'bearer',
            'token_field' => 'max_tokens', 'structured_output' => 'none', 'allow_private_network' => '0',
        ]);
        $this->version = $saved['version'];
        $profile = $this->store->testingProfile(17, $this->version);
        $this->store->markTested(17, 23, $this->version, $profile->revision());
        $this->control->resume($this->store->enable(17, 23, $this->version), 17);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function controller(array $post, string $method = 'POST'): AiSettingsHttpFixtureController
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $request->removeHeader(csrf_header());
        $request->setGlobal('get', []);
        $request->setGlobal('post', [csrf_token() => csrf_hash()] + $post);
        Services::injectMock('request', $request);
        $response = new Response(config('App'));
        Services::injectMock('response', $response);
        $controller = new AiSettingsHttpFixtureController();
        $controller->fixtureStore = $this->store;
        $controller->fixtureControl = $this->control;
        $controller->probeDirectory = $this->directory;
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    public static function unconfirmed(): iterable
    {
        yield 'missing' => [[]];
        yield 'false' => [['confirm_pause' => '0']];
        yield 'array' => [['confirm_pause' => ['1']]];
    }

    #[DataProvider('unconfirmed')]
    public function testEnabledTestRequiresConfirmationBeforeAnyPauseOrWrite(array $confirmation): void
    {
        $before = file_get_contents($this->directory . '/settings/provider.enc');
        $controlBefore = file_get_contents($this->directory . '/control.json');
        $controller = $this->controller(['version' => $this->version] + $confirmation);
        $response = $controller->test();
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('pause_confirmation_required', json_decode($response->getBody(), true)['reason']);
        self::assertSame($before, file_get_contents($this->directory . '/settings/provider.enc'));
        self::assertSame($controlBefore, file_get_contents($this->directory . '/control.json'));
        self::assertNotContains('probe', $controller->events);
        $this->control->assertAllowed(Profile::fromConfig($this->store->configuration()), 17);
    }

    public function testOmittingVersionCannotBypassManagedPauseConfirmation(): void
    {
        $before = file_get_contents($this->directory . '/settings/provider.enc');
        $controlBefore = file_get_contents($this->directory . '/control.json');
        $controller = $this->controller([]);
        $response = $controller->test();
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('settings_not_saved', json_decode($response->getBody(), true)['reason']);
        self::assertSame($before, file_get_contents($this->directory . '/settings/provider.enc'));
        self::assertSame($controlBefore, file_get_contents($this->directory . '/control.json'));
        self::assertNotContains('probe', $controller->events);
        $this->control->assertAllowed(Profile::fromConfig($this->store->configuration()), 17);
    }

    public static function outcomes(): iterable
    {
        yield 'success' => [true];
        yield 'failure' => [false];
    }

    #[DataProvider('outcomes')]
    public function testConfirmedTestStaysPausedAndGetReconcilesWithoutWrites(bool $success): void
    {
        $controller = $this->controller(['version' => $this->version, 'confirm_pause' => '1']);
        $controller->result = ['success' => $success, 'verified' => $success];
        $response = $controller->test();
        self::assertSame($success ? 200 : 503, $response->getStatusCode());
        self::assertContains('probe', $controller->events);
        self::assertFalse($this->store->summary(17)['enabled']);
        self::assertTrue(json_decode(file_get_contents($this->directory . '/control.json'), true)['paused']);
        $before = file_get_contents($this->directory . '/settings/provider.enc');
        $status = $this->controller([], 'GET');
        $data = json_decode($status->status()->getBody(), true);
        self::assertTrue($data['success']);
        self::assertFalse($data['settings']['enabled']);
        self::assertSame($before, file_get_contents($this->directory . '/settings/provider.enc'));
        self::assertNotContains('probe', $status->events);
        self::assertStringNotContainsString('synthetic-only-key', $response->getBody());
    }

    public function testStaleConfirmedTestDoesNotPauseCurrentEnabledConfiguration(): void
    {
        $controller = $this->controller(['version' => str_repeat('0', 32), 'confirm_pause' => '1']);
        $response = $controller->test();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('settings_conflict', json_decode($response->getBody(), true)['reason']);
        self::assertNotContains('probe', $controller->events);
        $this->control->assertAllowed(Profile::fromConfig($this->store->configuration()), 17);
    }
}
