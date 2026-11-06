<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CarouselSlideModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\Carousel\CarouselAssetStore;
use App\Database\Seeds\PermissionsAndRolesSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Covers admin-only management of the login carousel and its multi-tenant scope.
 *
 * @internal
 */
final class CarouselAdminRoutesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private string $savedDefaultGroup;
    private string $savedStoreRoot;
    private string $storeRoot;
    private int $adminId = 0;
    private int $studentId = 0;
    private int $studentPersonId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // The request path revalidates identity via the default group; bind it to the
        // migrated tests schema so fixtures and route handlers share one database.
        $this->savedDefaultGroup = config('Database')->defaultGroup;
        config('Database')->defaultGroup = 'tests';
        // Keep the managed artwork out of the live private store.
        $this->savedStoreRoot = config('Carousel')->storeRoot;
        $this->storeRoot = sys_get_temp_dir() . '/carousel-routes-' . bin2hex(random_bytes(6));
        config('Carousel')->storeRoot = $this->storeRoot;
        (new PermissionsAndRolesSeeder(config('Database')))->run();
        // Session identity is revalidated from the database; the fixture needs real rows.
        $this->adminId = (int) (new UserModel())->insert([
            'tenant_id' => 1,
            'username'  => uniqid('carousel_admin_'),
            'role'      => 'superadmin',
            'status'    => 'active',
        ]);
        $this->studentPersonId = (int) (new PersonModel())->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => uniqid('carousel_student_'),
            'first_name' => 'Carousel',
            'last_name'  => 'Student',
        ]);
        $this->studentId = (int) (new UserModel())->insert([
            'tenant_id'  => 1,
            'person_id'  => $this->studentPersonId,
            'username'   => uniqid('carousel_student_'),
            'role'       => 'student',
            'status'     => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        (new UserModel())->delete($this->adminId, true);
        (new UserModel())->delete($this->studentId, true);
        (new PersonModel())->delete($this->studentPersonId, true);
        config('Carousel')->storeRoot = $this->savedStoreRoot;
        $this->removeTree($this->storeRoot);
        config('Database')->defaultGroup = $this->savedDefaultGroup;
        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }

    /** AuthenticatedUser::current() requires _auth metadata bound to this session id. */
    private function authMeta(): array
    {
        return ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()];
    }

    /** Valid CSRF pair (cookie + token), as a real browser session would present. */
    private function csrfData(): array
    {
        $hash = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
        Services::resetSingle('security');
        return [config('Security')->tokenName => $hash];
    }

    private function adminSession(): array
    {
        return [
            'user' => [
                'id'          => $this->adminId,
                'tenant_id'   => 1,
                'name'        => 'Carousel Admin',
                'username'    => 'carousel_admin',
                'role'        => 'superadmin',
                'permissions' => ['rbac.manage'],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
            '_auth'     => $this->authMeta(),
        ];
    }

    private function studentSession(): array
    {
        return [
            'user' => [
                'id'          => $this->studentId,
                'tenant_id'   => 1,
                'name'        => 'Regular Student',
                'username'    => 'student_one',
                'role'        => 'student',
                'permissions' => [],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
            '_auth'     => $this->authMeta(),
        ];
    }

    private function seedSlide(string $title = 'Test Slide', string $fitMode = 'cover', int $active = 1): int
    {
        $model = new CarouselSlideModel();

        return (int) $model->insert([
            'tenant_id'   => 1,
            'title'       => $title,
            'subtitle'    => 'Subtitle here',
            'light_image' => 'assets/img/brand/fuel-bg.jpg',
            'dark_image'  => null,
            'fit_mode'    => $fitMode,
            'sort_order'  => 50,
            'is_active'   => $active,
        ]);
    }

    public function testAdminCanViewCarouselManager(): void
    {
        $this->seedSlide('University Health Services');

        $res = $this->withSession($this->adminSession())->get('admin/carousel');

        $res->assertStatus(200);
        $res->assertSee('Drag rows or use Move up / Move down to reorder');
        $res->assertSee('University Health Services');
        $res->assertSee('Add Carousel Slide');
    }

    public function testStudentIsDeniedCarouselManager(): void
    {
        $res = $this->withSession($this->studentSession())->get('admin/carousel');

        // AuthFilter redirects to dashboard for insufficient permission.
        $res->assertRedirect();
        $res->assertRedirectTo('/');
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $res = $this->get('admin/carousel');

        $res->assertRedirectTo('/auth/login');
    }

    public function testAdminCanToggleSlideActiveState(): void
    {
        $id = $this->seedSlide('Toggle Me', 'cover', 1);

        $res = $this->withSession($this->adminSession())->post('admin/carousel/' . $id . '/toggle', $this->csrfData());
        $res->assertRedirectTo('/admin/carousel');

        $model = new CarouselSlideModel();
        $this->assertSame(0, (int) $model->find($id)['is_active']);
    }

    public function testAdminCanDeleteSlide(): void
    {
        $id = $this->seedSlide('Delete Me');

        $res = $this->withSession($this->adminSession())->post('admin/carousel/' . $id . '/delete', $this->csrfData());
        $res->assertRedirectTo('/admin/carousel');

        $model = new CarouselSlideModel();
        $this->assertNull($model->find($id));
    }

    public function testAdminCanReorderSlides(): void
    {
        $first  = $this->seedSlide('First Slide', 'cover', 1);
        $second = $this->seedSlide('Second Slide', 'cover', 1);

        // Reverse the order: second, first
        $res = $this->withSession($this->adminSession())->post('admin/carousel/reorder', $this->csrfData() + [
            'order' => $second . ',' . $first,
        ]);
        $res->assertRedirectTo('/admin/carousel');

        $model = new CarouselSlideModel();
        $this->assertLessThan(
            (int) $model->find($first)['sort_order'],
            (int) $model->find($second)['sort_order'],
            'Second slide should now sort before the first.'
        );
    }

    public function testLoginPageRendersDatabaseSlides(): void
    {
        $this->seedSlide('Clinic Health Desk', 'contain_blur', 1);

        $res = $this->get('auth/login');

        $res->assertStatus(200);
        $res->assertSee('Clinic Health Desk');

        $body = $res->getBody();
        // contain_blur slides must include the blurred backdrop layer class.
        $this->assertStringContainsString('auth-slide-img-bg synapse-logo-dark', $body);
        $this->assertMatchesRegularExpression(
            '/\[data-theme="dark"\] #loginPhotoCarousel \.auth-slide-img-bg\s*\{\s*filter: blur\(22px\)/',
            $body
        );
        // The seeded cover slide must render the crisp, non-blurred class.
        $this->assertStringContainsString('auth-slide-img-cover', $body);
    }

    public function testEditPageRendersExistingSlide(): void
    {
        $id = $this->seedSlide('Editable Slide', 'contain_blur', 1);

        $res = $this->withSession($this->adminSession())->get('admin/carousel/' . $id . '/edit');

        $res->assertStatus(200);
        $res->assertSee('Edit Carousel Slide');
        $res->assertSee('Editable Slide');
        $res->assertSee('Subtitle here');
    }

    public function testUpdatePersistsSlideEditsWithoutAcceptingImagePaths(): void
    {
        $id = $this->seedSlide('Old Title', 'cover', 1);
        $before = (new CarouselSlideModel())->find($id);

        $res = $this->withSession($this->adminSession())->post('admin/carousel/' . $id . '/update', $this->csrfData() + [
            'title'       => 'New Title',
            'subtitle'    => 'New subtitle',
            'fit_mode'    => 'contain_blur',
            'light_path'  => 'assets/img/brand/health-light.png',
            'dark_path'   => 'assets/img/brand/health-dark.png',
        ]);

        $res->assertRedirectTo('/admin/carousel');

        $model = new CarouselSlideModel();
        $slide = $model->find($id);
        $this->assertSame('New Title', $slide['title']);
        $this->assertSame('New subtitle', $slide['subtitle']);
        $this->assertSame('contain_blur', $slide['fit_mode']);
        // Operator-supplied paths are ignored; authoritative artwork comes from the row.
        $this->assertStringStartsWith('uploads/carousel/1/imported_', $slide['light_image']);
        $this->assertSame($before['dark_image'], $slide['dark_image']);
    }

    public function testUpdateCanRemoveDarkImage(): void
    {
        $id = $this->seedSlide('Keep Light Only', 'cover', 1);
        (new CarouselSlideModel())->update($id, ['dark_image' => 'assets/img/brand/guidance-dark.png']);

        $this->withSession($this->adminSession())->post('admin/carousel/' . $id . '/update', $this->csrfData() + [
            'remove_dark' => '1',
            'fit_mode'    => 'cover',
        ]);

        $this->assertNull((new CarouselSlideModel())->find($id)['dark_image']);
    }

    public function testUpdateKeepsExistingImagesWhenNoUploadOrPathSupplied(): void
    {
        $id = $this->seedSlide('Stable Image', 'cover', 1);

        $this->withSession($this->adminSession())->post('admin/carousel/' . $id . '/update', $this->csrfData() + [
            'title' => 'Renamed Only',
        ]);

        $slide = (new CarouselSlideModel())->find($id);
        $this->assertSame('Renamed Only', $slide['title']);
        // The artwork is retained and now held in the managed store.
        $this->assertStringStartsWith('uploads/carousel/1/imported_', $slide['light_image']);
        $this->assertNotNull((new CarouselAssetStore())->absolute($slide['light_image']));
    }

    public function testUpdateReturnsJsonForAjaxRequests(): void
    {
        $id = $this->seedSlide('Ajax Slide', 'cover', 1);

        $res = $this->withSession($this->adminSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('admin/carousel/' . $id . '/update', $this->csrfData() + [
                'title' => 'Ajax Updated',
            ]);

        $res->assertStatus(200);
        $res->assertJSONFragment(['success' => true]);
    }

        public function testAjaxUpdateReturnsToManagerWithConfirmation(): void
        {
            $id = $this->seedSlide('Update Landing', 'cover', 1);

            $res = $this->withSession($this->adminSession())
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post('admin/carousel/' . $id . '/update', $this->csrfData() + ['title' => 'Updated Landing']);

            $res->assertStatus(200);
            $payload = json_decode($res->getJSON(), true);

            $this->assertTrue($payload['success']);
            $this->assertSame(site_url('/admin/carousel'), $payload['redirect']);
            $this->assertSame('Carousel slide updated successfully.', session()->getFlashdata('success'));

            $manager = $this->withSession()->withHeaders([])->get('admin/carousel');
            $manager->assertStatus(200);
            $manager->assertSee('Carousel slide updated successfully.');
            $manager->assertSee('Updated Landing');
            // The list page no longer carries the form; the add button is the
            // only call to action.
            $this->assertStringContainsString('href="' . site_url('/admin/carousel/create') . '"', $manager->getBody());
        }

        /**
         * A failed save stays on the form so the admin can correct it; no success
         * notice may be queued for the next page they open.
         */
        public function testAjaxFailureDoesNotQueueSuccessNotice(): void
        {
            $id = $this->seedSlide('Failure Landing', 'cover', 1);

            $res = $this->withSession($this->adminSession())
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post('admin/carousel/' . $id . '/update', $this->csrfData() + ['title' => str_repeat('x', 151)]);

            $res->assertStatus(422);
            $payload = json_decode($res->getJSON(), true);

            $this->assertFalse($payload['success']);
            $this->assertNull(session()->getFlashdata('success'));
        }

        public function testEditorIncludesSavedPreviewsAndUploadProgress(): void
    {
        $id = $this->seedSlide('Preview Slide');
        (new CarouselSlideModel())->update($id, ['dark_image' => 'assets/img/brand/guidance-dark.png']);
        $res = $this->withSession($this->adminSession())->get('admin/carousel/' . $id . '/edit');
        $res->assertStatus(200);
        $body = $res->getBody();
        $this->assertStringContainsString('data-current="' . base_url('assets/img/brand/fuel-bg.jpg') . '"', $body);
        $this->assertStringContainsString('data-current="' . base_url('assets/img/brand/guidance-dark.png') . '"', $body);
        $this->assertStringContainsString('xhr.upload.addEventListener', $body);
        $this->assertStringContainsString('URL.createObjectURL', $body);
        $this->assertStringContainsString('role="progressbar"', $body);
        $this->assertStringContainsString('Save Changes', $body);
        $this->assertStringNotContainsString('name="light_path"', $body);
        $this->assertStringNotContainsString('name="dark_path"', $body);
    }

    public function testAjaxValidationReturnsErrorAndFreshCsrfToken(): void
    {
        $id = $this->seedSlide('Keep Me');
        $res = $this->withSession($this->adminSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('admin/carousel/' . $id . '/update', $this->csrfData() + ['title' => str_repeat('x', 151)]);
        $res->assertStatus(422);
        $payload = json_decode($res->getJSON(), true);
        $this->assertFalse($payload['success']);
        $this->assertNotEmpty($payload['csrf']['hash']);
        $this->assertSame('Keep Me', (new CarouselSlideModel())->find($id)['title']);
    }

    public function testStudentCannotEditSlide(): void
    {
        $id = $this->seedSlide('Protected Slide');
        $res = $this->withSession($this->studentSession())->get('admin/carousel/' . $id . '/edit');
        $res->assertRedirectTo('/');
        $res = $this->withSession($this->studentSession())->post('admin/carousel/' . $id . '/update', $this->csrfData() + ['title' => 'Changed']);
        // Rejected mutations are never redirected; the browser gets 403 JSON instead.
        $res->assertStatus(403);
        $payload = json_decode($res->getJSON(), true);
        $this->assertSame('You do not have permission to access that resource.', $payload['message'] ?? null);
        $this->assertSame('Protected Slide', (new CarouselSlideModel())->find($id)['title']);
    }

    public function testStoreRequiresALightImage(): void
    {
        $res = $this->withSession($this->adminSession())->post('admin/carousel/store', $this->csrfData() + [
            'title' => 'Missing Image',
        ]);

        $res->assertRedirectTo('/admin/carousel');
        $this->assertSame(0, (new CarouselSlideModel())->where('title', 'Missing Image')->countAllResults());
    }

    public function testStoreRequiresALightImageUpload(): void
    {
        $res = $this->withSession($this->adminSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('admin/carousel/store', $this->csrfData() + [
                'title'      => 'Path Only',
                'light_path' => 'assets/img/brand/fuel-bg.jpg',
            ]);

        $res->assertStatus(422);
        $res->assertJSONFragment([
            'success' => false,
            'message' => 'A light mode image upload is required.',
        ]);
        $this->assertSame(0, (new CarouselSlideModel())->where('title', 'Path Only')->countAllResults());
    }
}
