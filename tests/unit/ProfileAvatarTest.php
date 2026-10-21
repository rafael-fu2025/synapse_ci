<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProfileAvatar;
use PHPUnit\Framework\TestCase;

final class ProfileAvatarTest extends TestCase
{
    public function testFirstAndLastNamesAreUsedRatherThanMiddleNamesOrLastWords(): void
    {
        $this->assertSame('MD', ProfileAvatar::initials(['first_name' => 'Maria Clara', 'middle_name' => 'Santos', 'last_name' => 'De la Cruz']));
        $this->assertSame('JD', ProfileAvatar::initials(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']));
        $this->assertSame('JO', ProfileAvatar::initials(['first_name' => '  juan ', 'last_name' => 'o’neil ']));
        $this->assertSame('ÉÑ', ProfileAvatar::initials(['first_name' => 'Élodie', 'last_name' => 'Ñunez']));
        $this->assertSame('李王', ProfileAvatar::initials(['first_name' => '李', 'last_name' => '王']));
    }

    public function testDisplayNameAndMissingNameFallbacksRemainUsable(): void
    {
        $this->assertSame('JS', ProfileAvatar::initials(['name' => 'Juan Middle Santos']));
        $this->assertSame('P', ProfileAvatar::initials(['first_name' => 'Prince']));
        $this->assertSame('S', ProfileAvatar::initials(['username' => 'superadmin']));
        $this->assertSame('U', ProfileAvatar::initials([]));
        $this->assertSame('U', ProfileAvatar::initials(['first_name' => '---', 'last_name' => '  ']));
    }

    public function testAvatarIsMaroonWithWhiteInitialsAndNoExternalContent(): void
    {
        $svg = ProfileAvatar::svg(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($svg));
        $this->assertSame('#800000', $document->getElementsByTagName('rect')->item(0)->getAttribute('fill'));
        $this->assertSame('#ffffff', $document->getElementsByTagName('text')->item(0)->getAttribute('fill'));
        $this->assertSame('AL', $document->getElementsByTagName('text')->item(0)->textContent);
        foreach (['script', 'image', 'foreignObject', 'style'] as $tag) $this->assertSame(0, $document->getElementsByTagName($tag)->length);
        $uri = ProfileAvatar::dataUri(['avatar_initials' => 'MD', 'name' => 'Maria Clara De la Cruz']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $this->assertStringContainsString('>MD</text>', base64_decode(substr($uri, strpos($uri, ',') + 1), true));
    }

    public function testUntrustedCachedInitialsAndNamesCannotInjectSvgMarkup(): void
    {
        $svg = ProfileAvatar::svg(['avatar_initials' => '"><script>alert(1)</script>', 'first_name' => '<img onerror="alert(1)">', 'last_name' => '& Weird']);
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($svg));
        $this->assertSame(0, $document->getElementsByTagName('script')->length);
        $this->assertSame(0, $document->getElementsByTagName('image')->length);
        $this->assertSame('IW', $document->getElementsByTagName('text')->item(0)->textContent);
        $this->assertStringNotContainsString('onerror', $svg);
    }
}
