<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TeamWorkspaceInvalidation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TeamWorkspaceInvalidationTest extends TestCase
{
    public function testPublishesOnlyContentFreeTaskAndCalendarHintsToRevalidatedMembers(): void
    {
        foreach ([TeamWorkspaceInvalidation::TASKS, TeamWorkspaceInvalidation::CALENDAR] as $event) {
            $sent = [];
            $publisher = new TeamWorkspaceInvalidation(
                static fn (int $tenant, array $roles): array => [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]],
                static fn (int $id, int $tenant): ?array => match ($id) {
                    2 => null,
                    3 => ['id' => 3, 'tenant_id' => 99, 'logged_in' => true, 'role' => 'clinic_staff'],
                    4 => ['id' => 4, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'employee'],
                    default => ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'clinic_staff'],
                },
                static function (...$args) use (&$sent): bool { $sent[] = $args; return true; },
                true
            );
            $publisher->publish(10, 'clinic', $event);
            $this->assertSame([['private.tenant.10.user.1', $event, ['unit' => 'clinic']]], $sent);
            $this->assertSame(['unit'], array_keys($sent[0][2]));
        }
    }

    public function testAttemptAndElapsedBudgetsBoundFanout(): void
    {
        $sent = [];
        $publisher = new TeamWorkspaceInvalidation(
            static fn (): array => array_map(static fn (int $id): array => ['id' => $id], range(1, 100)),
            static fn (int $id, int $tenant): array => [
                'id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'guidance_admin',
            ],
            static function (...$args) use (&$sent): bool { $sent[] = $args; return true; },
            true,
            static fn (): float => 0.0
        );
        $publisher->publish(10, 'guidance', TeamWorkspaceInvalidation::TASKS);
        $this->assertCount(4, $sent);

        $now = 0.0;
        $sent = [];
        $publisher = new TeamWorkspaceInvalidation(
            static fn (): array => [['id' => 1], ['id' => 2]],
            static function (int $id, int $tenant) use (&$now): array {
                $now += 0.8;
                return ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'facilities_op'];
            },
            static function (...$args) use (&$sent): bool { $sent[] = $args; return true; },
            true,
            static function () use (&$now): float { return $now; }
        );
        $publisher->publish(10, 'facilities', TeamWorkspaceInvalidation::CALENDAR);
        $this->assertSame([], $sent);
    }

    public function testInvalidArgumentsDisabledStateAndFailuresAreBestEffort(): void
    {
        $called = [];
        $unexpected = static function (...$args) use (&$called): array { $called[] = $args; return []; };
        $disabled = new TeamWorkspaceInvalidation($unexpected, $unexpected, $unexpected, false);
        $disabled->publish(1, 'clinic', TeamWorkspaceInvalidation::TASKS);
        $enabled = new TeamWorkspaceInvalidation($unexpected, $unexpected, $unexpected, true);
        $enabled->publish(0, 'clinic', TeamWorkspaceInvalidation::TASKS);
        $enabled->publish(1, 'main', TeamWorkspaceInvalidation::TASKS);
        $enabled->publish(1, 'clinic', 'team.secret.changed');
        $this->assertSame([], $called);

        (new TeamWorkspaceInvalidation(
            static fn (): array => throw new RuntimeException('offline'),
            static fn (): ?array => null,
            static fn (): bool => false,
            true
        ))->publish(1, 'clinic', TeamWorkspaceInvalidation::TASKS);
    }
}
