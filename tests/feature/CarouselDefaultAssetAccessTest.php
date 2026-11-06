<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\CarouselSlideModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\Carousel\CarouselAccessPolicy;
use App\Services\Carousel\CarouselAssetStore;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The permanent default login image is governed by role.
 *
 * Nobody may edit it; only the platform owner may delete the slide that
 * carries it. Guidance, clinic and BMG roles are blocked from both, and the
 * ordinary slides they manage stay unaffected.
 *
 * @internal
 */
final class CarouselDefaultAssetAccessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private string $savedDefaultGroup;
    private string $savedStoreRoot;
    private string $storeRoot;
    private array $userIds = [];
    private array $personIds = [];
    private array $slideIds = [];
    private int $defaultSlideId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // The request path revalidates identity via the default group; bind it to the
        // migrated tests schema so fixtures and route handlers share one database.
        $this->savedDefaultGroup = config('Database')->defaultGroup;
        config('Database')->defaultGroup = 'tests';
        // Keep the managed artwork out of the live private store.
        $this->savedStoreRoot = config('Carousel')->storeRoot;
        $this->storeRoot = sys_get_temp_dir() . '/carousel-feature-' . bin2hex(random_bytes(6));
        config('Carousel')->storeRoot = $this->storeRoot;
        (new PermissionsAndRolesSeeder(config('Database')))->run();

        $this->defaultSlideId = $this->findDefaultSlideId();
    }

    protected function tearDown(): void
    {
        foreach ($this->userIds as $id) {
            (new UserModel())->delete($id, true);
        }
        foreach ($this->personIds as $id) {
            (new PersonModel())->delete($id, true);
        }
        foreach ($this->slideIds as $id) {
            (new CarouselSlideModel())->builder()->resetQuery()->where('id', $id)->delete();
        }
        $this->userIds = [];
        $this->personIds = [];
        $this->slideIds = [];
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

    private function findDefaultSlideId(): int
    {
        $row = (new CarouselSlideModel())->builder()->resetQuery()
            ->where('light_image', CarouselAssetStore::DEFAULT_LIGHT_ASSET)
            ->get()
            ->getRowArray();

        return $row === null ? 0 : (int) $row['id'];
    }

    /** Create an active user for a role, with the identity rows it needs. */
    private function createUser(string $role): int
    {
        $personId = null;
        if ($role !== 'superadmin') {
            $personId = (int) (new PersonModel())->insert([
                'tenant_id'  => 1,
                'kind'       => 'employee',
                'mis_id'     => uniqid('carousel_actor_'),
                'first_name' => 'Carousel',
                'last_name'  => 'Actor',
            ]);
            $this->personIds[] = $personId;
        }

        $id = (int) (new UserModel())->insert([
            'tenant_id' => 1,
            'person_id' => $personId,
            'username'  => uniqid('carousel_' . $role . '_'),
            'role'      => $role,
            'status'    => 'active',
        ]);
        $this->userIds[] = $id;

        return $id;
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

    private function sessionFor(int $userId, string $role): array
    {
        return [
            'user' => [
                'id'          => $userId,
                'tenant_id'   => 1,
                'name'        => 'Carousel Actor',
                'username'    => 'carousel_actor',
                'role'        => $role,
                'permissions' => [],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
            '_auth'     => $this->authMeta(),
        ];
    }

    private function slide(int $id, string $lightImage, int $active = 1): int
    {
        $slideId = (int) (new CarouselSlideModel())->insert([
            'tenant_id'   => 1,
            'title'       => 'Slide ' . $id,
            'subtitle'    => 'Subtitle',
            'light_image' => $lightImage,
            'dark_image'  => null,
            'fit_mode'    => 'cover',
            'sort_order'  => 50,
            'is_active'   => $active,
        ]);
        $this->slideIds[] = $slideId;

        return $slideId;
    }

    /**
     * A slide that carries the permanent default image.
     *
     * The protection belongs to the artwork, not to one particular row, so the
     * mutable checks work on a fixture this test owns.
     */
    private function protectedSlide(): int
    {
        return $this->slide(900, CarouselAssetStore::DEFAULT_LIGHT_ASSET);
    }

    /** Roles that must never touch the permanent default image. */
    public static function restrictedRoles(): iterable
    {
        yield 'clinic admin' => ['clinic_admin'];
        yield 'clinic staff' => ['clinic_staff'];
        yield 'guidance admin' => ['guidance_admin'];
        yield 'guidance supervisor' => ['guidance_supervisor'];
        yield 'counsellor' => ['counsellor'];
        yield 'bmg admin' => ['bmg_admin'];
        yield 'facilities operator' => ['facilities_op'];
        yield 'student' => ['student'];
    }

    /**
     * The carousel manager submits through fetch, which is the path that
     * carries the machine-readable refusal.
     *
     * @return array<string, string>
     */
    private function backgroundHeaders(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
    }

    public function testTheSeededDefaultSlideCarriesThePermanentDefaultPair(): void
    {
        $this->assertGreaterThan(0, $this->defaultSlideId, 'The permanent default slide must exist after migration.');

        $slide = (new CarouselSlideModel())->find($this->defaultSlideId);
        $this->assertSame(CarouselAssetStore::DEFAULT_LIGHT_ASSET, $slide['light_image']);
        $this->assertSame(CarouselAssetStore::DEFAULT_DARK_ASSET, $slide['dark_image']);
        $this->assertTrue(CarouselAccessPolicy::usesDefaultAsset($slide));
    }

    public function testOnlySuperadminMayDeleteTheDefaultImageSlide(): void
    {
        $superadminId = $this->createUser('superadmin');
        $slideId = $this->protectedSlide();

        $res = $this->withSession($this->sessionFor($superadminId, 'superadmin'))
            ->post('admin/carousel/' . $slideId . '/delete', $this->csrfData());

        $res->assertRedirectTo('/admin/carousel');
        $this->assertNull((new CarouselSlideModel())->find($slideId));
    }

    public function testEvenSuperadminMayNotEditTheDefaultImageSlide(): void
    {
        $superadminId = $this->createUser('superadmin');
        $slideId = $this->protectedSlide();

        $res = $this->withSession($this->sessionFor($superadminId, 'superadmin'))
            ->withHeaders($this->backgroundHeaders())
            ->post('admin/carousel/' . $slideId . '/update', $this->csrfData() + [
                'title'      => 'Hijacked',
            ]);

        $res->assertStatus(403);
        $payload = json_decode($res->getJSON(), true);
        $this->assertSame(CarouselAccessPolicy::EDIT_DENIED, $payload['message'] ?? null);

        $slide = (new CarouselSlideModel())->find($slideId);
        $this->assertSame(CarouselAssetStore::DEFAULT_LIGHT_ASSET, $slide['light_image']);
        $this->assertNotSame('Hijacked', $slide['title']);
    }

    #[DataProvider('restrictedRoles')]
    public function testRestrictedRolesCannotDeleteTheDefaultImageSlide(string $role): void
    {
        $userId = $this->createUser($role);
        $slideId = $this->protectedSlide();

        $res = $this->withSession($this->sessionFor($userId, $role))
            ->withHeaders($this->backgroundHeaders())
            ->post('admin/carousel/' . $slideId . '/delete', $this->csrfData());

        $res->assertStatus(403);
        $this->assertNotNull(
            (new CarouselSlideModel())->find($slideId),
            "Role [{$role}] must not delete the permanent default image."
        );
    }

    #[DataProvider('restrictedRoles')]
    public function testRestrictedRolesCannotEditTheDefaultImageSlide(string $role): void
    {
        $userId = $this->createUser($role);
        $slideId = $this->protectedSlide();

        $res = $this->withSession($this->sessionFor($userId, $role))
            ->withHeaders($this->backgroundHeaders())
            ->post('admin/carousel/' . $slideId . '/update', $this->csrfData() + [
                'title'      => 'Hijacked',
            ]);

        $res->assertStatus(403);
        $this->assertNotNull((new CarouselSlideModel())->find($slideId));

        $slide = (new CarouselSlideModel())->find($slideId);
        $this->assertSame(CarouselAssetStore::DEFAULT_LIGHT_ASSET, $slide['light_image']);
        $this->assertNotSame('Hijacked', $slide['title']);
    }

    #[DataProvider('restrictedRoles')]
    public function testRestrictedRolesCannotHideTheDefaultImageSlide(string $role): void
    {
        $userId = $this->createUser($role);
        $slideId = $this->protectedSlide();

        $res = $this->withSession($this->sessionFor($userId, $role))
            ->withHeaders($this->backgroundHeaders())
            ->post('admin/carousel/' . $slideId . '/toggle', $this->csrfData());

        $res->assertStatus(403);
        $this->assertSame(1, (int) (new CarouselSlideModel())->find($slideId)['is_active']);
    }

    /**
     * A plain form post is refused too, with the reason surfaced as a flash
     * notice instead of a machine-readable body.
     */
    public function testAPlainFormPostIsRefusedWithAnErrorNotice(): void
    {
        $guidanceId = $this->createUser('guidance_admin');
        $slideId = $this->protectedSlide();

        $res = $this->withSession($this->sessionFor($guidanceId, 'guidance_admin'))
            ->post('admin/carousel/' . $slideId . '/delete', $this->csrfData());

        $res->assertRedirectTo('/admin/carousel');
        $this->assertSame(CarouselAccessPolicy::DELETE_DENIED, session()->getFlashdata('error'));
        $this->assertNotNull((new CarouselSlideModel())->find($slideId));
    }

    public function testSuperadminMayHideTheDefaultImageSlide(): void
    {
        $superadminId = $this->createUser('superadmin');
        $slideId = $this->protectedSlide();

        $res = $this->withSession($this->sessionFor($superadminId, 'superadmin'))
            ->post('admin/carousel/' . $slideId . '/toggle', $this->csrfData());

        $res->assertRedirectTo('/admin/carousel');
        $this->assertSame(0, (int) (new CarouselSlideModel())->find($slideId)['is_active']);
    }

    public function testGuidanceAdminMayStillManageOrdinarySlides(): void
    {
        $userId = $this->createUser('guidance_admin');
        $slideId = $this->slide(900, 'assets/img/brand/fuel-bg.jpg');

        $res = $this->withSession($this->sessionFor($userId, 'guidance_admin'))
            ->post('admin/carousel/' . $slideId . '/update', $this->csrfData() + ['title' => 'Guidance Slide']);

        $res->assertRedirectTo('/admin/carousel');
        $this->assertSame('Guidance Slide', (new CarouselSlideModel())->find($slideId)['title']);

        $res = $this->withSession($this->sessionFor($userId, 'guidance_admin'))
            ->post('admin/carousel/' . $slideId . '/delete', $this->csrfData());

        $res->assertRedirectTo('/admin/carousel');
        $this->assertNull((new CarouselSlideModel())->find($slideId));
    }

    public function testTheEditFormShowsTheDefaultImageAsLocked(): void
    {
        $superadminId = $this->createUser('superadmin');

        $res = $this->withSession($this->sessionFor($superadminId, 'superadmin'))
            ->get('admin/carousel/' . $this->defaultSlideId . '/edit');

        $res->assertStatus(200);
        $body = $res->getBody();
        $this->assertStringContainsString('Permanent default image.', $body);
        $this->assertStringContainsString('id="light_file" accept="image/jpeg,image/png,image/webp,image/gif" class="d-none" disabled', $body);
        $this->assertStringNotContainsString('name="remove_dark"', $body);
    }

    public function testTheManagerMarksTheDefaultSlideAsProtected(): void
    {
        $guidanceId = $this->createUser('guidance_admin');

        $res = $this->withSession($this->sessionFor($guidanceId, 'guidance_admin'))->get('admin/carousel');

        $res->assertStatus(200);
        $body = $res->getBody();
        $this->assertStringContainsString('Default image &middot; protected', $body);
        $this->assertStringContainsString('Only a superadmin may delete the permanent default image', $body);
    }

    public function testTheLoginPagePublishesThePermanentDefaultPair(): void
    {
        $res = $this->get('auth/login');

        $res->assertStatus(200);
        $body = $res->getBody();
        $this->assertStringContainsString(
            base_url(CarouselAssetStore::DEFAULT_LIGHT_ASSET),
            $body,
            'The login carousel must publish the managed light default image.'
        );
        $this->assertStringContainsString(
            base_url(CarouselAssetStore::DEFAULT_DARK_ASSET),
            $body,
            'The login carousel must publish the managed dark default image.'
        );
    }

    /** The permanent default pair, by slot, with the format the route must serve. */
    public static function defaultArtwork(): iterable
    {
        yield 'light' => ['light', 'image/jpeg'];
        yield 'dark' => ['dark', 'image/png'];
    }

    #[DataProvider('defaultArtwork')]
    public function testTheDefaultArtworkIsPublishedThroughTheAuthorisedRoute(string $slot, string $mime): void
    {
        $store = new CarouselAssetStore();
        $this->assertTrue($store->ensureDefaultAssets());

        $res = $this->get($store->defaultAssets()[$slot]);

        $res->assertStatus(200);
        $this->assertStringContainsString($mime, $res->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('nosniff', $res->response()->getHeaderLine('X-Content-Type-Options'));

        // TestResponse forwards unknown methods to its DOM parser, so read the
        // raw bytes from the response rather than the parsed document.
        $body = (string) $res->response()->getBody();
        $this->assertNotSame('', $body);
        $info = @getimagesizefromstring($body);
        $this->assertNotFalse($info, 'The route must return the stored image bytes.');
        $this->assertSame($mime, $info['mime'] ?? null);
    }

    public function testThePublishingRouteRefusesAnythingOutsideTheStore(): void
    {
        foreach ([
            'uploads/carousel/default/missing.jpg',
            'uploads/carousel/1/missing.jpg',
            'uploads/carousel/default/private.php',
            'uploads/carousel/default/banner.svg',
        ] as $path) {
            $this->get($path)->assertStatus(404);
        }
    }

    public function testThePublishingRouteRejectsTraversalBeforeItReachesTheController(): void
    {
        // The router never matches a traversal segment, so the request cannot
        // address a file outside the managed store.
        $this->expectException(PageNotFoundException::class);

        $this->get('uploads/carousel/default/../../secret.txt');
    }
}
