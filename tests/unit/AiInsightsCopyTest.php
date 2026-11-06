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
        self::assertStringContainsString('Eligibility and worker availability are checked separately', $html);
        self::assertStringContainsString('No AI drafts yet', $html);
        self::assertStringContainsString('Generate a draft if you have permission', $html);
        self::assertStringContainsString('Only unexpired drafts are shown; historical policies cannot be accepted or approved', $html);
        self::assertStringContainsString('Preview data', $html);
        self::assertStringContainsString('Generate draft', $html);
        self::assertStringContainsString('data-ai-analysis-selector', $html);
        self::assertStringContainsString('<option value="clinic.activity" selected>Clinic activity</option>', $html);
        self::assertStringContainsString('<option value="clinic.complaints">Recorded clinic complaints</option>', $html);
        self::assertSame(2, substr_count($html, 'data-ai-analysis-value'));
        self::assertStringContainsString('data-ai-refresh', $html);
    }

    public function testReadOnlyAndDisabledControlsKeepTheirRestrictions(): void
    {
        $html = $this->panel(['canGenerate' => false]);
        self::assertStringNotContainsString('data-ai-action', $html);
        $html = $this->panel(['reason' => 'disabled', 'ready' => false]);
        self::assertStringContainsString('Local previews still require approval and privacy checks', $html);
        self::assertSame(2, substr_count($html, ' disabled>'));
    }

    public function testEligibilityAndPrivacyGuidanceLivesInTheSingleHowItWorksDisclosure(): void
    {
        foreach (['clinic', 'guidance', 'bmg'] as $unit) {
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
                'unit' => $unit, 'analyses' => \App\Services\Ai\AnalysisCatalog::forUnit($unit),
                'profile' => ['revision' => 'test-revision'], 'ready' => true, 'reason' => null,
                'jobs' => [], 'insights' => [], 'canGenerate' => false, 'canReview' => false, 'canApprove' => false,
                'presentationNow' => new \DateTimeImmutable('2026-10-30T12:00:00+08:00'),
            ]);
            self::assertSame(1, substr_count($html, '>How this works</summary>'));
            self::assertStringContainsString('Dates, privacy rules, and eligible records are fixed', $html);
            self::assertStringContainsString('Hidden or unavailable values are not zero', $html);
            self::assertStringContainsString('Staff approval does not publish a program', $html);
            if ($unit === 'bmg') {
                self::assertStringContainsString('eligible recorded events', $html);
                self::assertStringNotContainsString('Repeat visits by one person', $html);
            } else {
                self::assertStringContainsString('enough different people', $html);
                self::assertStringContainsString('Repeat visits by one person', $html);
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
        self::assertStringContainsString('data-ai-analysis-selector', $html);
        self::assertStringContainsString('<option value="clinic.complaints">Recorded clinic complaints</option>', $html);
        self::assertSame(2, substr_count($html, 'name="analysis_code" value="clinic.activity" data-ai-analysis-value'));
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
        foreach (['Review approved aggregate activity from August 2026 and September 2026', 'without contacting an AI service',
            'queues a separate worker', '<summary data-ai-key="how-this-works-summary">How this works</summary>',
            'enough different people', 'Repeat visits by one person',
            'individual survey answers are never sent', 'does not publish a program',
            'data-ai-feedback', 'data-ai-baseline', 'data-ai-panel', 'data-ai-review-modal'] as $copy) {
            self::assertStringContainsString($copy, $html);
        }
        self::assertStringNotContainsString('data-ai-action', $html);
        self::assertStringNotContainsString('<h1', $html);
        self::assertStringContainsString('<h2 class="h4 mb-1">Guidance AI insights</h2>', $html);
        self::assertStringContainsString('class="alert alert-info small" hidden', $html);
        self::assertStringNotContainsString('data-ai-baseline aria-live', $html);
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
            '<summary>Privacy and limits of this comparison</summary>', 'Hidden or unavailable values are not zero',
            'cannot establish cause', 'not the same as 0%', 'Synthetic trusted limitation &lt;not markup&gt;',
            '<caption>Released values for August 2026 and September 2026.'] as $copy) {
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
