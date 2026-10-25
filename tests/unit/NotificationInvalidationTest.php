<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NotificationInvalidation;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Realtime;
use ReflectionFunction;
use ReflectionProperty;
use RuntimeException;

/** No database or transport access: every external boundary is a double. */
final class NotificationInvalidationTest extends CIUnitTestCase
{
    private function connection(int &$depth, bool &$healthy): BaseConnection
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('__get')->willReturnCallback(static function (string $name) use (&$depth) {
            return $name === 'transDepth' ? $depth : null;
        });
        $db->method('transStatus')->willReturnCallback(static function () use (&$healthy) { return $healthy; });
        $db->expects(self::never())->method('query');
        $db->expects(self::never())->method('transCommit');
        return $db;
    }

    public function testOnlyCommittedWritesPublishDeduplicatedAuthorizedPrivateHints(): void
    {
        $depth = 1; $healthy = true; $order = [];
        $db = $this->connection($depth, $healthy);
        $service = new NotificationInvalidation($db,
            static function ($tenant, $ids) use (&$order) {
                self::assertSame(7, $tenant);
                self::assertSame([31, 32], $ids);
                $order[] = 'audience';
                return [['id' => 1, 'tenant_id' => 7], ['id' => 1, 'tenant_id' => 7],
                    ['id' => 2, 'tenant_id' => 8], ['id' => 3, 'tenant_id' => 7], ['id' => 0, 'tenant_id' => 7]];
            },
            static function ($tenant, $user, $ids) { self::assertSame(7, $tenant); return $user === 1; },
            static function (...$args) use (&$order) { $order[] = $args; }, true,
        );
        $service->publishCommitted(7, [31, 32]);
        self::assertSame([], $order, 'Never publish before the outer transaction commits');
        $depth = 0;
        $order[] = 'commit';
        $service->publishCommitted(7, [31, 31, 32, 0, -1, '33']);
        self::assertSame(['commit', 'audience', ['private.tenant.7.user.1', 'notifications.changed', []]], $order);
    }

    public function testFailedTransactionDisabledAndEmptyHintsNeverResolveAudience(): void
    {
        $depth = 0; $healthy = false;
        $db = $this->connection($depth, $healthy);
        $unexpected = static function () { self::fail('No audience or transport expected'); };
        $enabled = new NotificationInvalidation($db, $unexpected, $unexpected, $unexpected, true);
        $enabled->publishCommitted(7, [31]);
        $healthy = true;
        $enabled->publishCommitted(7, []);
        $enabled->publishCommitted(0, [31]);
        (new NotificationInvalidation($db, $unexpected, $unexpected, $unexpected, false))->publishCommitted(7, [31]);
        self::assertTrue(true);
    }

    public function testAudienceAndPerRecipientFailuresNeverEscape(): void
    {
        $depth = 0; $healthy = true; $attempted = [];
        $db = $this->connection($depth, $healthy);
        $fail = static function () { throw new RuntimeException('unavailable'); };
        (new NotificationInvalidation($db, $fail, $fail, $fail, true))->publishCommitted(7, [31]);
        (new NotificationInvalidation($db,
            static fn () => [['id' => 1, 'tenant_id' => 7], ['id' => 2, 'tenant_id' => 7], ['id' => 3, 'tenant_id' => 7]],
            static function ($tenant, $id) { if ($id === 1) throw new RuntimeException('authority unavailable'); return true; },
            static function ($channel) use (&$attempted) { $attempted[] = $channel; throw new RuntimeException('transport unavailable'); }, true,
        ))->publishCommitted(7, [31]);
        self::assertSame(['private.tenant.7.user.2'], $attempted, 'Identity failure can continue; transport failure stops fanout');
    }

    public function testFalseTransportResultStopsFanoutAndLaterBatches(): void
    {
        $depth = 0; $healthy = true; $attempted = []; $audiences = 0;
        $service = new NotificationInvalidation($this->connection($depth, $healthy),
            static function () use (&$audiences) {
                ++$audiences;
                return [['id' => 1, 'tenant_id' => 7], ['id' => 2, 'tenant_id' => 7]];
            }, static fn () => true,
            static function ($channel) use (&$attempted) { $attempted[] = $channel; return false; }, true,
        );
        $service->publishCommitted(7, [31]);
        $service->publishCommitted(7, [32]);
        self::assertSame(['private.tenant.7.user.1'], $attempted);
        self::assertSame(1, $audiences);
    }

    public function testAttemptBudgetIsSharedAcrossBatchesEvenForFastSuccessfulTransport(): void
    {
        $depth = 0; $healthy = true; $sent = 0; $audiences = 0;
        $service = new NotificationInvalidation($this->connection($depth, $healthy),
            static function ($tenant) use (&$audiences) {
                ++$audiences;
                return array_map(static fn ($id) => ['id' => $id, 'tenant_id' => $tenant], range(1, 3));
            }, static fn () => true,
            static function () use (&$sent) { ++$sent; return true; }, true, static fn () => 0.0,
        );
        $service->publishCommitted(7, [31]);
        self::assertSame(3, $sent);
        $service->publishCommitted(8, [32]);
        $service->publishCommitted(9, [33]);
        self::assertSame(4, $sent, 'Four attempts total, not four per tenant or recipient list');
        self::assertSame(2, $audiences);
    }

    public function testElapsedBudgetIncludesAudienceAndAuthorityAndReservesTransportTime(): void
    {
        foreach (['audience', 'authority', 'transport'] as $slow) {
            $depth = 0; $healthy = true; $now = 0.0; $sent = 0;
            $service = new NotificationInvalidation($this->connection($depth, $healthy),
                static function () use (&$now, $slow) {
                    if ($slow === 'audience') $now = 1.0;
                    return array_map(static fn ($id) => ['id' => $id, 'tenant_id' => 7], range(1, 100));
                },
                static function () use (&$now, $slow) { if ($slow === 'authority') $now = 0.8; return true; },
                static function () use (&$now, &$sent, $slow) { ++$sent; if ($slow === 'transport') $now += 0.3; return true; },
                true, static function () use (&$now) { return $now; },
            );
            $service->publishCommitted(7, [31]);
            self::assertSame($slow === 'transport' ? 3 : 0, $sent, $slow);
            $now = 2.0;
            $service->publishCommitted(7, [32]);
            self::assertSame($slow === 'transport' ? 3 : 0, $sent, 'A later batch cannot reset the deadline');
        }
    }

    public function testDefaultPublisherUsesPrivateCappedConfigWithoutChangingSharedTimeout(): void
    {
        $config = config(Realtime::class);
        $original = $config->publishTimeout;
        try {
            foreach ([2.0 => 0.25, '0.1' => 0.1] as $timeout => $expected) {
                $config->publishTimeout = (float) $timeout;
                $service = new NotificationInvalidation();
                $send = (new ReflectionProperty($service, 'send'))->getValue($service);
                $publisher = (new ReflectionFunction($send))->getClosureThis();
                $private = (new ReflectionProperty($publisher, 'config'))->getValue($publisher);
                self::assertNotSame($config, $private);
                self::assertSame($expected, $private->publishTimeout);
                self::assertSame((float) $timeout, $config->publishTimeout);
            }
        } finally {
            $config->publishTimeout = $original;
        }
    }
}
