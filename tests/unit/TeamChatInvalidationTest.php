<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TeamChatInvalidation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TeamChatInvalidationTest extends TestCase
{
    public function testRevalidatesMembershipTenantAndIdentityAndPublishesOnlyHints(): void
    {
        foreach (['clinic' => 'clinic_staff', 'guidance' => 'counsellor', 'facilities' => 'facilities_op'] as $unit => $role) {
            $checked = $sent = [];
            $publisher = new TeamChatInvalidation(
                function ($tenant, $roles) use ($unit) {
                    $this->assertSame(10, $tenant);
                    $this->assertSame(\App\Services\PortalContext::allowedRoles($unit === 'facilities' ? 'bmg' : $unit), $roles);
                    return array_map(static fn ($id) => ['id' => $id], [0, 1, 1, 2, 3, 4, 5, 6, 7, 8]);
                },
                static function ($id, $tenant) use (&$checked, $role) {
                    $checked[] = [$id, $tenant];
                    $actor = ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => $role];
                    return match ($id) {
                        2 => null, // Deleted, disabled, or otherwise invalid DB identity.
                        3 => array_replace($actor, ['tenant_id' => 99]),
                        4 => array_replace($actor, ['id' => 99]),
                        5 => array_replace($actor, ['role' => 'employee']),
                        6 => array_replace($actor, ['logged_in' => false]),
                        7 => throw new RuntimeException('identity unavailable'),
                        8 => array_replace($actor, ['role' => 'superadmin']),
                        default => $actor,
                    };
                },
                static function (...$args) use (&$sent) { $sent[] = $args; }, true);
            $publisher->publish(10, $unit);
            $this->assertCount(8, $checked);
            $this->assertSame([
                ['private.tenant.10.user.1', 'team.chat.changed', ['unit' => $unit]],
                ['private.tenant.10.user.8', 'team.chat.changed', ['unit' => $unit]],
            ], $sent);
        }
    }

    public function testReadHintTargetsOnlyRevalidatedUserAndFailureIsBestEffort(): void
    {
        $sent = [];
        $enumerated = false;
        $publisher = new TeamChatInvalidation(
            static function () use (&$enumerated) { $enumerated = true; return []; },
            static fn ($id, $tenant) => ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'clinic_admin'],
            static function (...$args) use (&$sent) { $sent[] = $args; throw new RuntimeException('offline'); }, true);
        $publisher->publish(10, 'clinic', 2);
        $this->assertFalse($enumerated);
        $this->assertSame([['private.tenant.10.user.2', 'team.chat.changed', ['unit' => 'clinic']]], $sent);
    }

    public function testDisabledAndInvalidArgumentsDoNotResolveAudience(): void
    {
        $calls = [];
        $unexpected = static function (...$args) use (&$calls) { $calls[] = $args; return []; };
        foreach ([false, true] as $enabled) {
            $publisher = new TeamChatInvalidation($unexpected, $unexpected, $unexpected, $enabled);
            $publisher->publish(0, 'clinic');
            $publisher->publish(1, 'main');
            $publisher->publish(1, 'clinic', 0);
            if (!$enabled) $publisher->publish(1, 'clinic');
        }
        $this->assertSame([], $calls);
    }

    public function testFalseTransportResultStopsFanOut(): void
    {
        $checked = $sent = [];
        $publisher = new TeamChatInvalidation(static fn () => [['id' => 1], ['id' => 2]],
            static function ($id, $tenant) use (&$checked) {
                $checked[] = $id;
                return ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'clinic_staff'];
            },
            static function ($channel) use (&$sent) { $sent[] = $channel; return false; }, true);
        $publisher->publish(10, 'clinic');
        $this->assertSame([1], $checked);
        $this->assertSame(['private.tenant.10.user.1'], $sent);
    }

    public function testAttemptBudgetStopsEvenWithInstantSuccessfulTransport(): void
    {
        $checked = $sent = [];
        $publisher = new TeamChatInvalidation(
            static fn () => array_map(static fn ($id) => ['id' => $id], range(1, 100)),
            static function ($id, $tenant) use (&$checked) {
                $checked[] = $id;
                return ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'clinic_staff'];
            },
            static function ($channel) use (&$sent) { $sent[] = $channel; return true; }, true, static fn () => 0.0);
        $publisher->publish(10, 'clinic');
        $this->assertCount(4, $sent);
        $this->assertSame([1, 2, 3, 4], $checked);
    }

    public function testElapsedBudgetIncludesAudienceAndIdentityWorkAndReservesTransportTime(): void
    {
        foreach (['audience', 'identity', 'transport'] as $slow) {
            $now = 0.0;
            $checked = $sent = [];
            $publisher = new TeamChatInvalidation(
                static function () use (&$now, $slow) {
                    if ($slow === 'audience') $now += 1.1;
                    return array_map(static fn ($id) => ['id' => $id], range(1, 10));
                },
                static function ($id, $tenant) use (&$now, &$checked, $slow) {
                    $checked[] = $id;
                    if ($slow === 'identity') $now += 0.8;
                    return ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'clinic_staff'];
                },
                static function ($channel) use (&$now, &$sent) { $sent[] = $channel; $now += 0.4; return true; },
                true, static function () use (&$now) { return $now; });
            $publisher->publish(10, 'clinic');
            $this->assertCount($slow === 'transport' ? 2 : 0, $sent);
            $this->assertCount(match ($slow) { 'audience' => 0, 'identity' => 1, default => 3 }, $checked);
        }
    }

    public function testDefaultPublisherUsesPrivateShortTimeoutConfig(): void
    {
        $shared = config(\Config\Realtime::class);
        $timeout = $shared->publishTimeout;
        $invalidation = new TeamChatInvalidation(static fn () => [], static fn () => null, enabled: false);
        $send = (new \ReflectionProperty($invalidation, 'send'))->getValue($invalidation);
        $publisher = (new \ReflectionFunction($send))->getClosureThis();
        $private = (new \ReflectionProperty($publisher, 'config'))->getValue($publisher);
        $this->assertNotSame($shared, $private);
        $this->assertSame(0.25, $private->publishTimeout);
        $this->assertSame($timeout, $shared->publishTimeout);
    }

    public function testTransportThrowStopsFanOutAndAudienceFailuresDoNotEscape(): void
    {
        $sent = [];
        $publisher = new TeamChatInvalidation(static fn () => [['id' => 1], ['id' => 2]],
            static fn ($id, $tenant) => ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'clinic_staff'],
            static function ($channel) use (&$sent) { $sent[] = $channel; throw new RuntimeException('offline'); }, true);
        $publisher->publish(10, 'clinic');
        $this->assertCount(1, $sent);
        (new TeamChatInvalidation(static fn () => throw new RuntimeException('offline'),
            static fn () => null, static fn () => null, true))->publish(10, 'clinic');
    }
}
