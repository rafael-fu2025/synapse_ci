<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ClinicDispensationModel;
use App\Models\ClinicEncounterModel;
use App\Models\ClinicMedicineBatchModel;
use App\Models\ClinicMedicineModel;
use App\Models\ClinicTreatmentModel;
use App\Models\ClinicVitalsModel;
use App\Models\PersonModel;
use App\Models\QueueEntryModel;
use App\Models\UserModel;
use App\Services\Clinic\EncounterService;
use App\Services\Scheduling\QueueService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class EncounterServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private EncounterService $encounterService;
    private ClinicEncounterModel $encounterModel;
    private ClinicVitalsModel $vitalsModel;
    private ClinicMedicineModel $medicineModel;
    private ClinicMedicineBatchModel $batchModel;
    private ClinicDispensationModel $dispensationModel;
    private PersonModel $personModel;
    private QueueService $queueService;
    private int $patientId;
    private int $staffUserId;

    protected function setUp(): void
    {
        \Tests\Support\InventoryTestDatabase::verify();
        parent::setUp();

        $this->encounterModel = new ClinicEncounterModel();
        $this->vitalsModel = new ClinicVitalsModel();
        $this->medicineModel = new ClinicMedicineModel();
        $this->batchModel = new ClinicMedicineBatchModel();
        $this->dispensationModel = new ClinicDispensationModel();
        $this->personModel = new PersonModel();
        $this->queueService = new QueueService();

        $this->encounterService = new EncounterService(
            $this->encounterModel,
            $this->vitalsModel,
            new ClinicTreatmentModel(),
            $this->medicineModel,
            $this->batchModel,
            $this->dispensationModel,
            $this->personModel,
            $this->queueService
        );

        $unique = uniqid();
        $userModel = new UserModel();
        $this->staffUserId = (int) $userModel->insert([
            'tenant_id' => 1,
            'username'  => 'nurse_' . $unique,
            'role'      => 'clinic_staff',
            'status'    => 'active',
        ]);

        $this->patientId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'STU_' . $unique,
            'first_name' => 'Roberto',
            'last_name'  => 'Gomez',
        ]);
    }

    public function testStartEncounterAdvancesQueueTicket(): void
    {
        $queueEntry = $this->queueService->enqueueWalkIn('clinic', $this->patientId, 'routine');
        $this->assertSame('waiting', $queueEntry['status']);

        $pkg = $this->encounterService->startEncounter(
            $this->patientId,
            $this->staffUserId,
            queueEntryId: (int) $queueEntry['id'],
            chiefComplaint: 'Severe headache'
        );

        $this->assertNotNull($pkg['encounter']);
        $this->assertSame('in_progress', $pkg['encounter']['status']);
        $this->assertSame('Severe headache', $pkg['encounter']['chief_complaint']);

        // Queue ticket state must transition to in_consultation
        $queueModel = new QueueEntryModel();
        $ticket = $queueModel->find($queueEntry['id']);
        $this->assertSame('in_consultation', $ticket['status']);
    }

    public function testRecordVitalsCalculatesBmi(): void
    {
        $pkg = $this->encounterService->startEncounter($this->patientId, $this->staffUserId);
        $encId = (int) $pkg['encounter']['id'];

        // Height 170 cm, Weight 68 kg -> BMI = 68 / (1.7^2) = 23.53
        $vitals = $this->encounterService->recordVitals($encId, [
            'bp_systolic'  => 120,
            'bp_diastolic' => 80,
            'pulse_bpm'    => 75,
            'temp_c'       => 36.8,
            'spo2_pct'     => 99,
            'height_cm'    => 170.0,
            'weight_kg'    => 68.0,
        ], $this->staffUserId);

        $this->assertNotNull($vitals);
        $this->assertSame(120, (int) $vitals['bp_systolic']);
        $this->assertSame(80, (int) $vitals['bp_diastolic']);
        $this->assertSame('23.53', (string) $vitals['bmi']);
    }

    public function testFefoMedicineDispensingOrder(): void
    {
        $inventory = new \App\Services\Clinic\InventoryService();
        $managerId = (int) (new UserModel())->insert([
            'tenant_id'=>1, 'username'=>'inventory_manager_' . uniqid(), 'role'=>'superadmin', 'status'=>'active',
        ]);
        $medicine = $inventory->saveMedicine(null, [
            'name'=>'Test Amoxicillin ' . uniqid(), 'generic_name'=>'Amoxicillin',
            'category'=>'Antibiotic', 'dosage_form'=>'Capsule', 'strength'=>'500mg',
            'unit_of_measure'=>'capsule', 'minimum_stock_level'=>10,
        ], $managerId);
        $medId = (int) $medicine['id'];
        $first = $inventory->receive($medId, [
            'batch_number'=>'EXP-SOON', 'expiration_date'=>date('Y-m-d', strtotime('+2 months')), 'quantity'=>10,
        ], $managerId, 'encounter-soon-' . $medId);
        $second = $inventory->receive($medId, [
            'batch_number'=>'EXP-LATER', 'expiration_date'=>date('Y-m-d', strtotime('+10 months')), 'quantity'=>20,
        ], $managerId, 'encounter-later-' . $medId);
        $batch1Id = (int) $first['movements'][0]['batch_id'];
        $batch2Id = (int) $second['movements'][0]['batch_id'];

        $pkg = $this->encounterService->startEncounter($this->patientId, $this->staffUserId);
        $encId = (int) $pkg['encounter']['id'];

        // Request 15 capsules -> must exhaust all 10 from Batch 1, and take 5 from Batch 2
        $dispensations = $this->encounterService->dispenseMedicine(
            $encId,
            $medId,
            15,
            '1 cap TID for 5 days',
            $managerId,
            'encounter-dispense-' . $medId
        );

        $this->assertCount(2, $dispensations);

        // First allocation: from earliest-expiring batch
        $this->assertSame($batch1Id, (int) $dispensations[0]['batch_id']);
        $this->assertSame(10, (int) $dispensations[0]['quantity']);

        // Second allocation: remaining 5 from next batch
        $this->assertSame($batch2Id, (int) $dispensations[1]['batch_id']);
        $this->assertSame(5, (int) $dispensations[1]['quantity']);

        // Check remaining batch quantities
        $b1 = $this->batchModel->find($batch1Id);
        $b2 = $this->batchModel->find($batch2Id);
        $this->assertSame(0, (int) $b1['remaining_quantity']);
        $this->assertSame(15, (int) $b2['remaining_quantity']);

        // Check medicine total stock
        $med = $this->medicineModel->find($medId);
        $this->assertSame(15, (int) $med['total_stock']);
    }

    public function testCompleteEncounterMarksQueueDone(): void
    {
        $queueEntry = $this->queueService->enqueueWalkIn('clinic', $this->patientId, 'routine');
        $pkg = $this->encounterService->startEncounter($this->patientId, $this->staffUserId, queueEntryId: (int) $queueEntry['id']);
        $encId = (int) $pkg['encounter']['id'];

        $completed = $this->encounterService->completeEncounter(
            $encId,
            'discharged',
            dispositionNotes: 'Rest advised',
            followUpDate: date('Y-m-d', strtotime('+3 days'))
        );

        $this->assertSame('completed', $completed['encounter']['status']);
        $this->assertSame('discharged', $completed['encounter']['disposition']);
        $this->assertNotNull($completed['encounter']['completed_at']);

        // Ticket must be marked done
        $queueModel = new QueueEntryModel();
        $ticket = $queueModel->find($queueEntry['id']);
        $this->assertSame('done', $ticket['status']);
    }
}
