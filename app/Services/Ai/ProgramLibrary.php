<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Server-owned operational drafts, not clinical programs or evidence of program efficacy. */
final class ProgramLibrary
{
    private const VERSION = 'department-programs-v2';

    public static function version(): string
    {
        return self::VERSION;
    }

    public static function all(): array
    {
        // Governance context only, never evidence of clinical or operational efficacy.
        $references = [[
            'reference_id' => 'who-ai-governance-2021',
            'title' => 'Ethics and governance of artificial intelligence for health',
            'url' => 'https://www.who.int/publications/i/item/9789240029200',
        ], [
            'reference_id' => 'who-ai-governance-2024',
            'title' => 'WHO releases AI ethics and governance guidance for large multi-modal models',
            'url' => 'https://www.who.int/news/item/18-01-2024-who-releases-ai-ethics-and-governance-guidance-for-large-multi-modal-models',
        ]];
        $common = [
            'version' => self::VERSION,
            'department' => 'clinic',
            'status' => 'draft_template',
            'audience' => 'Clinic operations staff; no patient targeting.',
            'resource_assumptions' => ['Staff review time; any staffing, scheduling, or spending change needs separate approval.'],
            'limitations' => [
                'An activity change does not establish a capacity problem, an access barrier, or clinical need.',
                'WHO references inform human oversight only; they do not endorse these templates or establish clinical efficacy.',
                'No diagnoses, treatment, medication, eligibility decisions, patient outreach, or automatic actions.',
            ],
            'required_reviewer' => 'Clinic lead',
            'reference_review_status' => 'review_required',
            'references' => $references,
        ];
        return [[
            'program_id' => 'capacity_review',
            'title' => 'Clinic capacity review',
            'eligible_analysis_codes' => ['clinic.activity'],
            'eligible_metric_codes' => ['completed_encounters'],
            'eligible_signal_ids' => ['activity_increased'],
            'objective' => 'Review whether existing operational arrangements fit the observed completed-encounter workload.',
            'activities' => [
                'Verify recording coverage, operating days, and exceptional events before interpreting the comparison.',
                'Have the clinic lead review aggregate workload and existing staffing arrangements; record assumptions and proposed options.',
            ],
            'evaluation_measures' => ['Staff-reviewed monthly completed-encounter count; document coverage and operating-day differences.'],
        ] + $common, [
            'program_id' => 'service_access_review',
            'title' => 'Clinic service-access review',
            'eligible_analysis_codes' => ['clinic.activity'],
            'eligible_metric_codes' => ['completed_encounters'],
            'eligible_signal_ids' => ['activity_decreased'],
            'objective' => 'Review service availability and recording practices without assuming why encounter activity changed.',
            'activities' => [
                'Check opening schedules, closures, attendance changes, and recording coverage using authorized operational sources.',
                'Have the clinic lead document possible operational explanations and decide whether a separately approved review is warranted.',
            ],
            'evaluation_measures' => ['Staff-reviewed monthly completed-encounter count; document availability and recording changes.'],
        ] + $common, ...self::departmentPrograms($references)];
    }

    /**
     * Pure selection over an approved snapshot; DisclosurePolicy owns disclosure/arithmetic validation.
     * Each qualifying signal must bind the analysis, metric and evidence on the same record.
     * Returned entries are fixed server templates, in library order, never model-authored prose.
     */
    public static function forSnapshot(array $snapshot): array
    {
        $eligible = [];
        foreach (self::all() as $program) {
            if (self::evidenceIds($program, $snapshot) !== []) {
                $eligible[] = $program;
                if (count($eligible) === 3) {
                    break;
                }
            }
        }

        return $eligible;
    }

