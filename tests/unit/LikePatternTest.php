<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\LikePattern;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A LIKE term must match literally, and the statement must carry the matching
 * ESCAPE clause. Without the clause the database applies its own default escape
 * character, so an escaped metacharacter is still partly a wildcard.
 */
final class LikePatternTest extends TestCase
{
    private ?BaseConnection $fixture = null;
    private array $savedConnections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->savedConnections = $instances->getValue();
        $this->fixture = Database::connect([
            'DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => '', 'DBDebug' => true, 'pConnect' => false, 'failover' => [],
        ], false);
        $instances->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        self::assertSame($this->fixture, Database::connect('default'));
    }

    protected function tearDown(): void
    {
        try {
            $this->fixture?->close();
        } finally {
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->savedConnections);
            $this->fixture = null;
            parent::tearDown();
        }
    }

    public function testMetacharactersAreEscapedWithTheConnectionEscapeCharacter(): void
    {
        $escape = Database::connect()->likeEscapeChar;
        self::assertSame('100' . $escape . '%', LikePattern::literal('100%'));
        self::assertSame('a' . $escape . '_b', LikePattern::literal('a_b'));
        self::assertSame($escape . $escape, LikePattern::literal($escape));
        self::assertSame('plain text', LikePattern::literal('plain text'));
    }

    public function testEscapeCharacterIsEscapedBeforeItIsUsed(): void
    {
        $escape = Database::connect()->likeEscapeChar;
        // "!%" is a literal "!" followed by a literal "%", never a wildcard.
        self::assertSame($escape . $escape . $escape . '%', LikePattern::literal($escape . '%'));
    }

    public function testSingleColumnComparisonCarriesTheEscapeClause(): void
    {
        $escape = Database::connect()->likeEscapeChar;
        $sql = $this->compile(fn ($builder) => LikePattern::apply($builder, 'p.name', '50%_off'));

        self::assertStringContainsString('LIKE ', $sql);
        self::assertStringContainsString("ESCAPE '{$escape}'", $sql);
        // The metacharacters arrive escaped inside the bound literal, never as bare wildcards.
        self::assertStringContainsString('50' . $escape . '%' . $escape . '_off', $sql);
        self::assertStringNotContainsString('50%_off', $sql);
    }

    public function testGroupedComparisonScopesEveryColumnTogether(): void
    {
        $escape = Database::connect()->likeEscapeChar;
        $sql = $this->compile(function ($builder): void {
            $builder->where('p.tenant_id', 7);
            LikePattern::applyGroup($builder, ['p.first_name', 'p.last_name'], 'a%b');
            $builder->where('p.deleted_at', null);
        });

        self::assertStringContainsString('`p`.`first_name`', $sql);
        self::assertStringContainsString('`p`.`last_name`', $sql);
        self::assertStringContainsString('a' . $escape . '%b', $sql);
        // One enclosing group, so the OR cannot leak past the tenant or deletion filters.
        self::assertSame(1, substr_count($sql, '('));
        self::assertSame(1, substr_count($sql, ')'));
        self::assertSame(1, preg_match_all('/\bOR\b/', $sql));
        self::assertSame(2, substr_count($sql, 'ESCAPE'));
        self::assertMatchesRegularExpression(
            '/WHERE\s+`p`\.`tenant_id` = 7\s+AND\s+\(\s*`p`\.`first_name` LIKE [^\r\n]+\s+OR\s+`p`\.`last_name` LIKE [^\r\n]+\s*\)\s+AND `p`\.`deleted_at` IS NULL/',
            $sql,
        );
    }

    public function testEmptyFieldListAddsNoCondition(): void
    {
        $sql = $this->compile(fn ($builder) => LikePattern::applyGroup($builder, [], 'anything'));

        self::assertStringNotContainsString('LIKE', $sql);
    }

    private function compile(callable $apply): string
    {
        $builder = Database::connect()->table('people p');
        $apply($builder);

        return $builder->getCompiledSelect(false);
    }
}
