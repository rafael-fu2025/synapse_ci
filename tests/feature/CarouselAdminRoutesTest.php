<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CarouselSlideModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

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

    private function adminSession(): array
    {
        return [
            'user' => [
                'id'          => 900001,
                'name'        => 'Carousel Admin',
                'username'    => 'carousel_admin',
                'role'        => 'superadmin',
                'permissions' => ['rbac.manage'],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
        ];
    }

    private function studentSession(): array
    {
        return [
            'user' => [
                'id'          => 900002,
                'name'        => 'Regular Student',
                'username'    => 'student_one',
                'role'        => 'student',
                'permissions' => [],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
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
        $res->assertSee('Carousel Slides');
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

        $res = $this->withSession($this->adminSession())->post('admin/carousel/' . $id . '/toggle');
        $res->assertRedirectTo('/admin/carousel');

        $model = new CarouselSlideModel();
        $this->assertSame(0, (int) $model->find($id)['is_active']);
    }

    public function testAdminCanDeleteSlide(): void
    {
        $id = $this->seedSlide('Delete Me');

        $res = $this->withSession($this->adminSession())->post('admin/carousel/' . $id . '/delete');
        $res->assertRedirectTo('/admin/carousel');

        $model = new CarouselSlideModel();
        $this->assertNull($model->find($id));
    }

    public function testAdminCanReorderSlides(): void
    {
        $first  = $this->seedSlide('First Slide', 'cover', 1);
        $second = $this->seedSlide('Second Slide', 'cover', 1);

        // Reverse the order: second, first
        $res = $this->withSession($this->adminSession())->post('admin/carousel/reorder', [
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

    public function testUpdatePersistsSlideEdits(): void
    {
        $id = $this->seedSlide('Old Title', 'cover', 1);

        $res = $this->withSession($this->adminSession())->post('admin/carousel/' . $id . '/update', [
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
        $this->assertSame('assets/img/brand/health-light.png', $slide['light_image']);
        $this->assertSame('assets/img/brand/health-dark.png', $slide['dark_image']);
    }

    public function testUpdateCanRemoveDarkImage(): void
    {
        $id = $this->seedSlide('Keep Light Only', 'cover', 1);
        (new CarouselSlideModel())->update($id, ['dark_image' => 'assets/img/brand/guidance-dark.png']);

        $this->withSession($this->adminSession())->post('admin/carousel/' . $id . '/update', [
            'remove_dark' => '1',
            'fit_mode'    => 'cover',
        ]);

        $this->assertNull((new CarouselSlideModel())->find($id)['dark_image']);
    }

    public function testUpdateKeepsExistingImageWhenNoUploadOrPathSupplied(): void
    {
        $id = $this->seedSlide('Stable Image', 'cover', 1);

        $this->withSession($this->adminSession())->post('admin/carousel/' . $id . '/update', [
            'title' => 'Renamed Only',
        ]);

        $slide = (new CarouselSlideModel())->find($id);
        $this->assertSame('Renamed Only', $slide['title']);
        $this->assertSame('assets/img/brand/fuel-bg.jpg', $slide['light_image']);
    }

    public function testUpdateReturnsJsonForAjaxRequests(): void
    {
        $id = $this->seedSlide('Ajax Slide', 'cover', 1);

        $res = $this->withSession($this->adminSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('admin/carousel/' . $id . '/update', [
                'title' => 'Ajax Updated',
            ]);

        $res->assertStatus(200);
        $res->assertJSONFragment(['success' => true]);
    }

    /**
         * Saving must land back on the slide manager rather than bouncing the admin
         * into the standalone edit screen, and the confirmation has to survive the
         * client-side navigation that follows the upload XHR.
         */
        public function testAjaxSaveReturnsToManagerWithConfirmation(): void
        {
            $res = $this->withSession($this->adminSession())
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post('admin/carousel/store', [
                    'title'      => 'Manager Landing Slide',
                    'light_path' => 'assets/img/brand/fuel-bg.jpg',
                    'fit_mode'   => 'cover',
                ]);

            $res->assertStatus(200);
            $payload = json_decode($res->getJSON(), true);

            $this->assertTrue($payload['success']);
            $this->assertSame(site_url('/admin/carousel'), $payload['redirect']);

            // The success notice must survive the navigation the browser performs
            // after the XHR resolves.
            $this->assertSame('Carousel slide created successfully.', session()->getFlashdata('success'));

            // Carry the POST session (including flash metadata) into the GET.
            $manager = $this->withSession()->withHeaders([])->get('admin/carousel');
            $manager->assertStatus(200);
            $manager->assertSee('Carousel slide created successfully.');
            $manager->assertSee('Manager Landing Slide');
            $body = $manager->getBody();
            // The manager page now lists slides and links to a separate modal
            // for adding new ones, instead of rendering the form inline.
            $this->assertStringContainsString('href="' . site_url('/admin/carousel/create') . '"', $body);
            $this->assertStringNotContainsString('action="' . site_url('/admin/carousel/store') . '"', $body);
        }

        public function testAjaxUpdateReturnsToManagerWithConfirmation(): void
        {
            $id = $this->seedSlide('Update Landing', 'cover', 1);

            $res = $this->withSession($this->adminSession())
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post('admin/carousel/' . $id . '/update', ['title' => 'Updated Landing']);

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
                ->post('admin/carousel/' . $id . '/update', ['title' => str_repeat('x', 151)]);

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
    }

    public function testAjaxValidationReturnsErrorAndFreshCsrfToken(): void
    {
        $id = $this->seedSlide('Keep Me');
        $res = $this->withSession($this->adminSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('admin/carousel/' . $id . '/update', ['title' => str_repeat('x', 151)]);
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
        $res = $this->withSession($this->studentSession())->post('admin/carousel/' . $id . '/update', ['title' => 'Changed']);
        $res->assertRedirectTo('/');
        $this->assertSame('Protected Slide', (new CarouselSlideModel())->find($id)['title']);
    }

    public function testStoreRequiresALightImage(): void
    {
        $res = $this->withSession($this->adminSession())->post('admin/carousel/store', [
            'title' => 'Missing Image',
        ]);

        $res->assertRedirectTo('/admin/carousel');
        $this->assertSame(0, (new CarouselSlideModel())->where('title', 'Missing Image')->countAllResults());
    }

    public function testStoreReturnsJsonForAjaxRequests(): void
    {
        $res = $this->withSession($this->adminSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('admin/carousel/store', [
                'title'       => 'Lib Path Slide',
                'light_path'  => 'assets/img/brand/fuel-bg.jpg',
                'fit_mode'    => 'cover',
            ]);

        $res->assertStatus(200);
        $res->assertJSONFragment(['success' => true]);
    }
}
