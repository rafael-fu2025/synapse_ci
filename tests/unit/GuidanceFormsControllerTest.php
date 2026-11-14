<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\GuidanceFormsController;
use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use App\Services\Guidance\GuidanceFormService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockSession;
use Config\Services;
use DomainException;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

final class GuidanceFormsControllerTest extends CIUnitTestCase
{
    private GuidanceFormsController $controller;
    private IncomingRequest $request;
    private Response $response;
    private $mockFormService;
    private $mockDefinitionService;
    private $mockDocumentService;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertInstanceOf(MockSession::class, session());
        session()->remove('user');

        $this->request = Services::incomingrequest(null, false);
        $this->request->setMethod('POST');
        $this->request->setHeader('Content-Type', 'application/json');
        $this->request->setGlobal('post', []);
        $this->request->setGlobal('get', []);
        Services::injectMock('request', $this->request);

        $this->response = new Response(config('App'));
        Services::injectMock('response', $this->response);

        $this->mockFormService = $this->createMock(GuidanceFormService::class);
        $this->mockDefinitionService = $this->createMock(GuidanceFormDefinitionService::class);
        $this->mockDocumentService = $this->createMock(GuidanceDocumentService::class);
    }

    private function createController(?array $actor = null, array $permissions = ['guidance.cases.create', 'guidance.cases.write', 'guidance.cases.read', 'guidance.surveys.manage']): GuidanceFormsController
    {
        $actorResolver = function (?string $permission = null) use ($actor, $permissions): array {
            if ($actor === null) {
                throw new RuntimeException('Please sign in to continue.', 401);
            }
            if ($permission !== null && !in_array($permission, $permissions, true)) {
                throw new DomainException('Guidance permission forbidden.', 403);
            }
            return array_merge([
                'id' => 1,
                'person_id' => 10,
                'tenant_id' => 1,
                'role' => 'counsellor',
                'permissions' => $permissions,
            ], $actor);
        };

        $controller = new GuidanceFormsController(
            formService: $this->mockFormService,
            definitionService: $this->mockDefinitionService,
            documentService: $this->mockDocumentService,
            actorResolver: $actorResolver
        );
        $controller->initController($this->request, $this->response, new NullLogger());
        return $controller;
    }

    public function testUnauthenticatedRequestIsDenied(): void
    {
        $controller = $this->createController(null);
        $result = $controller->definitions();

        $this->assertSame(401, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Please sign in to continue.', $body['message']);
    }

    public function testMissingPermissionIsDeniedWith403(): void
    {
        // Actor without guidance.cases.write
        $controller = $this->createController(['id' => 5], ['guidance.cases.read']);
        $result = $controller->createInstance();

        $this->assertSame(403, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Guidance permission forbidden.', $body['message']);
    }

    public function testCreateInstanceValidRequest(): void
    {
        $this->mockFormService->expects($this->once())
            ->method('createInstance')
            ->willReturn([
                'id' => 42,
                'instance_number' => 'OGC-2026-000042',
                'status' => 'draft',
            ]);

        $this->request->setBody(json_encode([
            'person_id' => 10,
            'internal_key' => 'guidance_call_slip',
        ]));

        $controller = $this->createController(['id' => 1]);
        $result = $controller->createInstance();

        $this->assertSame(201, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('success', $body['status']);
        $this->assertSame(42, $body['instance']['id']);
    }

    public function testSaveDraftValidRequest(): void
    {
        $this->mockFormService->expects($this->once())
            ->method('saveDraft')
            ->with(42, ['student_name' => 'Juana'])
            ->willReturn([
                'instance_id' => 42,
                'status' => 'draft_saved',
            ]);

        $this->request->setBody(json_encode([
            'data' => ['student_name' => 'Juana'],
        ]));

        $controller = $this->createController(['id' => 1]);
        $result = $controller->saveDraft(42);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('success', $body['status']);
    }

    public function testFinalizeInstanceValidRequest(): void
    {
        $this->mockFormService->expects($this->once())
            ->method('finalizeInstance')
            ->willReturn([
                'instance_id' => 42,
                'revision_id' => 1,
                'snapshot_hash' => 'synthetic-hash',
                'status' => 'finalized',
            ]);

        $controller = $this->createController(['id' => 1]);
        $result = $controller->finalize(42);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('success', $body['status']);
        $this->assertSame('finalized', $body['result']['status']);
    }

    public function testPublicVerificationEndpointReturnsNonPiiOnly(): void
    {
        $this->mockDocumentService->expects($this->once())
            ->method('verifyCode')
            ->with('SYN-DOC-TEST1234')
            ->willReturn([
                'verification_code' => 'SYN-DOC-TEST1234',
                'status' => 'active',
                'issued_at' => '2026-11-13 10:00:00',
                'pdf_sha256' => 'synthetic-pdf-hash',
                'void_reason' => null,
            ]);

        // Controller does not require auth for verification
        $controller = $this->createController(null);
        $result = $controller->verify('SYN-DOC-TEST1234');

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('success', $body['status']);
        $this->assertSame('SYN-DOC-TEST1234', $body['verification']['verification_code']);
        $this->assertArrayNotHasKey('person_id', $body['verification']);
        $this->assertArrayNotHasKey('student_name', $body['verification']);
    }
}

