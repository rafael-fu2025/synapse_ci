<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\DeploymentControl;
use App\Services\Ai\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

require_once __DIR__ . '/AiApplicationTest.php';

/** Every runtime stamp is synthetic; the private production dotenv stamper is inspected only. */
final class AiDeploymentControlTest extends TestCase
{
    use AiApplicationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpControl();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
        parent::tearDown();
    }

    public function testMissingPausedResumedAndRemovedManifestFailClosed(): void
    {
        $this->assertAiFailure('dispatch_paused', fn () => $this->control->assertAllowed($this->profile, 1));
        $this->control->pause();
        $this->assertAiFailure('dispatch_paused', fn () => $this->control->assertAllowed($this->profile, 1));
        $this->control->resume($this->profile, 1);
        $this->control->assertAllowed($this->profile, 1);
        $manifest = json_decode(file_get_contents($this->controlPath), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(1, $manifest['version']);
        self::assertFalse($manifest['paused']);
        self::assertSame($this->profile->revision(), $manifest['revision']);
        self::assertSame($this->sourceStamp, $manifest['source_stamp']);
        self::assertStringNotContainsString('SYNTHETIC-SECRET', file_get_contents($this->controlPath));
        unlink($this->controlPath);
        $this->assertAiFailure('dispatch_paused', fn () => $this->control->assertAllowed($this->profile, 1));
    }

    public function testFreshApprovedRevisionAndChangedStampRequireExplicitResume(): void
    {
        $this->control->resume($this->profile, 1);
        $changedProfile = self::fixtureProfile(['credentialVersion' => '2']);
        $this->assertAiFailure('dispatch_paused', fn () => $this->control->assertAllowed($changedProfile, 1));
        $this->sourceStamp = 'synthetic-source-b';
        $this->assertAiFailure('dispatch_paused', fn () => $this->control->assertAllowed($this->profile, 1));
        $this->control->resume($changedProfile, 1);
        $this->control->assertAllowed($changedProfile, 1);
        $this->assertAiFailure('dispatch_paused', fn () => $this->control->assertAllowed($this->profile, 1));
    }

    public static function invalidManifests(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['{'];
        yield 'oversized' => [str_repeat(' ', 4097)];
        yield 'scalar' => ['false'];
        yield 'array' => ['[]'];
        foreach (['version' => 2, 'paused' => 'false', 'revision' => 12, 'source_stamp' => null] as $field => $value) {
            yield $field => [[$field => $value]];
        }
    }

    #[DataProvider('invalidManifests')]
    public function testMalformedOrMismatchedManifestNeverPermitsDispatch(string|array $corruption): void
    {
        $this->control->resume($this->profile, 1);
        if (is_array($corruption)) {
            $manifest = json_decode(file_get_contents($this->controlPath), true, 8, JSON_THROW_ON_ERROR);
            $corruption = json_encode(array_replace($manifest, $corruption), JSON_THROW_ON_ERROR);
        }
        file_put_contents($this->controlPath, $corruption);
        $this->assertAiFailure('dispatch_paused', fn () => $this->control->assertAllowed($this->profile, 1));
    }

    public static function unapprovedProfiles(): iterable
    {
        yield 'disabled' => [['enabled' => false], 'disabled'];
        yield 'governance' => [['governanceApproved' => false], 'governance_required'];
        yield 'tenant' => [['approvedTenants' => '2'], 'tenant_not_approved'];
        yield 'revision' => [['approvedRevision' => ''], 'revision_not_approved'];
        yield 'compatibility' => [['compatibilityApprovedRevision' => ''], 'compatibility_required'];
        yield 'library' => [['programLibraryApproved' => false], 'library_not_approved'];
        yield 'library version' => [['capabilityRevision' => 'unapproved:prompt-v1'], 'library_not_approved'];
        yield 'encryption' => [['encryptionKey' => ''], 'invalid_config'];
    }

    #[DataProvider('unapprovedProfiles')]
    public function testResumeRequiresCompleteApprovalAndLeavesNoManifestOnFailure(array $changes, string $reason): void
    {
        $this->assertAiFailure($reason, fn () => $this->control->resume(self::fixtureProfile($changes), 1));
        self::assertFileDoesNotExist($this->controlPath);
    }

    public function testPauseIsIndependentOfInvalidConfigurationAndDoesNotEvaluateStamp(): void
    {
        $control = new DeploymentControl($this->controlPath, static function (): never {
            throw new RuntimeException('The stamp must not be read to pause.');
        });
        $this->assertAiFailure('invalid_config', fn () => self::fixtureProfile(['maxAttempts' => 2]));
        $control->pause();
        self::assertSame(0, (new \ReflectionMethod(DeploymentControl::class, 'pause'))->getNumberOfParameters());
        self::assertTrue(json_decode(file_get_contents($this->controlPath), true, 8, JSON_THROW_ON_ERROR)['paused']);
        $this->assertAiFailure('dispatch_paused', fn () => $control->assertAllowed($this->profile, 1));
    }

    public function testStampReadFailureOnExistingResumedManifestIsControlled(): void
    {
        $this->control->resume($this->profile, 1);
        $control = new DeploymentControl($this->controlPath, static function (): never {
            throw new RuntimeException('PRIVATE configuration source failure');
        });
        $this->assertAiFailure('dispatch_paused', fn () => $control->assertAllowed($this->profile, 1));
    }

    public function testSourceChangeDuringResolverCallbackPreventsExistingProfileDispatch(): void
    {
        $this->setUpStore();
        $this->seedSource();
        $this->control->resume($this->profile, 1);
        $job = $this->application()->generate(self::fixtureActor());
        $revision = $this->profile->revision();
        $worker = $this->worker(resolver: function (): array {
            // Simulate an on-disk change after claim/identity, before the final transport callback.
            $this->sourceStamp = 'synthetic-live-change';
            return ['8.8.8.8'];
        });
        self::assertSame(['processed' => 0, 'failed' => 1, 'outcome_unknown' => 0], $worker->run(1));
        self::assertSame($revision, $this->profile->revision());
        self::assertSame(0, $this->requests);
        self::assertSame(0, $this->fixtureDb->table('ai_dispatch_attempts')->countAllResults());
        self::assertSame([], $this->store->listInsights(1, 'clinic'));
        $failed = $this->store->job(1, 'clinic', $job['id']);
        self::assertSame('failed', $failed['status']);
        self::assertSame('ai_disabled', $failed['reason']);
        $this->assertAiFailure('dispatch_paused', fn () => $worker->run(1));
    }

    public function testProductionStampIsPrivateKeyedAndCheckedOnEveryAssertionWithoutReadingDotenv(): void
    {
        $class = new ReflectionClass(DeploymentControl::class);
        $method = $class->getMethod('configurationStamp');
        self::assertTrue($method->isPrivate());
        self::assertTrue($method->isStatic());
        $source = file($class->getFileName());
        $stamp = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringContainsString("ROOTPATH . '.env'", $stamp);
        self::assertStringContainsString('hash_hmac_file', $stamp);
        self::assertStringNotContainsString('hash_file(', $stamp);
        $assert = $class->getMethod('assertAllowed');
        $body = implode('', array_slice($source, $assert->getStartLine() - 1, $assert->getEndLine() - $assert->getStartLine() + 1));
        self::assertStringContainsString("\$manifest['revision']", $body);
        self::assertStringContainsString("\$manifest['source_stamp']", $body);
        self::assertStringContainsString('($this->sourceStamp)($profile)', $body);
    }
}
