<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Realtime\Channel;
use App\Services\Realtime\Origin;
use App\Services\Realtime\TicketSigner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RealtimeTicketTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const ORIGIN = 'https://portal.example.test';

    public function testRoundTripAndExactExpiry(): void
    {
        $signer = new TicketSigner(self::KEY);
        $channels = [Channel::user(4, 12), Channel::personal(12)];
        $ticket = $signer->issue($channels, self::ORIGIN, 1000);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $ticket);
        $this->assertSame(['v' => 1, 'channels' => $channels, 'exp' => 1060, 'origin' => self::ORIGIN],
            $signer->verify($ticket, self::ORIGIN, 1000));
        $this->assertNotNull($signer->verify($ticket, self::ORIGIN, 1059));
        $this->assertNull($signer->verify($ticket, self::ORIGIN, 1060));
        $this->assertNull($signer->verify($ticket, self::ORIGIN, 999));
        $this->assertNull($signer->verify($ticket, self::ORIGIN . ':443', 1000));
        $this->assertNull((new TicketSigner(str_repeat('x', 32)))->verify($ticket, self::ORIGIN, 1000));
    }

    public function testTamperingAndMalformedInputAreRejected(): void
    {
        $signer = new TicketSigner(self::KEY);
        $ticket = $signer->issue([Channel::personal(1)], self::ORIGIN, 1000);
        [$payload, $signature] = explode('.', $ticket);
        foreach (['', 'a', $payload, $payload . '.' . $signature . '.x', str_repeat('x', 8193),
            'A' . substr($ticket, 1), $payload . '.A' . substr($signature, 1), $ticket . '='] as $bad) {
            $this->assertNull($signer->verify($bad, self::ORIGIN, 1000));
        }
    }

    public static function invalidClaims(): iterable
    {
        yield [['v' => 2]];
        yield [['exp' => '1060']];
        yield [['exp' => 1000]];
        yield [['exp' => 1061]];
        yield [['channels' => []]];
        yield [['channels' => ['private.user.*']]];
        yield [['channels' => ['private.user.1', 'private.user.1']]];
        yield [['channels' => ['key' => 'private.user.1']]];
        yield [['channels' => ['private.user.1', 1]]];
        yield [['extra' => true]];
        yield [['origin' => 'https://attacker.test']];
    }

    #[DataProvider('invalidClaims')]
    public function testEvenCorrectlySignedInvalidClaimsFailClosed(array $changes): void
    {
        $claims = array_replace(['v' => 1, 'channels' => ['private.user.1'], 'exp' => 1060, 'origin' => self::ORIGIN], $changes);
        $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, self::KEY, true)), '+/', '-_'), '=');
        $this->assertNull((new TicketSigner(self::KEY))->verify($payload . '.' . $signature, self::ORIGIN, 1000));
    }

    public static function invalidChannels(): iterable
    {
        foreach ([[], ['public.news'], ['private.user.*'], ['private.user.1,private.user.2'], ['private.user.1\n'],
            ['private.user.1', 'private.user.1'], [str_repeat('a', 161)], array_fill(0, 33, 'private.user.1'),
            ['private..user'], ['private.user.'], [null]] as $channels) {
            yield [$channels];
        }
    }

    #[DataProvider('invalidChannels')]
    public function testInvalidGrantsCannotBeIssued(array $channels): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TicketSigner(self::KEY))->issue($channels, self::ORIGIN, 1000);
    }

    public function testChannelHelpersKeepTenantAndUserScopeDistinct(): void
    {
        $this->assertSame('private.tenant.2.user.3', Channel::user(2, 3));
        $this->assertNotSame(Channel::user(2, 3), Channel::user(3, 3));
        $this->assertNotSame(Channel::user(2, 3), Channel::user(2, 4));
        $this->assertSame('private.user.3', Channel::personal(3));
        $this->expectException(InvalidArgumentException::class);
        Channel::user(0, 3);
    }

    public function testWeakKeysAndInvalidOriginsAreRejected(): void
    {
        foreach (['*', 'null', '', 'https://example.test/', 'https://example.test/path', 'https://user@example.test',
            'https://example.test?x=1', 'https://example.test#x', "https://example.test\r\n", 'ws://example.test'] as $origin) {
            $this->assertFalse(Origin::isValid($origin), $origin);
        }
        $this->assertTrue(Origin::isValid('http://127.0.0.1:8080'));
        $this->assertTrue(Origin::isValid('http://[::1]:8080'));
        $this->expectException(InvalidArgumentException::class);
        new TicketSigner(str_repeat('x', 31));
    }
}
