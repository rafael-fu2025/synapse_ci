<?php

declare(strict_types=1);

namespace App\Services\Carousel;

use RuntimeException;

/**
 * Role-based rules for the login carousel's permanent default image.
 *
 * The default image is the platform's own artwork: it may not be edited by
 * anyone, and only the platform owner (superadmin) may delete the slide that
 * carries it. Every other role that can reach the carousel manager - guidance,
 * clinic and BMG administration alike - is blocked from both operations.
 *
 * The rules live beside the service so that every caller, HTTP or otherwise,
 * is held to the same decision.
 */
final class CarouselAccessPolicy
{
    public const MANAGE_PERMISSION = 'rbac.manage';

    public const EDIT_DENIED = 'The permanent default login image cannot be edited.';
    public const DELETE_DENIED = 'Only a superadmin may delete the permanent default login image.';
    public const HIDE_DENIED = 'Only a superadmin may hide the permanent default login image.';

    /** True when the slide carries the permanent default image in either slot. */
    public static function usesDefaultAsset(?array $slide, ?CarouselAssetStore $store = null): bool
    {
        if (!is_array($slide)) {
            return false;
        }

        $store ??= new CarouselAssetStore();

        return $store->isDefault($slide['light_image'] ?? null)
            || $store->isDefault($slide['dark_image'] ?? null);
    }

    /**
     * The default image is immutable for every role, including the platform
     * owner; other slides stay editable by holders of the management
     * permission.
     */
    public static function canEdit(array $actor, ?array $slide): bool
    {
        return self::canManage($actor) && self::canEditAsset($actor, $slide);
    }

    /**
     * Deleting the default image's slide is reserved for the platform owner;
     * every other slide follows the ordinary management permission.
     */
    public static function canDelete(array $actor, ?array $slide): bool
    {
        return self::canManage($actor) && self::canDeleteAsset($actor, $slide);
    }

    /**
     * Asset-level edit rule, independent of the general management permission.
     *
     * The permanent default image cannot be replaced by anyone; every other
     * slide's artwork is editable.
     */
    public static function canEditAsset(array $actor, ?array $slide): bool
    {
        if (!is_array($slide)) {
            return false;
        }

        return !self::usesDefaultAsset($slide);
    }

    /**
     * Asset-level delete rule, independent of the general management
     * permission.
     *
     * Only the platform owner may delete the slide that carries the permanent
     * default image.
     */
    public static function canDeleteAsset(array $actor, ?array $slide): bool
    {
        if (!is_array($slide)) {
            return false;
        }

        if (!self::usesDefaultAsset($slide)) {
            return true;
        }

        return ($actor['role'] ?? '') === 'superadmin';
    }

    /**
     * Asset-level visibility rule.
     *
     * The slide that carries the permanent default image stays visible unless
     * the platform owner hides it, so the default image remains in place.
     */
    public static function canToggleAsset(array $actor, ?array $slide): bool
    {
        if (!is_array($slide)) {
            return false;
        }

        if (!self::usesDefaultAsset($slide)) {
            return true;
        }

        return ($actor['role'] ?? '') === 'superadmin';
    }

    /**
     * @throws RuntimeException when the slide may not be edited by this actor.
     */
    public static function assertEditable(array $actor, ?array $slide): void
    {
        if (!self::canEditAsset($actor, $slide)) {
            throw new RuntimeException(self::EDIT_DENIED, 403);
        }
    }

    /**
     * @throws RuntimeException when the slide may not be deleted by this actor.
     */
    public static function assertDeletable(array $actor, ?array $slide): void
    {
        if (!self::canDeleteAsset($actor, $slide)) {
            throw new RuntimeException(self::DELETE_DENIED, 403);
        }
    }

    /** Holders of the management permission, plus the platform owner. */
    private static function canManage(array $actor): bool
    {
        if (($actor['role'] ?? '') === 'superadmin') {
            return true;
        }

        $permissions = $actor['permissions'] ?? [];

        return is_array($permissions) && in_array(self::MANAGE_PERMISSION, $permissions, true);
    }
}
