<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiFailure;
use App\Services\Ai\AnalysisCatalog;
use App\Services\Ai\BroadDisclosurePolicy;
use App\Services\Ai\ChatClient;
use App\Services\Ai\DisclosurePolicy;
use App\Services\Ai\InputBudget;
use App\Services\Ai\InsightGenerator;
use App\Services\Ai\Profile;
use App\Services\Ai\ProgramLibrary;
use Config\Ai;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Synthetic aggregates and callback transport only; no framework boot, .env, DB, DNS or HTTP. */
final class AiDepartmentProgramsTest extends TestCase
{
    private static function profile(array $changes = []): Profile
    {
        $config = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        foreach (array_replace([
            'programLibraryApproved' => true,
            'capabilityRevision' => ProgramLibrary::version() . ':' . InsightGenerator::PROMPT_VERSION,
            'baseUrl' => 'https://gateway.example.test/v1',
            'allowedOrigins' => 'https://gateway.example.test',
            'model' => 'synthetic-model', 'apiKey' => 'SYNTHETIC-PRIVATE-KEY',
        ], $changes) as $key => $value) {
            $config->$key = $value;
        }

        return Profile::fromConfig($config);
    }

    private static function legacy(int $current = 240, int $previous = 200): array
    {
        return DisclosurePolicy::release(['count' => $current, 'contributors' => 20, 'unlinked' => 0],
            ['count' => $previous, 'contributors' => 20, 'unlinked' => 0], '2026-09', '2026-08');
    }

    private static function snapshot(string $analysis, array $current = [], array $previous = []): array
    {
        $aggregate = static function (array $values) use ($analysis): array {
            $descriptors = AnalysisCatalog::metrics($analysis);
            if (str_starts_with($analysis, 'bmg.')) {
                $counts = [];
                foreach ($descriptors as $d) {
                    $counts[$d['metric_code']] = $values[$d['metric_code']] ?? 20;
                }
                if ($analysis === 'bmg.rotations') {
                    $counts['rotation_reports'] = $values['rotation_reports'] ?? 100;
                }
                return ['counts' => $counts];
            }
            $total = $values[$descriptors[0]['metric_code']] ?? 1000;
            $result = ['count' => $total, 'contributors' => $total, 'unlinked' => 0];
            if ($analysis === 'guidance.activity') {
                return $result;
            }
            $buckets = [];
            $remaining = $total;
            foreach (array_slice($descriptors, 1) as $d) {
                $count = $values[$d['metric_code']] ?? 20;
                $buckets[$d['metric_code']] = $count;
                $remaining -= $count;
            }
            $buckets['__other'] = intdiv($remaining, 2);
            $buckets['__missing'] = $remaining - $buckets['__other'];
            if ($analysis === 'guidance.themes') {
                $buckets['__personal'] = 0;
                $buckets['__crisis'] = 0;
            }
            foreach ($buckets as $code => $count) {
                $result['buckets'][$code] = ['count' => $count, 'contributors' => $count,
                    'complement_contributors' => $total - $count];
            }
            return $result;
        };

        return BroadDisclosurePolicy::release($analysis, $aggregate($current), $aggregate($previous), '2026-09', '2026-08');
    }

    private static function narrative(array $changes = []): array
    {
        return array_replace([
            'schema_version' => '1', 'evidence_ids' => ['m1'],
            'summary' => 'Recorded activity changed.',
            'interpretation' => 'This descriptive comparison does not establish cause or individual need. Human review is required.',
            'alternative_explanations' => ['Recording coverage and operating days may differ.'],
            'program_ids' => [], 'abstained' => false,
        ], $changes);
    }

    private static function evidenceId(array $snapshot, string $code): string
    {
        return array_column($snapshot['metrics'], 'evidence_id', 'metric_code')[$code];
    }

