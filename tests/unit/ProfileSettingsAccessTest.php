<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ProfileSettingsController;
use App\Filters\WebCsrfFilter;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\ProfilePhotoService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\View\View;
use Config\Services;
use Psr\Log\NullLogger;
use Tests\Support\FreshDatabaseTestCase;

/** Run only with run-fresh-database-tests.py; images use an isolated temporary directory. */
final class ProfileSettingsAccessTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;
    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';
    private string $directory;
    private ProfilePhotoService $photos;
    private int $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/synapse-profile-access-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->photos = new ProfilePhotoService($this->directory . '/photos');
        $this->db->table('tenants')->insert(['slug' => uniqid('photo_'), 'name' => 'Synthetic photo tenant', 'is_active' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->tenant = (int) $this->db->insertID();
        Services::injectMock('renderer', new class(config('View'), APPPATH . 'Views/') extends View {
            public function render(string $view, ?array $options = null, ?bool $saveData = null): string
            {
                return $view === 'layouts/app' ? $this->renderSection('content') : parent::render($view, $options, $saveData);
            }
        });
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        foreach (['renderer', 'request', 'response'] as $name) Services::resetSingle($name);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($this->directory);
        parent::tearDown();
    }

    private function account(string $kind = 'student', ?int $tenant = null): array
    {
        $tenant ??= $this->tenant;
        CurrentTenant::set($tenant);
        $person = (int) (new PersonModel())->insert(['tenant_id' => $tenant, 'kind' => $kind, 'mis_id' => uniqid('photo_id_'),
            'first_name' => 'Synthetic', 'last_name' => $kind . '_' . bin2hex(random_bytes(4))]);
        $role = $kind === 'guest' ? 'student' : $kind;
        $user = (int) (new UserModel())->insert(['tenant_id' => $tenant, 'person_id' => $person, 'username' => uniqid('photo_user_'), 'role' => $role, 'status' => 'active']);
        return ['id' => $user, 'person_id' => $person, 'tenant_id' => $tenant, 'role' => $role, 'logged_in' => true];
    }

    private function login(array $actor): void
    {
        AuthenticatedUser::clear();
        session()->set(['user' => $actor, 'tenant_id' => $actor['tenant_id'], '_auth' => [
            'started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id(),
        ]]);
        self::assertNotNull((new AuthenticatedUser())->current());
    }

    private function file(): UploadedFile
    {
        $path = $this->directory . '/upload.png';
        $image = imagecreatetruecolor(50, 40); imagepng($image, $path); imagedestroy($image);
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('hasMoved')->willReturn(false);
        $file->method('getTempName')->willReturn($path);
        return $file;
    }

    private function controller(string $method = 'GET', array $post = [], ?UploadedFile $file = null, bool $ajax = true): ProfileSettingsController
    {
        $request = $this->getMockBuilder(IncomingRequest::class)->setConstructorArgs([config('App'), service('uri'), null, new UserAgent()])
            ->onlyMethods(['getFile'])->getMock();
        $request->method('getFile')->with('photo')->willReturn($file);
        $request->setMethod($method);
        $request->setGlobal('post', $post);
        if ($ajax) $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response(config('App')));
        $controller = new ProfileSettingsController($this->photos);
        $controller->initController($request, service('response'), new NullLogger());
        return $controller;
    }

    public function testStudentsAndEmployeesCanViewAndUploadOnlyTheirOwnPhoto(): void
    {
        foreach (['student', 'employee'] as $kind) {
            $actor = $this->account($kind);
            $other = $this->account($kind);
            $this->login($actor);
            $view = $this->controller()->index();
            self::assertIsString($view);
            self::assertStringContainsString('Profile picture', $view);
            $before = $this->db->table('people')->where('id', $actor['person_id'])->get()->getRowArray();
            $response = $this->controller('POST', ['tenant_id' => 999, 'person_id' => $other['person_id'], 'role' => 'superadmin', 'email' => 'forged@example.test'], $this->file())->upload();
            self::assertSame(200, $response->getStatusCode());
                        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                        self::assertTrue($payload['success']);
                        self::assertTrue($payload['has_photo']);
                        self::assertStringContainsString('/account/photo?v=', $payload['photo_url']);
                        self::assertArrayHasKey('csrf', $payload);
            self::assertTrue($this->photos->exists($actor['tenant_id'], $actor['person_id']));
            self::assertFalse($this->photos->exists($other['tenant_id'], $other['person_id']));
            self::assertSame($before, $this->db->table('people')->where('id', $actor['person_id'])->get()->getRowArray());
            $image = $this->controller()->photo();
            self::assertSame('image/webp', $image->getHeaderLine('Content-Type'));
            self::assertStringContainsString('private', $image->getHeaderLine('Cache-Control'));
                        self::assertStringContainsString('no-store', $image->getHeaderLine('Cache-Control'));
            self::assertSame(file_get_contents($this->photos->path($actor['tenant_id'], $actor['person_id'])), $image->getBody());
            $this->login($other);
            self::assertNotSame($image->getBody(), $this->controller()->photo()->getBody(), 'Another account must not receive the uploaded image.');
        }
    }

    public function testNativeUploadFallbackRedirectsToTheFixedSettingsRoute(): void
    {
        $actor = $this->account(); $this->login($actor);
        $response = $this->controller('POST', ['return_url' => 'https://example.test/unsafe'], $this->file(), false)->upload();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(site_url('portal/settings'), $response->getHeaderLine('Location'));
        self::assertTrue($this->photos->exists($actor['tenant_id'], $actor['person_id']));
    }

    public function testDefaultInitialsUseFreshFirstAndLastNamesAndReturnAfterPhotoRemoval(): void
    {
        $actor = $this->account('employee');
        $this->db->table('people')->where('id', $actor['person_id'])->update(['first_name' => 'Maria Clara', 'last_name' => 'De la Cruz']);
        $this->login($actor);
        self::assertSame('MD', session()->get('user')['avatar_initials']);
        $session = session()->get('user'); $session['avatar_initials'] = 'XX'; session()->set('user', $session);
        $default = $this->controller()->photo();
        self::assertSame('image/svg+xml', $default->getHeaderLine('Content-Type'));
        self::assertStringContainsString('>MD</text>', $default->getBody());
        self::assertStringContainsString('fill="#800000"', $default->getBody());
        self::assertStringContainsString('fill="#ffffff"', $default->getBody());
        self::assertSame('MD', session()->get('user')['avatar_initials']);
        self::assertStringContainsString('data:image/svg+xml;base64,', $this->controller()->index());
        $this->controller('POST', [], $this->file())->upload();
        self::assertSame('image/webp', $this->controller()->photo()->getHeaderLine('Content-Type'));
        $this->controller('POST')->remove();
        $removed = $this->controller()->photo();
        self::assertSame('image/svg+xml', $removed->getHeaderLine('Content-Type'));
        self::assertSame($default->getBody(), $removed->getBody());
    }

    public function testForgedSessionPersonIsRefreshedBeforeSelectingPhoto(): void
    {
        $actor = $this->account(); $other = $this->account();
        $this->login($actor);
        session()->set('user', $actor + ['name' => 'ignored']);
        $session = session()->get('user'); $session['person_id'] = $other['person_id']; session()->set('user', $session);
        $this->controller('POST', ['person_id' => $other['person_id']], $this->file())->upload();
        self::assertTrue($this->photos->exists($actor['tenant_id'], $actor['person_id']));
        self::assertFalse($this->photos->exists($other['tenant_id'], $other['person_id']));
    }

    public function testMissingGuestAndInactiveIdentitiesAreDeniedBeforeAnyStorageWrite(): void
    {
        AuthenticatedUser::clear();
        self::assertSame(401, $this->controller('POST', [], $this->file())->upload()->getStatusCode());
        $guest = $this->account('guest'); $this->login($guest);
        self::assertSame(403, $this->controller('POST', [], $this->file())->upload()->getStatusCode());
        $student = $this->account(); $this->login($student);
        $this->db->table('users')->where('id', $student['id'])->update(['status' => 'suspended']);
        self::assertSame(401, $this->controller('POST', [], $this->file())->upload()->getStatusCode());
        self::assertFalse(is_dir($this->directory . '/photos'));
    }

    public function testRemovalAndCrossTenantParametersCannotAffectAnotherPhoto(): void
    {
        $actor = $this->account();
        $this->db->table('tenants')->insert(['slug' => uniqid('photo_foreign_'), 'name' => 'Other photo tenant', 'is_active' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $foreign = $this->account('employee', (int) $this->db->insertID());
        $file = $this->file();
        foreach ([$actor, $foreign] as $account) $this->photos->save($file, $account['tenant_id'], $account['person_id']);
        $this->login($actor);
        $this->controller('POST', ['person_id' => $foreign['person_id'], 'tenant_id' => $foreign['tenant_id']])->remove();
        self::assertFalse($this->photos->exists($actor['tenant_id'], $actor['person_id']));
        self::assertTrue($this->photos->exists($foreign['tenant_id'], $foreign['person_id']));
    }

    public function testCsrfRejectsUploadBeforeTheControllerAndRoutesStayProtected(): void
    {
        $actor = $this->account(); $this->login($actor);
        $this->controller('POST', [], $this->file());
        $response = (new WebCsrfFilter())->before(service('request'));
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->photos->exists($actor['tenant_id'], $actor['person_id']));
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        self::assertStringContainsString("['auth', 'web_csrf', 'portal_host', 'web_access']", $routes);
        self::assertStringContainsString("\$routes->post('portal/settings/photo', 'ProfileSettingsController::upload')", $routes);
        self::assertStringContainsString("\$routes->post('portal/settings/photo/remove', 'ProfileSettingsController::remove')", $routes);
                self::assertStringContainsString("\$routes->get('account/photo', 'ProfileSettingsController::photo')", $routes);
    }
}