    /** @return list<string> Applicable evidence for one fixed template, in metric order. */
    public static function evidenceIds(array $program, array $snapshot): array
    {
        $legacy = ($snapshot['schema_version'] ?? null) === '1';
        $analysis = $legacy ? 'clinic.activity' : ($snapshot['analysis_code'] ?? null);
        if (!in_array($snapshot['schema_version'] ?? null, ['1', '2'], true)
            || ($snapshot['department'] ?? null) !== $program['department']
            || !in_array($analysis, $program['eligible_analysis_codes'], true)) {
            return [];
        }
        $ids = [];
        foreach ($snapshot['metrics'] ?? [] as $metric) {
            if (!is_array($metric) || !is_string($metric['evidence_id'] ?? null)
                || !in_array($metric['metric_code'] ?? null, $program['eligible_metric_codes'], true)) {
                continue;
            }
            foreach ($snapshot['signals'] ?? [] as $signal) {
                if (is_array($signal) && ($signal['evidence_id'] ?? null) === $metric['evidence_id']
                    && ($legacy || ($signal['metric_code'] ?? null) === $metric['metric_code'])
                    && in_array($signal['signal_id'] ?? null, $program['eligible_signal_ids'], true)) {
                    $ids[] = $metric['evidence_id'];
                    break;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private static function departmentPrograms(array $governanceReferences): array
    {
        $respiratory = [[
            'reference_id' => 'who-seasonal-influenza-facts',
            'title' => 'Influenza (seasonal)',
            'url' => 'https://www.who.int/news-room/fact-sheets/detail/influenza-(seasonal)',
        ]];
        $oral = [[
            'reference_id' => 'who-oral-health-facts',
            'title' => 'Oral health',
            'url' => 'https://www.who.int/news-room/fact-sheets/detail/oral-health',
        ]];
        $programs = [];
        foreach (['increased' => 'capacity', 'decreased' => 'service_access'] as $direction => $kind) {
            $programs[] = self::template(
                'guidance_' . $kind . '_review', 'guidance', 'guidance.activity', ['completed_sessions'], ['activity_' . $direction],
                $kind === 'capacity' ? 'Guidance capacity review' : 'Guidance service-access review',
                'Review service availability and recording coverage against completed-session activity without assuming unmet need.',
                ['Review operating days, aggregate workload and recording practices.',
                    'Have the guidance lead document options for a separately approved service review.'],
                ['Staff-reviewed completed-session counts with coverage and operating-day differences.'],
                ['Activity does not establish capacity constraints, access barriers, individual risk or counseling need.'],
                'Guidance lead', $governanceReferences
            );
        }
        $programs[] = self::template(
            'clinic_respiratory_awareness', 'clinic', 'clinic.complaints',
            ['complaint_cough', 'complaint_colds', 'complaint_fever'], ['share_increased'],
            'Respiratory-health awareness draft',
            'Review whether broad, non-targeted respiratory-health awareness materials merit separate approval.',
            ['Verify complaint coding, source coverage and denominators before reviewing the comparison.',
                'Have a qualified clinic reviewer assess general respiratory-hygiene information for a voluntary campus-wide audience.'],
            ['Document material review and any separately approved distribution; compare aggregate complaint shares with source denominators.'],
            ['Complaint labels are not diagnoses and cannot establish an outbreak, disease prevalence, transmission or individual risk.',
                'The influenza fact sheet is background for reviewing general awareness content, not evidence that these complaints are influenza or that this draft is effective.'],
            'Clinic lead and qualified health professional', $respiratory
        );
        $programs[] = self::template(
            'clinic_oral_health_awareness', 'clinic', 'clinic.complaints', ['complaint_toothache'], ['share_increased'],
            'Oral-health awareness draft',
            'Review general oral-health awareness materials for a broad voluntary audience, without diagnosing the cause of toothache.',
            ['Verify complaint coding, recording coverage and source denominators.',
                'Have a qualified oral-health reviewer assess general education materials before any separately approved publication.'],
            ['Document reviewer approval of materials and aggregate complaint shares with source denominators.'],
            ['A toothache complaint does not establish dental disease prevalence, cause, treatment need or individual risk.',
                'The oral-health fact sheet supplies general background, not evidence of local causes or effectiveness of this draft.'],
            'Clinic lead and qualified oral-health professional', $oral
        );
        $programs[] = self::template(
            'clinic_wellness_review', 'clinic', 'clinic.complaints',
            ['complaint_headache', 'complaint_dizziness', 'complaint_abdominal_pain', 'complaint_injury',
                'complaint_nausea', 'complaint_vomiting', 'complaint_menstrual_cramps', 'complaint_checkup'], ['share_increased'],
            'General clinic wellness review',
            'Review existing general wellness information and service signposting without attributing complaint changes to a cause.',
            ['Review complaint coding, recording coverage and source denominators.',
                'Have the clinic lead assess whether existing broad, voluntary information needs a separately approved update.'],
            ['Document information review and aggregate complaint shares with their source denominators.'],
            ['Complaint changes do not establish dehydration, stress or any other cause, clinical need, disease prevalence or individual risk.'],
            'Clinic lead', $governanceReferences
        );
        $programs[] = self::template(
            'guidance_academic_study_support', 'guidance', 'guidance.themes', ['category_academic'], ['share_increased'],
            'Academic study-support review',
            'Review broad voluntary study-support information without identifying or profiling students.',
            ['Review category coding, coverage and source denominators.',
                'Have the guidance lead assess existing study-planning resources and voluntary support signposting.'],
            ['Document staff review of study-support resources and aggregate recorded category shares.'],
            ['Recorded categories do not establish academic ability, cause, individual risk or a need for targeted intervention.'],
            'Guidance lead', $governanceReferences
        );
        $programs[] = self::template(
            'guidance_career_planning', 'guidance', 'guidance.themes', ['category_career'], ['share_increased'],
            'Career-planning review',
            'Review broadly available voluntary career-planning information without inferring individual needs.',
            ['Verify category coding, recording coverage and source denominators.',
                'Have the guidance lead review existing career-planning information and voluntary service signposting.'],
            ['Document staff review of career resources and aggregate recorded category shares.'],
            ['A recorded category does not establish career readiness, employment prospects, causation or individual risk.'],
            'Guidance lead', $governanceReferences
        );
        $programs[] = self::template(
            'guidance_voluntary_wellbeing_review', 'guidance', 'guidance.themes',
            ['category_family', 'category_behavioral'], ['share_increased'],
            'Broad voluntary wellbeing review',
            'Review inclusive wellbeing information available voluntarily to everyone, without targeting or stigmatizing any group.',
            ['Verify category coding, coverage and source denominators without examining individual records.',
                'Have the guidance lead review neutral service signposting and voluntary wellbeing resources for a broad audience.'],
            ['Document an inclusivity and privacy review of broad information; review aggregate category shares only.'],
            ['Family and behavioral labels do not establish misconduct, diagnosis, family circumstances, cause or individual risk.',
                'No individual or group targeting, stigma, disciplinary decisions, compulsory participation or automated outreach.'],
            'Guidance lead', $governanceReferences
        );
        $programs[] = self::template(
            'bmg_capacity_review', 'bmg', 'bmg.batches', ['batches_started'], ['activity_increased'],
            'BMG capacity review',
            'Review recorded batch-start workload and existing operational resources without assuming a capacity problem.',
            ['Verify batch-start records, reporting coverage and operating-day differences.',
                'Have the BMG lead review workload assumptions and options requiring separate resource approval.'],
            ['Staff-reviewed batch-start counts and documented recording-coverage differences.'],
            ['Batch records do not establish compost quality, sanitation, physical processing or schedule compliance.'],
            'BMG operations lead', $governanceReferences
        );
        $programs[] = self::template(
            'bmg_batch_record_review', 'bmg', 'bmg.batches', ['batches_voided'], ['activity_increased'],
            'BMG batch-record review',
            'Review aggregate voided-batch recording practices without inferring why batches were voided.',
            ['Verify status definitions, record coverage and the comparison periods.',
                'Have the BMG lead document recording issues and decide whether a separately approved operational review is warranted.'],
            ['Staff-reviewed voided-batch counts with documented coverage and status-definition differences.'],
            ['Voided status does not establish equipment failure, compost quality, sanitation or schedule compliance.'],
            'BMG operations lead', $governanceReferences
        );
        $programs[] = self::template(
            'bmg_qualified_maintenance_inspection', 'bmg', 'bmg.rotations',
            ['rotation_failed', 'rotation_interrupted'], ['share_increased'],
            'BMG qualified maintenance-inspection review',
            'Review whether recorded rotation outcomes warrant a separately approved inspection by qualified maintenance staff.',
            ['Review outcome labels, controller-record coverage and source denominators.',
                'Have qualified maintenance staff assess the records and decide whether an authorized inspection is appropriate; no automatic equipment actions.'],
            ['Document qualified record review and aggregate recorded outcome shares with source denominators.'],
            ['Controller outcomes do not verify physical rotation, equipment condition, schedule compliance, compost quality or sanitation.',
                'No remote commands, equipment adjustments or repair instructions are authorized by this template.'],
            'BMG operations lead and qualified maintenance personnel', $governanceReferences
        );

        return $programs;
    }

    private static function template(
        string $id, string $department, string $analysis, array $metrics, array $signals,
        string $title, string $objective, array $activities, array $evaluation, array $limitations,
        string $reviewer, array $references
    ): array {
        return [
            'program_id' => $id,
            'title' => $title,
            'eligible_analysis_codes' => [$analysis],
            'eligible_metric_codes' => $metrics,
            'eligible_signal_ids' => $signals,
            'objective' => $objective,
            'activities' => $activities,
            'evaluation_measures' => $evaluation,
            'version' => self::VERSION,
            'department' => $department,
            'status' => 'draft_template',
            'audience' => $department === 'bmg' ? 'Authorized BMG operations staff.' : 'Broad voluntary audience; no individual or group targeting.',
            'resource_assumptions' => ['Qualified staff review time; any publication, spending or operational change requires separate approval.'],
            'limitations' => [...$limitations,
                'References supply background or governance context only; they do not endorse this draft or establish program efficacy.',
                'No diagnoses, treatment, eligibility decisions, individual risk assessment or automatic actions.'],
            'required_reviewer' => $reviewer,
            'reference_review_status' => 'review_required',
            'references' => $references,
        ];
    }
}
