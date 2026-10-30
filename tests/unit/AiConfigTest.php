<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiFailure;
use App\Services\Ai\BoundedResolver;
use App\Services\Ai\Profile;
use Config\Ai;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiConfigTest extends TestCase
{
    private function defaults(): Ai
    {
        return (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
    }

    private function configured(): Ai
    {
        $c = $this->defaults();
        $c->baseUrl = 'https://gateway.example.test/team-a/v1';
        $c->allowedOrigins = 'https://gateway.example.test';
        $c->model = 'organization/reviewed-model';
        $c->apiKey = 'synthetic-test-key';
        $c->encryptionKey = base64_encode(str_repeat('k', 32));

        return $c;
    }

    public function testDisabledDefaultsAreUsableWithoutNetworkOrSecrets(): void
    {
        $c = $this->defaults();
        self::assertFalse($c->enabled);
        self::assertFalse($c->governanceApproved);
        self::assertFalse($c->programLibraryApproved);
        self::assertSame('department-programs-v2:prompt-v2', $c->capabilityRevision);
        self::assertFalse($c->allowLocalHttp);
        self::assertFalse($c->allowPrivateHttpDispatch);
        foreach (['baseUrl', 'model', 'apiKey', 'reasoningEffort', 'thinkingMode', 'allowedOrigins', 'privateOrigins', 'approvedTenants', 'approvedRevision', 'compatibilityApprovedRevision', 'encryptionKey', 'caBundle', 'resolverPhpBinary'] as $field) {
            self::assertSame('', $c->$field);
        }
        foreach (['connectTimeoutSeconds' => 5, 'requestTimeoutSeconds' => 45, 'maxRequestBytes' => 65536,
            'maxResponseBytes' => 262144, 'maxInputTokens' => 8192, 'maxOutputTokens' => 2000, 'maxConcurrentRequests' => 1,
            'maxAttempts' => 1, 'dailyRequestLimit' => 50, 'minimumContributors' => 10,
            'retentionDays' => 30, 'maxJobsPerRun' => 5] as $field => $value) {
            self::assertSame($value, $c->$field);
        }
        $p = Profile::fromConfig($c);
        self::assertFalse($p->publicStatus()['configured']);
        self::assertFalse($p->publicStatus()['compatibilityVerified']);
        self::assertSame('bearer', $p->config->authMode);
        self::assertSame('openai_chat_completions', $p->config->adapter);
        self::assertSame('max_tokens', $p->config->maxOutputTokensField);
        self::assertSame('none', $p->config->structuredOutputMode);
        $this->expectExceptionMessage('disabled');
        $p->assertDispatchAllowed(1, 'clinic');
    }

    public static function invalidSettings(): iterable
    {
        foreach (['enabled', 'governanceApproved', 'programLibraryApproved', 'allowLocalHttp', 'allowPrivateHttpDispatch'] as $flag) {
            foreach (['false', 'true', 'yes', '0', 0, 1, null, []] as $value) {
                yield [$flag, $value];
            }
        }
        foreach (['connectTimeoutSeconds' => [0, 31], 'requestTimeoutSeconds' => [0, 91, 120, 121],
            'maxRequestBytes' => [255, 1048577], 'maxResponseBytes' => [255, 4194305],
            'maxInputTokens' => [0, 511, 131073], 'maxOutputTokens' => [0, 32769], 'maxConcurrentRequests' => [0, 2, 16],
            'maxAttempts' => [0, 2, 3], 'dailyRequestLimit' => [0, 10001],
            'minimumContributors' => [9, 1000001], 'retentionDays' => [0, 366],
            'maxJobsPerRun' => [0, 101]] as $field => $values) {
            foreach (array_merge($values, ['1', 1.0, true, null]) as $value) {
                yield [$field, $value];
            }
        }
        foreach ([
            'profileId' => ['', 'with space', str_repeat('x', 65)],
            'credentialVersion' => ['', 'bad version'], 'adapter' => ['other', ''],
            'capabilityRevision' => ['', 'bad version', "bad\nrevision", str_repeat('v', 129), true, 1, null],
            'model' => ["bad\nmodel", ' space', str_repeat('m', 192), str_repeat('é', 96), "\xff",
                "model\u{00a0}name", "model\u{200b}name", "model\u{0085}name", "model\u{2028}name"],
            'apiKey' => ["secret\r\nHost: evil", ' secret ', str_repeat('s', 4097)],
            'authMode' => ['basic', '', 'BEARER'], 'apiKeyHeader' => ['Authorization', 'Host', 'Cookie', 'Connection',
                'Content-Length', 'Proxy-Authorization', 'Forwarded', 'X-Forwarded-Host', 'Sec-Fetch-Site', 'X-CSRF-Token', 'X-Other', "X-API-Key\n"],
            'apiKeyHeaderAllowlist' => ['', 'X-API-Key,Host', 'X-API-Key,'],
            'maxOutputTokensField' => ['tokens', ''], 'structuredOutputMode' => ['json_schema', 'text', ''],
            'thinkingMode' => ['auto', 'default', 'none', 'DISABLED', ' disabled', 'enabled ', "disabled\n", false, 0, 1, null, []],
            'reasoningEffort' => ['auto', 'default', 'HIGH', ' high', 'high ', "low\n", 'disabled', false, 0, 1, null, []],
            'approvedTenants' => ['0', '-1', '01', '1.0', '1e2', '1,', '1,1', '*', str_repeat('9', 100)],
            'approvedRevision' => ['secret', str_repeat('A', 64)],
            'compatibilityApprovedRevision' => ['secret', str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 65), true, null],
            'allowedOrigins' => ['*', 'https://example.test/path', 'https://example.test/', 'https://example.test,',
                'https://example.test,https://example.test:443'],
            'privateOrigins' => ['https://unapproved.test'],
            'encryptionKey' => ['not-base64', base64_encode(str_repeat('k', 31)), base64_encode(str_repeat('k', 33)), base64_encode(str_repeat('k', 32)) . "\n"],
            'caBundle' => ["invalid\0path", 'https://ca.example.test/root.pem'],
            'resolverPhpBinary' => ['php', './php', 'https://binary.example.test/php', "bad\0path", '/humus-no-such-resolver-binary/php', '\\\\server\\php.exe', true, null],
        ] as $field => $values) {
            foreach ($values as $value) {
                yield [$field, $value];
            }
        }
    }

    #[DataProvider('invalidSettings')]
    public function testStrictConfigValidation(string $field, mixed $value): void
    {
        $c = $this->configured();
        $c->$field = $value;
        $this->expectException(AiFailure::class);
        Profile::fromConfig($c);
    }

    public function testTokenControlRangesAndExplicitReasoningChoices(): void
    {
        foreach (['', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh'] as $effort) {
            foreach ([512, 8192, 131072] as $limit) {
                $c = $this->configured();
                $c->reasoningEffort = $effort;
                $c->maxInputTokens = $limit;
                $p = Profile::fromConfig($c);
                self::assertSame($effort, $p->config->reasoningEffort);
                self::assertSame($limit, $p->config->maxInputTokens);
            }
        }
    }

    public function testModelIsOpaquePlainUtf8AndLimitedByBytesNotCharacters(): void
    {
        foreach (['organization/model@revision:+[]()=?!#&', '模型@组织/版本', str_repeat('m', 191), str_repeat('é', 95) . 'a'] as $model) {
            $c = $this->configured();
            $c->model = $model;
            self::assertSame($model, Profile::fromConfig($c)->config->model);
        }
    }

    public function testResolverExecutableIsExplicitAbsoluteAndFingerprintRelevant(): void
    {
        $c = $this->configured();
        $revision = Profile::fromConfig($c)->revision();
        $c->resolverPhpBinary = PHP_BINARY;
        self::assertSame(realpath(PHP_BINARY), BoundedResolver::phpBinary($c->resolverPhpBinary));
        self::assertNotSame($revision, Profile::fromConfig($c)->revision());
        $this->withEnvironment(['resolverPhpBinary' => PHP_BINARY, 'requestTimeoutSeconds' => '90'], static function (): void {
            $p = Profile::fromConfig(new Ai());
            self::assertSame(PHP_BINARY, $p->config->resolverPhpBinary);
            self::assertSame(90, $p->config->requestTimeoutSeconds);
        });
        $this->withEnvironment(['requestTimeoutSeconds' => '120'], function (): void {
            $this->fails('invalid_config', static fn () => Profile::fromConfig(new Ai()));
        });
    }

    public function testTimeoutOrderAndExplicitNoAuth(): void
    {
        $c = $this->configured();
        $c->authMode = 'none';
        $this->fails('invalid_config', static fn () => Profile::fromConfig($c));
        $c->apiKey = '';
        self::assertSame('none', Profile::fromConfig($c)->config->authMode);
        $c->connectTimeoutSeconds = 10;
        $c->requestTimeoutSeconds = 5;
        $this->fails('invalid_config', static fn () => Profile::fromConfig($c));
    }

    /** Exercise the real CI BaseConfig constructor and precedence, restoring every environment entry. */
    private function withEnvironment(array $overrides, callable $test): void
    {
        $saved = $_ENV;
        try {
            foreach (get_object_vars($this->defaults()) as $name => $value) {
                $_ENV['ai.' . $name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
            foreach ($overrides as $name => $value) {
                $_ENV['ai.' . $name] = $value;
            }
            $test();
        } finally {
            $_ENV = $saved;
        }
    }

    public function testCiEnvironmentScalarParsingDoesNotCoerceTruthyStrings(): void
    {
        foreach (['enabled', 'governanceApproved', 'programLibraryApproved', 'allowLocalHttp', 'allowPrivateHttpDispatch'] as $flag) {
            foreach (['false' => false, 'true' => true, '0' => false, '1' => true] as $input => $expected) {
                $this->withEnvironment([$flag => (string) $input], static function () use ($flag, $expected): void {
                    self::assertSame($expected, (new Ai())->$flag);
                });
            }
            foreach (['yes', 'FALSE', 'off', '', ' true ', '2', 'invalid'] as $input) {
                $this->withEnvironment([$flag => $input], function (): void {
                    $this->fails('invalid_config', static fn () => new Ai());
                });
            }
        }
        foreach (['1x', '1.0', '1e2', '01', '+1', '-1', '', ' 1', str_repeat('9', 100), 'true'] as $input) {
            $this->withEnvironment(['maxAttempts' => $input], function (): void {
                $this->fails('invalid_config', static fn () => new Ai());
            });
        }
        $this->withEnvironment(['maxAttempts' => '1', 'maxResponseBytes' => '65536', 'model' => 'org/model',
            'capabilityRevision' => 'clinic-operations-v2:prompt-v3', 'programLibraryApproved' => 'true'], static function (): void {
            $c = new Ai();
            self::assertSame(1, $c->maxAttempts);
            self::assertTrue($c->programLibraryApproved);
            self::assertSame('clinic-operations-v2:prompt-v3', $c->capabilityRevision);
            self::assertSame(65536, $c->maxResponseBytes);
            self::assertSame('org/model', $c->model);
            Profile::fromConfig($c);
        });
    }

    public function testThinkingModeUsesStrictEnvironmentParsingAndIndependentRevisions(): void
    {
        $revisions = [];
        foreach (['', 'disabled', 'enabled'] as $mode) {
            $this->withEnvironment(['thinkingMode' => $mode, 'reasoningEffort' => 'none'], static function () use ($mode): void {
                self::assertSame($mode, Profile::fromConfig(new Ai())->config->thinkingMode);
            });
            $c = $this->configured();
            $c->thinkingMode = $mode;
            $revisions[] = Profile::fromConfig($c)->revision();
        }
        self::assertCount(3, array_unique($revisions));
        $legacy = get_object_vars($this->configured());
        unset($legacy['thinkingMode'], $legacy['apiKey'], $legacy['encryptionKey'], $legacy['approvedRevision'], $legacy['compatibilityApprovedRevision']);
        ksort($legacy);
        self::assertSame(hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR)), $revisions[0]);
        $this->withEnvironment(['thinkingMode' => 'none'], function (): void {
            $this->fails('invalid_config', static fn () => Profile::fromConfig(new Ai()));
        });
    }

    public function testTokenControlsUseStrictEnvironmentParsing(): void
    {
        $this->withEnvironment(['maxInputTokens' => '131072', 'reasoningEffort' => 'xhigh'], static function (): void {
            $p = Profile::fromConfig(new Ai());
            self::assertSame(131072, $p->config->maxInputTokens);
            self::assertSame('xhigh', $p->config->reasoningEffort);
        });
        foreach (['8192.0', '8e3', '08192', '+8192', '-8192', ' 8192', '', 'true', '511', '131073'] as $input) {
            $this->withEnvironment(['maxInputTokens' => $input], function (): void {
                $this->fails('invalid_config', static fn () => Profile::fromConfig(new Ai()));
            });
        }
        $this->withEnvironment(['reasoningEffort' => 'auto'], function (): void {
            $this->fails('invalid_config', static fn () => Profile::fromConfig(new Ai()));
        });
    }

    public function testCiUnderscoreEnvironmentAliasWorks(): void
    {
        $saved = $_ENV;
        try {
            $this->withEnvironment([], static function (): void {
                unset($_ENV['ai.maxOutputTokens']);
                $_ENV['ai_maxOutputTokens'] = '1001';
                self::assertSame(1001, (new Ai())->maxOutputTokens);
            });
        } finally {
            $_ENV = $saved;
        }
    }

    public function testRevisionExcludesSecretMaterialAndApprovalButIncludesCredentialVersion(): void
    {
        $c = $this->configured();
        $revision = Profile::fromConfig($c)->revision();
        $c->apiKey = 'rotated-synthetic-secret';
        $c->encryptionKey = base64_encode(str_repeat('n', 32));
        $c->approvedRevision = str_repeat('a', 64);
        self::assertSame($revision, Profile::fromConfig($c)->revision());
        $c->compatibilityApprovedRevision = str_repeat('b', 64);
        self::assertSame($revision, Profile::fromConfig($c)->revision());
        $c->credentialVersion = '2';
        self::assertNotSame($revision, Profile::fromConfig($c)->revision());
    }

    public function testEveryNonsecretPolicySettingParticipatesInRevision(): void
    {
        $changes = ['enabled' => true, 'profileId' => 'other-profile', 'baseUrl' => 'https://gateway.example.test/other/v1',
            'model' => 'org/other-model', 'apiKeyHeader' => 'API-Key', 'apiKeyHeaderAllowlist' => 'X-API-Key,API-Key,X-Provider-Key',
            'credentialVersion' => '2', 'maxOutputTokensField' => 'max_completion_tokens', 'structuredOutputMode' => 'json_object',
            'connectTimeoutSeconds' => 6, 'requestTimeoutSeconds' => 46, 'maxRequestBytes' => 65537, 'maxResponseBytes' => 262145,
            'maxInputTokens' => 8193, 'maxOutputTokens' => 2001, 'reasoningEffort' => 'high', 'thinkingMode' => 'disabled', 'dailyRequestLimit' => 51,
            'programLibraryApproved' => true, 'capabilityRevision' => 'clinic-operations-v1:prompt-v2',
            'allowedOrigins' => 'https://gateway.example.test,https://other.example.test', 'privateOrigins' => 'https://gateway.example.test',
            'allowLocalHttp' => true, 'allowPrivateHttpDispatch' => true, 'governanceApproved' => true,
            'approvedTenants' => '1,2', 'minimumContributors' => 11, 'retentionDays' => 31, 'maxJobsPerRun' => 6];
        $revision = Profile::fromConfig($this->configured())->revision();
        foreach ($changes as $name => $value) {
            $c = $this->configured();
            $c->$name = $value;
            self::assertNotSame($revision, Profile::fromConfig($c)->revision(), $name);
        }
        $c = $this->configured();
        $c->authMode = 'api_key_header';
        self::assertNotSame($revision, Profile::fromConfig($c)->revision());
    }

    public function testSnapshotIsDetachedAndCannotBeMutatedThroughPublicConfig(): void
    {
        $c = $this->configured();
        $p = Profile::fromConfig($c);
        $revision = $p->revision();
        $c->model = 'changed-original';
        self::assertSame('organization/reviewed-model', $p->config->model);
        self::assertSame($revision, $p->revision());
        $p->config->enabled = true;
        $this->fails('invalid_config', static fn () => $p->revision());
        $this->fails('invalid_config', static fn () => $p->assertDispatchAllowed(1, 'clinic'));
    }

    public function testDispatchRequiresEachGateAndAnExactCurrentRevision(): void
    {
        $c = $this->configured();
        $this->fails('disabled', static fn () => Profile::fromConfig($c)->assertDispatchAllowed(1, 'clinic'));
        $c->enabled = true;
        $this->fails('governance_required', static fn () => Profile::fromConfig($c)->assertDispatchAllowed(1, 'clinic'));
        $c->governanceApproved = true;
        $this->fails('unit_not_approved', static fn () => Profile::fromConfig($c)->assertDispatchAllowed(1, 'guidance'));
        $this->fails('tenant_not_approved', static fn () => Profile::fromConfig($c)->assertDispatchAllowed(1, 'clinic'));
        $c->approvedTenants = '1, 2';
        $this->fails('revision_not_approved', static fn () => Profile::fromConfig($c)->assertDispatchAllowed(1, 'clinic'));
        $c->approvedRevision = Profile::fromConfig($c)->revision();
        $this->fails('compatibility_required', static fn () => Profile::fromConfig($c)->assertDispatchAllowed(1, 'clinic'));
        $c->compatibilityApprovedRevision = str_repeat('b', 64);
        $this->fails('compatibility_required', static fn () => Profile::fromConfig($c)->assertDispatchAllowed(1, 'clinic'));
        $c->compatibilityApprovedRevision = $c->approvedRevision;
        $p = Profile::fromConfig($c);
        self::assertTrue($p->publicStatus()['compatibilityVerified']);
        $p->assertDispatchAllowed(1, 'clinic');
        $p->assertDispatchAllowed(2, 'clinic');
        $this->fails('tenant_not_approved', static fn () => $p->assertDispatchAllowed(3, 'clinic'));
        $this->fails('tenant_not_approved', static fn () => $p->assertDispatchAllowed(0, 'clinic'));
        $this->fails('unit_not_approved', static fn () => $p->assertDispatchAllowed(1, 'Clinic'));
        $c->dailyRequestLimit++;
        $this->fails('revision_not_approved', static fn () => Profile::fromConfig($c)->assertDispatchAllowed(1, 'clinic'));
    }

    public function testEnabledProfileCannotUseMissingModelSecretOrDestination(): void
    {
        foreach (['model', 'apiKey', 'baseUrl'] as $field) {
            $c = $this->configured();
            $c->enabled = true;
            $c->$field = '';
            $this->fails('invalid_config', static fn () => Profile::fromConfig($c));
        }
        $c = $this->configured();
        $c->enabled = true;
        $c->governanceApproved = true;
        $c->approvedTenants = '1';
        $c->encryptionKey = '';
        $c->approvedRevision = Profile::fromConfig($c)->revision();
        $c->compatibilityApprovedRevision = $c->approvedRevision;
        $this->fails('invalid_config', static fn () => Profile::fromConfig($c)->assertDispatchAllowed(1, 'clinic'));
    }

    public function testAcknowledgmentsCanBeSetWithoutChangingFingerprintAndBothExpireOnCapabilityChange(): void
    {
        $c = $this->configured();
        $c->enabled = true;
        $c->governanceApproved = true;
        $c->programLibraryApproved = true;
        $c->approvedTenants = '1';
        $revision = Profile::fromConfig($c)->revision();
        $c->approvedRevision = $revision;
        $c->compatibilityApprovedRevision = $revision;
        $p = Profile::fromConfig($c);
        self::assertSame($revision, $p->revision());
        self::assertTrue($p->publicStatus()['revisionApproved']);
        self::assertTrue($p->publicStatus()['compatibilityVerified']);
        $p->assertDispatchAllowed(1, 'clinic');

        $c->capabilityRevision = 'clinic-operations-v1:prompt-v2';
        $p = Profile::fromConfig($c);
        self::assertNotSame($revision, $p->revision());
        self::assertFalse($p->publicStatus()['revisionApproved']);
        self::assertFalse($p->publicStatus()['compatibilityVerified']);
        $this->fails('revision_not_approved', static fn () => $p->assertDispatchAllowed(1, 'clinic'));
        $c->approvedRevision = $p->revision();
        $this->fails('compatibility_required', static fn () => Profile::fromConfig($c)->assertDispatchAllowed(1, 'clinic'));
        $c->compatibilityApprovedRevision = $c->approvedRevision;
        Profile::fromConfig($c)->assertDispatchAllowed(1, 'clinic');
    }

    public function testSingleDispatchNoRetryLimitsAlsoRejectEnvironmentOverrides(): void
    {
        foreach (['maxConcurrentRequests', 'maxAttempts'] as $field) {
            $this->withEnvironment([$field => '2'], function (): void {
                $this->fails('invalid_config', static fn () => Profile::fromConfig(new Ai()));
            });
        }
    }

    public function testApprovalAcknowledgmentsAreScalarEnvironmentStrings(): void
    {
        $this->withEnvironment(['approvedRevision' => str_repeat('a', 64), 'compatibilityApprovedRevision' => str_repeat('b', 64)], static function (): void {
            $p = Profile::fromConfig(new Ai());
            self::assertSame(str_repeat('a', 64), $p->config->approvedRevision);
            self::assertSame(str_repeat('b', 64), $p->config->compatibilityApprovedRevision);
            self::assertFalse($p->publicStatus()['compatibilityVerified']);
        });
    }

    public static function sharedFailureReasons(): iterable
    {
        foreach (['insufficient_data', 'invalid_snapshot', 'analytics_unavailable',
            'encryption_unavailable', 'payload_unavailable', 'store_unavailable',
            'snapshot_changed_requires_review', 'snapshot_expired', 'invalid_job',
            'daily_budget_exceeded', 'job_not_found', 'invalid_lease', 'worker_busy',
            'invalid_review', 'insight_not_found', 'review_conflict', 'invalid_review_transition',
            'snapshot_not_found', 'lease_lost', 'invalid_scope', 'invalid_payload',
            'independent_transaction_required', 'access_denied', 'authentication_required',
            'library_not_approved', 'output_rejected', 'job_expired', 'endpoint_changed', 'compatibility_required',
                        'dispatch_paused', 'dispatch_budget_exceeded', 'input_budget_exceeded'] as $reason) {
            yield $reason => [$reason];
        }
    }

    #[DataProvider('sharedFailureReasons')]
    public function testSharedSafeCodesArePreservedWithoutExceptionChaining(string $reason): void
    {
        self::assertContains($reason, AiFailure::REASONS);
        $error = new AiFailure($reason, 409);
        self::assertSame($reason, $error->reason);
        self::assertSame($reason, $error->getMessage());
        self::assertSame(409, $error->getCode());
        self::assertNull($error->getPrevious());
        self::assertSame(count(AiFailure::REASONS), count(array_unique(AiFailure::REASONS)));
    }

    public function testPublicStatusAndUnknownFailureReasonsAreRedacted(): void
    {
        $c = $this->configured();
        $status = Profile::fromConfig($c)->publicStatus();
        self::assertSame(['profileId', 'adapter', 'enabled', 'configured', 'revision', 'governanceApproved', 'revisionApproved', 'compatibilityVerified'], array_keys($status));
        $serialized = json_encode($status, JSON_THROW_ON_ERROR);
        foreach ([$c->apiKey, $c->baseUrl, $c->model, $c->encryptionKey] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
        $error = new AiFailure('raw secret body', 200);
        self::assertSame('transport_failed', $error->reason);
        self::assertSame('transport_failed', $error->getMessage());
        self::assertSame(503, $error->getCode());
        self::assertNull($error->getPrevious());
        self::assertSame(429, (new AiFailure('provider_rate_limited', 429))->getCode());
    }

    private function fails(string $reason, callable $action): void
    {
        try {
            $action();
            self::fail('Expected ' . $reason);
        } catch (AiFailure $error) {
            self::assertSame($reason, $error->reason);
            self::assertSame($reason, $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }
}