    private static function validate(array $snapshot, array $narrative): array
    {
        return (new InsightGenerator(self::profile()))->validateOutput(json_encode($narrative, JSON_THROW_ON_ERROR), $snapshot);
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

    public static function qualifyingPrograms(): iterable
    {
        yield 'guidance capacity' => ['guidance.activity', 'completed_sessions', 1200, 1000, 'guidance_capacity_review'];
        yield 'guidance access' => ['guidance.activity', 'completed_sessions', 800, 1000, 'guidance_service_access_review'];
        foreach (['cough', 'colds', 'fever'] as $complaint) {
            yield $complaint => ['clinic.complaints', 'complaint_' . $complaint, 40, 20, 'clinic_respiratory_awareness'];
        }
        yield 'oral health' => ['clinic.complaints', 'complaint_toothache', 40, 20, 'clinic_oral_health_awareness'];
        foreach (['headache', 'dizziness', 'abdominal_pain', 'injury', 'nausea', 'vomiting', 'menstrual_cramps', 'checkup'] as $complaint) {
            yield $complaint => ['clinic.complaints', 'complaint_' . $complaint, 40, 20, 'clinic_wellness_review'];
        }
        yield 'academic' => ['guidance.themes', 'category_academic', 40, 20, 'guidance_academic_study_support'];
        yield 'career' => ['guidance.themes', 'category_career', 40, 20, 'guidance_career_planning'];
        foreach (['family', 'behavioral'] as $category) {
            yield $category => ['guidance.themes', 'category_' . $category, 40, 20, 'guidance_voluntary_wellbeing_review'];
        }
        yield 'batch starts' => ['bmg.batches', 'batches_started', 40, 20, 'bmg_capacity_review'];
        yield 'voided records' => ['bmg.batches', 'batches_voided', 40, 20, 'bmg_batch_record_review'];
        yield 'reported failures' => ['bmg.rotations', 'rotation_failed', 30, 20, 'bmg_qualified_maintenance_inspection'];
        yield 'reported interruptions' => ['bmg.rotations', 'rotation_interrupted', 30, 20, 'bmg_qualified_maintenance_inspection'];
    }

    #[DataProvider('qualifyingPrograms')]
    public function testProgramsRequireMatchingAnalysisMetricSignalAndCitedEvidence(
        string $analysis, string $metric, int $current, int $previous, string $program
    ): void {
        $snapshot = self::snapshot($analysis, [$metric => $current], [$metric => $previous]);
        self::assertSame([$program], array_column(ProgramLibrary::forSnapshot($snapshot), 'program_id'));
        $text = self::narrative(['evidence_ids' => [self::evidenceId($snapshot, $metric)], 'program_ids' => [$program]]);
        self::assertSame($text, self::validate($snapshot, $text));
    }

    public function testLegacyIdsAndNarrativeContractRemainValid(): void
    {
        self::assertSame('department-programs-v2', ProgramLibrary::version());
        self::assertSame('prompt-v2', InsightGenerator::PROMPT_VERSION);
        foreach ([[240, 200, 'capacity_review'], [160, 200, 'service_access_review']] as [$current, $previous, $id]) {
            $snapshot = self::legacy($current, $previous);
            self::assertSame([$id], array_column(ProgramLibrary::forSnapshot($snapshot), 'program_id'));
            $text = self::narrative(['program_ids' => [$id]]);
            self::assertSame($text, self::validate($snapshot, $text));
            foreach ([[], ['m2'], ['m1', 'm1'], ['m1', 'm2']] as $ids) {
                $this->fails('output_rejected', fn () => self::validate($snapshot, self::narrative(['evidence_ids' => $ids])));
            }
        }
        self::assertSame([], ProgramLibrary::forSnapshot(self::legacy(200, 200)));
    }

    public function testEverySelectedProgramMustIntersectItsOwnApplicableEvidence(): void
    {
        $snapshot = self::snapshot('guidance.themes', ['category_academic' => 40, 'category_career' => 40]);
        $academic = self::evidenceId($snapshot, 'category_academic');
        $career = self::evidenceId($snapshot, 'category_career');
        $programs = ['guidance_academic_study_support', 'guidance_career_planning'];
        $this->fails('output_rejected', fn () => self::validate($snapshot,
            self::narrative(['evidence_ids' => [$academic], 'program_ids' => $programs])));
        $this->fails('output_rejected', fn () => self::validate($snapshot,
            self::narrative(['evidence_ids' => ['m1'], 'program_ids' => $programs])));
        $text = self::narrative(['evidence_ids' => [$career, $academic], 'program_ids' => $programs]);
        self::assertSame($text, self::validate($snapshot, $text));
    }

    public function testEvidenceIdsMustBeANonemptyUniqueSubsetOfReleasedMetrics(): void
    {
        $snapshot = self::snapshot('guidance.themes');
        foreach ([[], ['m99'], ['m1', 'm1'], ['m1', 'm99'], [1], ['m1', null], 'm1', (object) ['0' => 'm1']] as $ids) {
            $this->fails('output_rejected', fn () => self::validate($snapshot, self::narrative(['evidence_ids' => $ids])));
        }
        $text = self::narrative(['evidence_ids' => ['m2', 'm1']]);
        self::assertSame($text, self::validate($snapshot, $text));
    }

    public function testStableNonqualifyingAndAbstainedEvidenceHasNoPrograms(): void
    {
        foreach (['clinic.complaints', 'guidance.activity', 'guidance.themes', 'bmg.batches', 'bmg.rotations'] as $analysis) {
            $snapshot = self::snapshot($analysis);
            self::assertSame([], ProgramLibrary::forSnapshot($snapshot));
            self::assertSame(self::narrative(), self::validate($snapshot, self::narrative()));
        }
        $released = self::snapshot('bmg.batches', ['batches_released' => 40]);
        self::assertSame([], ProgramLibrary::forSnapshot($released));
        $snapshot = self::snapshot('guidance.activity', ['completed_sessions' => 1200]);
        $text = self::narrative(['abstained' => true]);
        self::assertSame($text, self::validate($snapshot, $text));
        $this->fails('output_rejected', fn () => self::validate($snapshot,
            self::narrative(['abstained' => true, 'program_ids' => ['guidance_capacity_review']])));
    }

    public function testShareNotRawCountControlsCategoryEligibility(): void
    {
        $snapshot = self::snapshot('guidance.themes', ['completed_sessions' => 2000, 'category_academic' => 30],
            ['completed_sessions' => 1000, 'category_academic' => 20]);
        self::assertSame([], ProgramLibrary::forSnapshot($snapshot));
        $snapshot = self::snapshot('guidance.themes', ['completed_sessions' => 500, 'category_academic' => 20,
                    'category_career' => 10, 'category_family' => 10, 'category_behavioral' => 10],
            ['completed_sessions' => 1000, 'category_academic' => 30]);
        self::assertSame(['guidance_academic_study_support'], array_column(ProgramLibrary::forSnapshot($snapshot), 'program_id'));
    }

    public function testNoCrossDepartmentUnknownOrStigmatizingPrograms(): void
    {
        $snapshot = self::snapshot('guidance.themes', ['category_family' => 40]);
        foreach (['capacity_review', 'clinic_respiratory_awareness', 'bmg_capacity_review',
            'guidance_academic_study_support', 'guidance_discipline', 'guidance_high_risk_targeting', 'unknown'] as $id) {
            $this->fails('output_rejected', fn () => self::validate($snapshot,
                self::narrative(['evidence_ids' => [self::evidenceId($snapshot, 'category_family')], 'program_ids' => [$id]])));
        }
        $wrongUnit = array_replace($snapshot, ['department' => 'clinic']);
        self::assertSame([], ProgramLibrary::forSnapshot($wrongUnit));
        $this->fails('invalid_snapshot', fn () => self::validate($wrongUnit, self::narrative()));
    }

    public function testEligibilityCannotJoinAnUnrelatedMetricAndSignal(): void
    {
        $snapshot = self::snapshot('guidance.themes', ['category_academic' => 40]);
        $snapshot['signals'][1]['metric_code'] = 'category_career';
        self::assertSame([], ProgramLibrary::forSnapshot($snapshot));
        $this->fails('invalid_snapshot', fn () => self::validate($snapshot, self::narrative()));
        $snapshot = self::snapshot('guidance.themes', ['category_academic' => 40]);
        $snapshot['signals'][1]['evidence_id'] = 'm99';
        self::assertSame([], ProgramLibrary::forSnapshot($snapshot));
        $this->fails('invalid_snapshot', fn () => self::validate($snapshot, self::narrative()));
    }

    public function testStrictDisclosureValidationRejectsUnknownFieldsAndReferencesBeforeTransport(): void
    {
        $snapshot = self::snapshot('guidance.themes', ['category_academic' => 40]);
        $invalid = [];
        foreach (['notes' => 'PRIVATE-SENTINEL', 'tools' => [], 'actions' => [], 'patient_id' => 123] as $key => $value) {
            $invalid[] = $snapshot + [$key => $value];
        }
        foreach (['metric_code' => 'category_personal', 'evidence_id' => 'm99'] as $key => $value) {
            $copy = $snapshot;
            $copy['metrics'][1][$key] = $value;
            $invalid[] = $copy;
        }
        foreach (['signal_id' => 'do_something', 'metric_code' => 'unknown', 'evidence_id' => 'm99'] as $key => $value) {
            $copy = $snapshot;
            $copy['signals'][1][$key] = $value;
            $invalid[] = $copy;
        }
        $copy = $snapshot;
        $copy['metrics'][1]['evidence_id'] = 'm1';
        $invalid[] = $copy;
        $profile = self::profile();
        $client = new ChatClient($profile, static function (): never {
            self::fail('Invalid snapshot reached transport.');
        }, static function (): never {
            self::fail('Invalid snapshot reached resolver.');
        });
        $generator = new InsightGenerator($profile, $client);
        foreach ($invalid as $copy) {
            $this->fails('invalid_snapshot', fn () => $generator->generate($copy));
        }
    }

    public function testLibraryIsFixedReviewRequiredAndDeterministicallyCapped(): void
    {
        $snapshot = self::snapshot('clinic.complaints', [
            'complaint_cough' => 40, 'complaint_toothache' => 40, 'complaint_headache' => 40,
        ]);
        $programs = ProgramLibrary::forSnapshot($snapshot);
        self::assertSame(['clinic_respiratory_awareness', 'clinic_oral_health_awareness', 'clinic_wellness_review'],
            array_column($programs, 'program_id'));
        self::assertLessThanOrEqual(3, count($programs));
        self::assertSame($programs, ProgramLibrary::forSnapshot($snapshot));
        $all = ProgramLibrary::all();
        self::assertCount(count($all), array_unique(array_column($all, 'program_id')));
        foreach ($all as $program) {
            self::assertSame(ProgramLibrary::version(), $program['version']);
            self::assertSame('draft_template', $program['status']);
            self::assertSame('review_required', $program['reference_review_status']);
            foreach (['eligible_analysis_codes', 'eligible_metric_codes', 'eligible_signal_ids', 'activities',
                'resource_assumptions', 'evaluation_measures', 'limitations', 'references'] as $key) {
                self::assertNotEmpty($program[$key]);
                self::assertTrue(array_is_list($program[$key]));
            }
            self::assertNotEmpty($program['required_reviewer']);

        }
        $copy = $all;
        $copy[0]['objective'] = 'PRIVATE-SENTINEL';
        self::assertSame($all, ProgramLibrary::all());
    }

    public function testCompactPromptFitsDefaultBudgetWithoutPrivateDataOrTemplateBodies(): void
    {
        $generator = new InsightGenerator(self::profile());
        $complaints = [];
        foreach (array_slice(AnalysisCatalog::metrics('clinic.complaints'), 1) as $metric) {
            $complaints[$metric['metric_code']] = 40;
        }
        foreach ([self::legacy(), self::snapshot('clinic.complaints', $complaints),
            self::snapshot('guidance.themes', ['category_academic' => 40, 'category_career' => 40, 'category_family' => 40]),
            self::snapshot('bmg.batches', ['batches_started' => 40, 'batches_voided' => 40]),
            self::snapshot('bmg.rotations', ['rotation_failed' => 30])] as $snapshot) {
            $messages = $generator->messages($snapshot);
            InputBudget::assertFits($messages, self::profile()->config->maxInputTokens);
            $user = json_decode($messages[1]['content'], true, 32, JSON_THROW_ON_ERROR);
            self::assertSame(['evidence', 'programs'], array_keys($user));
            $promptEvidence = $snapshot;
            if ($snapshot['schema_version'] === '2') {
                foreach ($promptEvidence['metrics'] as &$metric) {
                    unset($metric['absolute_change'], $metric['relative_change_percent'], $metric['quality_status']);
                }
                unset($metric);
            }
            self::assertSame($promptEvidence, $user['evidence']);
            foreach ($user['programs'] as $candidate) {
                self::assertSame(['id', 'title', 'objective', 'eligible_evidence_ids'], array_keys($candidate));
                self::assertNotEmpty($candidate['eligible_evidence_ids']);
            }
            foreach (['SYNTHETIC-PRIVATE-KEY', 'patient_id', 'tenant_id', 'actor_id', 'snapshot_id',
                'contributors', '__personal', '__crisis', 'reference_id', 'https://www.who.int/', 'resource_assumptions', 'activities'] as $private) {
                self::assertStringNotContainsString($private, $messages[1]['content']);
            }
        }
    }

    public function testGenerateKeepsOuterV1AndReturnsOnlyFullServerTemplatesAfterSelection(): void
    {
        $snapshot = self::snapshot('guidance.themes', ['category_academic' => 40]);
        $text = self::narrative(['evidence_ids' => [self::evidenceId($snapshot, 'category_academic')],
            'program_ids' => ['guidance_academic_study_support']]);
        $profile = self::profile();
        $calls = 0;
        $client = new ChatClient($profile, static function ($p, $endpoint, $headers, $body, $receive) use ($text, &$calls): int {
            ++$calls;
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('tools', $payload);
            self::assertArrayNotHasKey('functions', $payload);
            $receive(json_encode(['choices' => [['index' => 0, 'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => json_encode($text, JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR));
            return 200;
        }, static fn (): array => ['8.8.8.8']);
        $draft = (new InsightGenerator($profile, $client))->generate($snapshot);
        self::assertSame(1, $calls);
        self::assertSame(['schema_version', 'evidence', 'narrative', 'programs', 'provenance'], array_keys($draft));
        self::assertSame('1', $draft['schema_version']);
        self::assertSame('2', $draft['evidence']['schema_version']);
        self::assertSame($snapshot, $draft['evidence']);
        self::assertSame($text, $draft['narrative']);
        self::assertSame(ProgramLibrary::forSnapshot($snapshot), $draft['programs']);
        self::assertNotEmpty($draft['programs'][0]['activities']);
        self::assertNotEmpty($draft['programs'][0]['references']);
        self::assertSame('prompt-v2', $draft['provenance']['prompt_version']);
        self::assertSame('department-programs-v2', $draft['provenance']['library_version']);
    }

    public function testNarrativeStillRejectsNumbersUrlsHtmlControlsExtraKeysAndProgramInjection(): void
    {
        $snapshot = self::legacy();
        foreach (['Activity rose twenty percent at https://example.test', 'Activity rose 20 percent.',
            'Activity rose ٢ percent.', '<b>Review</b>', '&lt;Review&gt;', 'See example.com.',
            "Hidden\ntext", "Hidden\u{202E}text"] as $unsafe) {
            foreach (['summary', 'interpretation', 'alternative_explanations'] as $key) {
                $this->fails('output_rejected', fn () => self::validate($snapshot,
                    self::narrative([$key => $key === 'alternative_explanations' ? [$unsafe] : $unsafe])));
            }
        }
        foreach (['tools' => [], 'actions' => [], 'notes' => 'Untrusted', 'references' => [], 'programs' => []] as $key => $value) {
            $this->fails('output_rejected', fn () => self::validate($snapshot, self::narrative() + [$key => $value]));
        }
        foreach ([['capacity_review', 'capacity_review'], ['capacity_review', 'service_access_review', 'unknown'], [123]] as $ids) {
            $this->fails('output_rejected', fn () => self::validate($snapshot, self::narrative(['program_ids' => $ids])));
        }
        $raw = substr(json_encode(self::narrative(), JSON_THROW_ON_ERROR), 0, -1) . ',"summ\\u0061ry":"Hidden"}';
        $this->fails('output_rejected', fn () => (new InsightGenerator(self::profile()))->validateOutput($raw, $snapshot));
    }

    public function testCurrentLibraryAndPromptApprovalIsStillRequiredWithoutNewFlags(): void
    {
        foreach (['clinic-operations-v1:prompt-v1', 'department-programs-v2:prompt-v1'] as $revision) {
            $this->fails('library_not_approved', fn () => (new InsightGenerator(self::profile([
                'capabilityRevision' => $revision,
            ])))->messages(self::legacy()));
        }
        $this->fails('library_not_approved', fn () => (new InsightGenerator(self::profile([
            'programLibraryApproved' => false,
        ])))->messages(self::legacy()));
    }
}
