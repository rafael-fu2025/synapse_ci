<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GuidanceIntakeReferralModel;
use App\Models\GuidanceSafetyAssessmentModel;
use App\Models\GuidanceSafetyEscalationModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceIntakeAndSafetyMigrationTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);
    }

    public function testTablesExistInDatabase(): void
    {
        $tables = $this->db->listTables();
        self::assertContains('guidance_intake_referrals', $tables);
        self::assertContains('guidance_safety_assessments', $tables);
        self::assertContains('guidance_safety_escalations', $tables);
    }

    public function testGuidanceIntakeReferralModelOperations(): void
    {
        $model = new GuidanceIntakeReferralModel();

        $data = [
            'tenant_id' => 1,
            'referral_number' => 'REF-2026-0001',
            'source_type' => 'employee_teacher',
            'subject_person_id' => 10,
            'referrer_user_id' => 2,
            'referrer_name_snapshot' => 'Prof. John Doe',
            'referrer_designation_snapshot' => 'Faculty Member',
            'referrer_department_snapshot' => 'College of Engineering',
            'referrer_contact_snapshot' => 'johndoe@example.com',
            'priority_hint' => 'urgent',
            'status' => 'submitted',
            'concern_catalog_version' => '1.0.0',
            'selections_cipher' => 'encrypted_selections_payload',
            'selections_nonce' => str_repeat('n', 16),
            'selections_tag' => str_repeat('t', 16),
            'selections_key_version' => 1,
            'narrative_cipher' => 'encrypted_narrative_payload',
            'narrative_nonce' => str_repeat('n', 16),
            'narrative_tag' => str_repeat('t', 16),
            'narrative_key_version' => 1,
            'submitted_at' => '2026-11-13 12:00:00',
        ];

        $id = $model->insert($data, true);
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);

        $row = $model->findByReferralNumber('REF-2026-0001');
        self::assertNotNull($row);
        self::assertSame('urgent', $row['priority_hint']);
        self::assertSame('submitted', $row['status']);
        self::assertNotEmpty($row['created_at']);
        self::assertNotEmpty($row['updated_at']);

        // Verify tenant scoping
        CurrentTenant::set(2);
        self::assertNull($model->findByReferralNumber('REF-2026-0001'));

        CurrentTenant::set(1);
        self::assertNotNull($model->findByReferralNumber('REF-2026-0001'));
    }

    public function testGuidanceSafetyAssessmentModelOperations(): void
    {
        $model = new GuidanceSafetyAssessmentModel();

        $data = [
            'tenant_id' => 1,
            'intake_referral_id' => null,
            'case_id' => null,
            'person_id' => 10,
            'assessor_user_id' => 3,
            'risk_level' => 'high',
            'self_harm_risk' => 1,
            'harm_to_others_risk' => 0,
            'indicators_cipher' => 'encrypted_indicators',
            'indicators_nonce' => str_repeat('n', 16),
            'indicators_tag' => str_repeat('t', 16),
            'indicators_key_version' => 1,
            'immediate_actions_taken' => 'Immediate de-escalation protocol initiated.',
            'safety_plan_cipher' => 'encrypted_safety_plan',
            'safety_plan_nonce' => str_repeat('s', 16),
            'safety_plan_tag' => str_repeat('s', 16),
            'safety_plan_key_version' => 1,
            'assessed_at' => '2026-11-13 12:30:00',
        ];

        $id = $model->insert($data, true);
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);

        $rows = $model->getByPersonId(10);
        self::assertCount(1, $rows);
        self::assertSame('high', $rows[0]['risk_level']);
        self::assertSame('1', (string) $rows[0]['self_harm_risk']);

        // Tenant scoping
        CurrentTenant::set(2);
        self::assertCount(0, $model->getByPersonId(10));
    }

    public function testGuidanceSafetyEscalationModelOperations(): void
    {
        $model = new GuidanceSafetyEscalationModel();

        $data = [
            'tenant_id' => 1,
            'safety_assessment_id' => 50,
            'escalated_by_user_id' => 3,
            'escalation_channel' => 'internal_crisis_team',
            'recipient_agency_or_person' => 'Crisis Response Unit Lead',
            'summary_notes' => 'Escalated to crisis team due to active suicidal ideation.',
            'occurred_at' => '2026-11-13 13:00:00',
        ];

        $id = $model->insert($data, true);
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);

        CurrentTenant::set(1);
        $escalations = $model->getByAssessmentId(50);
        self::assertCount(1, $escalations);
        self::assertSame('internal_crisis_team', $escalations[0]['escalation_channel']);

        // Tenant scoping
        CurrentTenant::set(2);
        self::assertCount(0, $model->getByAssessmentId(50));
    }

    public function testDownMigrationDropsTables(): void
    {
        $migration = new \App\Database\Migrations\CreateGuidanceIntakeAndSafetyKernel();
        $migration->down();

        $tables = $this->db->listTables();
        self::assertNotContains('guidance_safety_escalations', $tables);
        self::assertNotContains('guidance_safety_assessments', $tables);
        self::assertNotContains('guidance_intake_referrals', $tables);

        // Re-run up to leave in clean state
        $migration->up();
        $tables = $this->db->listTables();
        self::assertContains('guidance_safety_escalations', $tables);
        self::assertContains('guidance_safety_assessments', $tables);
        self::assertContains('guidance_intake_referrals', $tables);
    }
}
