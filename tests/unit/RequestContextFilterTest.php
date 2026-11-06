<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filters\RequestContextFilter;
use App\Services\CurrentTenant;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class RequestContextFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string|false $tenantEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantEnvironment = getenv('SYNAPSE_TENANT_ID');
        CurrentTenant::reset();
    }

    protected function tearDown(): void
    {
        CurrentTenant::reset();
        if ($this->tenantEnvironment === false) {
            putenv('SYNAPSE_TENANT_ID');
        } else {
            putenv('SYNAPSE_TENANT_ID=' . $this->tenantEnvironment);
        }
        parent::tearDown();
    }

    public function testFilterIsRequiredAtBothRequestBoundaries(): void
    {
        $config = new \Config\Filters();

        $this->assertSame(RequestContextFilter::class, $config->aliases['request_context']);
        $this->assertSame('request_context', $config->required['before'][0]);
        $this->assertSame('request_context', $config->required['after'][array_key_last($config->required['after'])]);
    }

    public function testPublicRequestClearsStaleTenantBeforeDispatchAndAfterResponse(): void
    {
        putenv('SYNAPSE_TENANT_ID=7');
        CurrentTenant::set(99);

        $response = $this->withRoutes([
            ['GET', 'context/public', static fn () => service('response')->setBody((string) CurrentTenant::id())],
        ])->get('context/public');

        $response->assertStatus(200);
        $this->assertStringContainsString('7', $response->getBody());

        putenv('SYNAPSE_TENANT_ID=8');
        $this->assertSame(8, CurrentTenant::id(), 'The request tenant must not remain cached after the response.');
    }

    public function testRejectedRouteStillClearsTenantAfterResponse(): void
    {
        putenv('SYNAPSE_TENANT_ID=11');
        CurrentTenant::set(99);
        $rejected = $this->withRoutes([
            ['GET', 'context/rejected', static fn () => service('response')->setBody('unreachable'), ['filter' => 'api_auth']],
        ])->get('context/rejected');
        $rejected->assertStatus(401);

        putenv('SYNAPSE_TENANT_ID=12');
        $this->assertSame(12, CurrentTenant::id(), 'An authentication rejection must not retain request state.');
    }
}
