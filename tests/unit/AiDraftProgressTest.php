<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AnalysisCatalog;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Security;
use Config\Services;
use DOMDocument;
use DOMXPath;
use ReflectionClass;

final class AiDraftProgressTest extends CIUnitTestCase
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

    private function job(string $status, array $overrides = []): array
    {
        return $overrides + ['id' => 31, 'analysis_code' => 'clinic.activity', 'status' => $status,
            'reason' => null, 'profile_revision' => 'current', 'created_at' => '2026-09-01 00:00:00'];
    }

    private function panel(array $jobs, array $overrides = []): string
    {
        return view('ai/panel', $overrides + [
            'unit' => 'clinic', 'analyses' => AnalysisCatalog::forUnit('clinic'),
            'legacyAnalysisFixture' => false, 'profile' => ['revision' => 'current'],
            'ready' => true, 'reason' => null, 'jobs' => $jobs, 'insights' => [],
            'canGenerate' => true, 'canReview' => false, 'canApprove' => false,
        ], ['saveData' => false]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        return new DOMXPath($document);
    }

    public function testPersistedActiveAndSuccessfulStagesAreAccessibleAndIndeterminate(): void
    {
        foreach (['queued' => 'queued', 'running' => 'generating', 'succeeded' => 'ready', 'completed' => 'ready'] as $status => $stage) {
            $html = $this->panel([$this->job($status)]);
            $xpath = $this->xpath($html);
            self::assertSame(1, $xpath->query('//*[@data-ai-request="31" and @data-ai-progress="' . $stage . '"]')->length);
            self::assertSame(3, $xpath->query('//*[@data-ai-stage]')->length);
            self::assertSame(1, $xpath->query('//*[@data-ai-stage="' . $stage . '" and @aria-current="step"]')->length);
            self::assertSame($status === 'running' ? 1 : 0, $xpath->query('//*[@data-ai-progress-spinner and @role="img" and @aria-label]')->length);
            self::assertSame($status === 'queued' ? 1 : 0, $xpath->query('//form[contains(@action, "/jobs/31/cancel")]')->length);
            self::assertSame(0, $xpath->query('//*[@aria-live or @aria-valuenow or @aria-valuemin or @aria-valuemax or @role="status" or @role="progressbar"]')->length);
            self::assertStringNotContainsString('%', $html);
            self::assertStringNotContainsString('data-ai-stage-state="not-confirmed"', $html);
            if ($stage === 'ready') {
                self::assertStringContainsString('&#10003;', $html);
                self::assertStringContainsString('Ready for review. Generation and checks finished.', $html);
                self::assertStringContainsString('data-ai-pending="false"', $html);
            }
        }
    }

    public function testQueuedCopyDoesNotClaimAWorkerIsRunningOrScheduled(): void
    {
        $html = $this->panel([$this->job('queued'), $this->job('running', ['id' => 32])]);
        self::assertSame(1, substr_count($html, 'data-ai-progress-help'));
        self::assertStringContainsString('Waiting for a worker; processing has not started.', $html);
        self::assertStringContainsString('worker enabled by an administrator', $html);
        self::assertStringContainsString('cannot confirm that one is scheduled', $html);
        self::assertStringContainsString('Status updates automatically; the draft appears only after checks pass.', $html);
        self::assertStringContainsString('No partial draft is available.', $html);
    }

    public function testStoppedStatesNeverPretendToReachReviewOrExposeInternalReasons(): void
    {
        foreach (['failed', 'outcome_unknown', 'cancelled', 'expired', 'private_status'] as $status) {
            $html = $this->panel([$this->job($status, ['reason' => 'private_provider_token'])]);
            $xpath = $this->xpath($html);
            self::assertStringContainsString('data-ai-progress="stopped"', $html);
            self::assertStringContainsString('Stopped', $html);
            self::assertSame(3, $xpath->query('//*[@data-ai-stage-state="not-confirmed"]')->length);
            self::assertSame(0, $xpath->query('//*[@aria-current or @data-ai-progress-spinner or @data-ai-progress-help]')->length);
            self::assertSame(0, $xpath->query('//form[contains(@action, "/jobs/31/cancel")]')->length);
            self::assertStringNotContainsString('private_provider_token', $html);
            self::assertStringNotContainsString('private_status', $html);
            self::assertStringNotContainsString('Generation and checks finished.', $html);
            if ($status === 'outcome_unknown') {
                self::assertStringContainsString('may have processed or charged', $html);
                self::assertStringContainsString('It will not be sent again automatically.', $html);
            }
        }
    }

    public function testValidationFailureExplainsWhyNoDraftIsReady(): void
    {
        $html = $this->panel([$this->job('failed', ['reason' => 'invalid_output'])]);
        self::assertStringContainsString('The AI response did not pass validation.', $html);
        self::assertStringContainsString('No draft is ready for review.', $html);
        self::assertStringNotContainsString('invalid_output', $html);
    }

    public function testOldRevisionAndReadOnlyPermissionsPreserveExistingCancelRules(): void
    {
        $job = $this->job('queued', ['profile_revision' => 'old']);
        $html = $this->panel([$job]);
        self::assertStringContainsString('Older configuration', $html);
        self::assertStringContainsString('data-ai-pending="false"', $html);
        self::assertSame(1, $this->xpath($html)->query('//form[contains(@action, "/jobs/31/cancel")]')->length);
        $html = $this->panel([$job], ['canGenerate' => false]);
        self::assertStringNotContainsString('data-ai-action', $html);
        self::assertStringContainsString('data-ai-progress="queued"', $html);
    }

    public function testNoPendingExplanationWithoutPendingRequestsAndFormOrderIsUnchanged(): void
    {
        $html = $this->panel([]);
        self::assertStringNotContainsString('data-ai-progress-help', $html);
        $xpath = $this->xpath($html);
        $actions = [];
        foreach ($xpath->query('//form[@data-ai-action]') as $form) {
            $actions[] = basename($form->getAttribute('action'));
        }
        self::assertSame(['prepare', 'generate', 'prepare', 'generate'], $actions);
        self::assertSame(4, $xpath->query('//form/input[@name="analysis_code"]')->length);
        $html = $this->panel([], ['ready' => false, 'reason' => 'disabled']);
        self::assertSame(4, $this->xpath($html)->query('//form/button[@disabled]')->length);
    }
}
