<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Carousel\CarouselAccessPolicy;
use App\Services\Carousel\CarouselAssetStore;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The permanent default image may not be edited by anyone and may only be
 * deleted by the platform owner. Guidance, clinic and BMG roles are held to
 * the same rule.
 *
 * @internal
 */
final class CarouselAccessPolicyTest extends CIUnitTestCase
{
    private const DEFAULT_SLIDE = [
        'id'          => 1,
        'light_image' => CarouselAssetStore::DEFAULT_LIGHT_ASSET,
        'dark_image'  => CarouselAssetStore::DEFAULT_DARK_ASSET,
    ];

    private const ORDINARY_SLIDE = [
        'id'          => 2,
        'light_image' => 'uploads/carousel/1/slide_light_abc.jpg',
        'dark_image'  => null,
    ];

    private function actor(string $role, array $permissions = []): array
    {
        return ['role' => $role, 'permissions' => $permissions];
    }

    /** Roles that can reach the carousel manager at all. */
    public static function restrictedRoles(): iterable
    {
        yield 'clinic admin' => ['clinic_admin', ['rbac.manage']];
        yield 'clinic staff' => ['clinic_staff', []];
        yield 'guidance admin' => ['guidance_admin', ['rbac.manage']];
        yield 'guidance supervisor' => ['guidance_supervisor', []];
        yield 'counsellor' => ['counsellor', []];
        yield 'bmg admin' => ['bmg_admin', []];
        yield 'facilities operator' => ['facilities_op', []];
        yield 'student' => ['student', []];
    }

    public function testDefaultAssetIsRecognisedOnEitherSlot(): void
    {
        $this->assertTrue(CarouselAccessPolicy::usesDefaultAsset(self::DEFAULT_SLIDE));
        $this->assertTrue(CarouselAccessPolicy::usesDefaultAsset([
            'light_image' => 'uploads/carousel/1/slide_light_abc.jpg',
            'dark_image'  => CarouselAssetStore::DEFAULT_DARK_ASSET,
        ]));
        $this->assertFalse(CarouselAccessPolicy::usesDefaultAsset(self::ORDINARY_SLIDE));
        $this->assertFalse(CarouselAccessPolicy::usesDefaultAsset(null));
    }

    public function testDefaultAssetCannotBeEditedByAnyRole(): void
    {
        $superadmin = $this->actor('superadmin', ['rbac.manage']);

        $this->assertFalse(CarouselAccessPolicy::canEdit($superadmin, self::DEFAULT_SLIDE));
        $this->assertFalse(CarouselAccessPolicy::canEditAsset($superadmin, self::DEFAULT_SLIDE));

        foreach (self::restrictedRoles() as [$role, $permissions]) {
            $actor = $this->actor($role, $permissions);
            $this->assertFalse(
                CarouselAccessPolicy::canEdit($actor, self::DEFAULT_SLIDE),
                "Role [{$role}] must not edit the permanent default image."
            );
            $this->assertFalse(
                CarouselAccessPolicy::canEditAsset($actor, self::DEFAULT_SLIDE),
                "Role [{$role}] must not edit the permanent default image."
            );
        }
    }

    #[DataProvider('restrictedRoles')]
    public function testDefaultAssetCannotBeDeletedByRestrictedRoles(string $role, array $permissions): void
    {
        $actor = $this->actor($role, $permissions);

        $this->assertFalse(
            CarouselAccessPolicy::canDelete($actor, self::DEFAULT_SLIDE),
            "Role [{$role}] must not delete the permanent default image."
        );
        $this->assertFalse(
            CarouselAccessPolicy::canDeleteAsset($actor, self::DEFAULT_SLIDE),
            "Role [{$role}] must not delete the permanent default image."
        );
    }

    public function testOnlySuperadminMayDeleteTheDefaultAsset(): void
    {
        $superadmin = $this->actor('superadmin', ['rbac.manage']);

        $this->assertTrue(CarouselAccessPolicy::canDelete($superadmin, self::DEFAULT_SLIDE));
        $this->assertTrue(CarouselAccessPolicy::canDeleteAsset($superadmin, self::DEFAULT_SLIDE));
    }

    #[DataProvider('restrictedRoles')]
    public function testDefaultAssetCannotBeHiddenByRestrictedRoles(string $role, array $permissions): void
    {
        $actor = $this->actor($role, $permissions);

        $this->assertFalse(
            CarouselAccessPolicy::canToggleAsset($actor, self::DEFAULT_SLIDE),
            "Role [{$role}] must not hide the permanent default image."
        );
    }

    public function testOnlySuperadminMayHideTheDefaultAsset(): void
    {
        $this->assertTrue(CarouselAccessPolicy::canToggleAsset($this->actor('superadmin'), self::DEFAULT_SLIDE));
    }

    public function testOrdinarySlidesFollowTheManagementPermission(): void
    {
        $manager = $this->actor('clinic_admin', ['rbac.manage']);
        $staff = $this->actor('clinic_staff', []);

        $this->assertTrue(CarouselAccessPolicy::canEdit($manager, self::ORDINARY_SLIDE));
        $this->assertTrue(CarouselAccessPolicy::canDelete($manager, self::ORDINARY_SLIDE));
        $this->assertTrue(CarouselAccessPolicy::canToggleAsset($manager, self::ORDINARY_SLIDE));

        $this->assertFalse(CarouselAccessPolicy::canEdit($staff, self::ORDINARY_SLIDE));
        $this->assertFalse(CarouselAccessPolicy::canDelete($staff, self::ORDINARY_SLIDE));
    }

    public function testAssertionsCarryTheRefusalReason(): void
    {
        $superadmin = $this->actor('superadmin');

        try {
            CarouselAccessPolicy::assertEditable($superadmin, self::DEFAULT_SLIDE);
            $this->fail('The permanent default image must not be editable.');
        } catch (\RuntimeException $e) {
            $this->assertSame(CarouselAccessPolicy::EDIT_DENIED, $e->getMessage());
            $this->assertSame(403, $e->getCode());
        }

        try {
            CarouselAccessPolicy::assertDeletable($this->actor('guidance_admin', ['rbac.manage']), self::DEFAULT_SLIDE);
            $this->fail('A guidance admin must not delete the permanent default image.');
        } catch (\RuntimeException $e) {
            $this->assertSame(CarouselAccessPolicy::DELETE_DENIED, $e->getMessage());
            $this->assertSame(403, $e->getCode());
        }
    }
}
