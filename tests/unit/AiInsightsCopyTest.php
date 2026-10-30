<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AiInsightsController;
use App\Services\Ai\AiFailure;
use App\Services\Ai\InsightApplication;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Security;
use Config\Services;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/** A copy test must never construct collectors, storage, or a provider client. */
final class InsightsCopyControllerProbe extends AiInsightsController
{
    protected function application(): InsightApplication
    {
        throw new \LogicException('Copy tests must not construct the application.');
    }
}

final class AiInsightsCopyTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        $security = (new ReflectionClass(Security::class))->newInstanceWithoutConstructor();
        $security->csrfProtection = 'cookie';
        Factories::injectMock('config', Security::class, $security);
        Services::resetSingle('security');
    }

    private function panel(array $overrides = []): string
    {
        return view('ai/panel', $overrides + [
            'unit' => 'clinic', 'analyses' => \App\Services\Ai\AnalysisCatalog::forUnit($overrides['unit'] ?? 'clinic'),
            'legacyAnalysisFixture' => false, 'profile' => ['revision' => 'test-revision'],
            'ready' => true, 'reason' => null, 'jobs' => [], 'insights' => [],
            'canGenerate' => true, 'canReview' => false, 'canApprove' => false,
            'presentationNow' => new \DateTimeImmutable('2026-10-30T12:00:00+08:00'),
        ], ['saveData' => false]);
    }

    public function testMonthLabelsUseManilaCompletedMonthsAtYearAndTimezoneBoundaries(): void
    {
        $html = $this->panel(['presentationNow' => new \DateTimeImmutable('2026-12-31T16:00:00Z')]);
        self::assertStringContainsString('November 2026 and December 2026', $html);
        $html = $this->panel(['presentationNow' => new \DateTimeImmutable('2026-03-31T15:59:59Z')]);
        self::assertStringContainsString('January 2026 and February 2026', $html);
        self::assertStringContainsString('August 2026 and September 2026', $this->panel());
    }

    public function testReadySetupDoesNotClaimDataEligibilityOrRunningWorker(): void
    {
        $html = $this->panel();
        self::assertStringContainsString('does not confirm that enough eligible data is available', $html);
        self::assertStringContainsString('or that the separate worker is running', $html);
        self::assertStringContainsString('separate from AI service connectivity', $html);
        self::assertStringContainsString('No AI drafts yet. Request a draft', $html);
        self::assertStringContainsString('Only current, unexpired drafts', $html);
        self::assertStringContainsString('Prepare local summary', $html);
        self::assertStringContainsString('Request AI draft', $html);
        self::assertStringContainsString('name="analysis_code" value="clinic.activity"', $html);
        self::assertStringContainsString('name="analysis_code" value="clinic.complaints"', $html);
        self::assertStringContainsString('data-ai-refresh', $html);
    }

    public function testReadOnlyAndDisabledControlsKeepTheirRestrictions(): void
    {
        $html = $this->panel(['canGenerate' => false]);
        self::assertStringNotContainsString('data-ai-action', $html);
        $html = $this->panel(['reason' => 'disabled', 'ready' => false]);
        self::assertStringContainsString('Local summaries still require separate approval and privacy checks', $html);
        self::assertSame(2 * count(\App\Services\Ai\AnalysisCatalog::forUnit('clinic')), substr_count($html, ' disabled>'));
    }

    public function testEligibilityChecklistIsAvailableNearActionsForEveryUnit(): void
    {
        foreach (['clinic', 'guidance', 'bmg'] as $unit) {
            $html = $this->panel(['unit' => $unit, 'canGenerate' => false]);
            self::assertStringContainsString('<summary>Why might a summary be unavailable?</summary>', $html);
            self::assertStringContainsString('Both completed months need eligible records', $html);
            self::assertStringContainsString('Required approvals and privacy checks must pass', $html);
            if ($unit === 'bmg') {
                self::assertStringContainsString('Check batch records or accepted rotation reports', $html);
                self::assertStringNotContainsString('different people', $html);
            } else {
                self::assertStringContainsString('Both months need enough different people', $html);
                self::assertStringContainsString('must be recorded as completed and have valid linked records', $html);
            }
            self::assertDoesNotMatchRegularExpression('/minimum|threshold|at least 10/i', $html);
        }
    }

    public function testExplicitAnalysisSelectorsAndPendingWorkerStateArePreserved(): void
    {
        $html = $this->panel([
            'analyses' => \App\Services\Ai\AnalysisCatalog::forUnit('clinic'),
            'jobs' => [['id' => 1, 'analysis_code' => 'clinic.activity', 'status' => 'queued',
                'reason' => null, 'profile_revision' => 'test-revision', 'created_at' => '2026-09-01 00:00:00']],
        ]);
        self::assertStringContainsString('data-ai-pending="true"', $html);
        self::assertStringContainsString('Waiting for a worker', $html);
        self::assertStringContainsString('name="analysis_code" value="clinic.complaints"', $html);
        self::assertStringContainsString('data-ai-action', $html);
        self::assertStringContainsString('/jobs/1/cancel', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function testArchivedReasonsAndUnknownStatusesNeverRenderInternalIdentifiers(): void
    {
        $html = $this->panel(['jobs' => [
            ['id' => 1, 'analysis_code' => 'clinic.activity', 'status' => 'outcome_unknown',
                'reason' => 'provider_timeout', 'profile_revision' => 'old', 'created_at' => '2026-09-01 00:00:00'],
            ['id' => 2, 'analysis_code' => 'clinic.activity', 'status' => 'private_status_marker',
                'reason' => 'private_reason_marker', 'profile_revision' => 'old', 'created_at' => '2026-09-01 00:00:00'],
        ]]);
        self::assertStringContainsString('Result could not be confirmed', $html);
        self::assertStringContainsString('The AI service did not respond in time.', $html);
        self::assertStringContainsString('It will not be sent again automatically.', $html);
        foreach (['provider_timeout', 'outcome_unknown', 'private_status_marker', 'private_reason_marker'] as $identifier) {
            self::assertStringNotContainsString($identifier, $html);
        }
        self::assertStringContainsString('Older configuration', $html);
    }

    public function testInsufficientDataIsUnitSpecificWarningWithoutCountsOrThresholds(): void
    {
        foreach (['clinic' => 'completed clinic visits', 'guidance' => 'completed counselling sessions',
            'facilities' => 'eligible recorded events'] as $unit => $description) {
            $response = $this->failure($unit, new AiFailure('insufficient_data', 422));
            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString('private, no-store', $response->getHeaderLine('Cache-Control'));
            $body = json_decode($response->getBody(), true, 32, JSON_THROW_ON_ERROR);
            self::assertFalse($body['success']);
            self::assertSame('insufficient_data', $body['reason']);
            self::assertSame('warning', $body['feedback_type']);
            self::assertSame(['name' => csrf_token(), 'hash' => csrf_hash()], $body['csrf']);
            self::assertStringContainsString($description, $body['message']);
            self::assertStringContainsString('Both completed months', $body['message']);
            self::assertStringContainsString('not an AI connection error', $body['message']);
            self::assertDoesNotMatchRegularExpression('/\d|threshold|minimum/i', $body['message']);
            if ($unit !== 'facilities') {
                self::assertStringContainsString('different people', $body['message']);
            } else {
                self::assertStringNotContainsString('people', $body['message']);
            }
        }
    }

    public function testOtherFailureKeepsErrorSemanticsAndStatusWithoutRawException(): void
    {
        $response = $this->failure('guidance', new \RuntimeException('PRIVATE exception detail'));
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode($response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('store_unavailable', $body['reason']);
        self::assertSame('error', $body['feedback_type']);
        self::assertStringNotContainsString('PRIVATE', $body['message']);
    }

    public function testPageExplainsLocalWorkQueueAndPrivacyWithoutRenderingGlobalLayout(): void
    {
        // Only the layout hooks are inert; the page and nested panel PHP are executed.
        $page = new class {
            public function extend(string $layout): string { return ''; }
            public function section(string $name): string { return ''; }
            public function endSection(): string { return ''; }
            public function render(array $data): string
            {
                extract($data);
                ob_start();
                include APPPATH . 'Views/ai/index.php';
                return ob_get_clean();
            }
        };
        $html = $page->render([
            'unit' => 'guidance', 'profile' => ['revision' => 'test-revision'],
            'ready' => true, 'reason' => null, 'jobs' => [], 'insights' => [],
            'canGenerate' => false, 'canReview' => false, 'canApprove' => false,
            'presentationNow' => new \DateTimeImmutable('2026-10-30T12:00:00+08:00'),
        ]);
        foreach (['Compare August 2026 and September 2026', 'without using AI',
            'a separate worker to process', '<summary>Privacy and how to use these summaries</summary>',
            'Both months must have enough different people', 'Repeat visits by one person',
            'individual survey answers are never sent', 'does not publish a program',
            'data-ai-feedback', 'data-ai-baseline', 'data-ai-panel'] as $copy) {
            self::assertStringContainsString($copy, $html);
        }
        self::assertStringNotContainsString('data-ai-action', $html);
        self::assertStringNotContainsString('<h1', $html);
        self::assertStringContainsString('<strong>Guidance planning.</strong>', $html);
        self::assertStringContainsString('class="alert alert-info small" hidden', $html);
        self::assertDoesNotMatchRegularExpression('/minimum|at least 10/i', $html);
    }

    public function testEvidenceKeepsTrustedValuesAndExpandablePrivacyDisclosure(): void
    {
        $html = view('ai/evidence', ['evidence' => [
            'analysis_code' => 'clinic.activity',
            'period' => ['label' => 'September 2026'], 'comparison_period' => ['label' => 'August 2026'],
            'metrics' => [['metric_code' => 'completed_encounters', 'unit_of_analysis' => 'encounter',
                'current_value' => 240, 'previous_value' => 200, 'absolute_change' => 40,
                'relative_change_percent' => 20]],
            'limitations' => ['Synthetic trusted limitation <not markup>'],
        ]], ['saveData' => false]);
        foreach (['240', '200', '40', '20.00%', 'completed clinic visits',
            '<summary>Privacy and limits of this comparison</summary>', 'Hidden or missing values are not zero',
            'not the same as 0%', 'Synthetic trusted limitation &lt;not markup&gt;'] as $copy) {
            self::assertStringContainsString($copy, $html);
        }
    }

    private function failure(string $unit, \Throwable $error): Response
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/' . $unit . '/insights/prepare'), null, new UserAgent());
        $response = new Response(config('App'));
        $controller = new InsightsCopyControllerProbe();
        $controller->initController($request, $response, new NullLogger());
        return (new ReflectionMethod(AiInsightsController::class, 'failure'))->invoke($controller, $error);
    }
}
