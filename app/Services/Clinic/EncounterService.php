<?php

declare(strict_types=1);

namespace App\Services\Clinic;

use App\Models\ClinicDispensationModel;
use App\Models\ClinicEncounterModel;
use App\Models\ClinicMedicineBatchModel;
use App\Models\ClinicMedicineModel;
use App\Models\ClinicTreatmentModel;
use App\Models\ClinicVitalsModel;
use App\Models\PersonModel;
use App\Services\CurrentTenant;
use App\Services\Scheduling\QueueService;

final class EncounterService
{
    private ClinicEncounterModel $encounterModel;
    private ClinicVitalsModel $vitalsModel;
    private ClinicTreatmentModel $treatmentModel;
    private ClinicMedicineModel $medicineModel;
    private ClinicMedicineBatchModel $batchModel;
    private ClinicDispensationModel $dispensationModel;
    private PersonModel $personModel;
    private QueueService $queueService;
    private \CodeIgniter\Database\BaseConnection $db;

    public const VITAL_BOUNDS = [
        'bp_systolic' => [30, 300, true], 'bp_diastolic' => [10, 200, true],
        'pulse_bpm' => [20, 300, true], 'temp_c' => [25, 45, false],
        'spo2_pct' => [0, 100, true], 'weight_kg' => [0.1, 500, false],
        'height_cm' => [10, 300, false],
    ];
    public const TEXT_LIMITS = [
        'chief_complaint' => 255, 'diagnosis' => 255,
        'assessment_notes' => 10000, 'treatment_plan' => 10000,
        'services_rendered' => 10000, 'disposition_notes' => 10000,
    ];
    public const DISPOSITIONS = ['discharged', 'admitted', 'referred_to_guidance', 'sent_home', 'referred_to_hospital'];

    public function __construct(
        ?ClinicEncounterModel $encounterModel = null,
        ?ClinicVitalsModel $vitalsModel = null,
        ?ClinicTreatmentModel $treatmentModel = null,
        ?ClinicMedicineModel $medicineModel = null,
        ?ClinicMedicineBatchModel $batchModel = null,
        ?ClinicDispensationModel $dispensationModel = null,
        ?PersonModel $personModel = null,
        ?QueueService $queueService = null,
        ?\CodeIgniter\Database\BaseConnection $db = null
    ) {
        $this->db = $db ?? \Config\Database::connect();
        $this->encounterModel = $encounterModel ?? new ClinicEncounterModel($this->db);
        $this->vitalsModel = $vitalsModel ?? new ClinicVitalsModel($this->db);
        $this->treatmentModel = $treatmentModel ?? new ClinicTreatmentModel($this->db);
        $this->medicineModel = $medicineModel ?? new ClinicMedicineModel($this->db);
        $this->batchModel = $batchModel ?? new ClinicMedicineBatchModel($this->db);
        $this->dispensationModel = $dispensationModel ?? new ClinicDispensationModel($this->db);
        $this->personModel = $personModel ?? new PersonModel($this->db);
        $this->queueService = $queueService ?? new QueueService(db: $this->db);
    }

    /**
     * Start a new clinical encounter.
     *
     * @return array<string, mixed>
     */
    public function startEncounter(
        int $personId,
        int $attendingUserId,
        ?int $queueEntryId = null,
        ?int $appointmentId = null,
        ?string $chiefComplaint = null
    ): array {
        self::validateTexts(['chief_complaint' => $chiefComplaint]);
        if ($personId < 1 || $attendingUserId < 1 || ($queueEntryId !== null && $queueEntryId < 1)) {
            throw new \InvalidArgumentException('A patient, explicit actor and valid ticket are required.', 422);
        }
        $encounterId = $this->transaction(function () use ($personId, $attendingUserId, $queueEntryId, $appointmentId, $chiefComplaint): int {
            $db = $this->db;
            $tenant = CurrentTenant::id();
            // Serialize direct and queue starts even when no encounter exists yet.
            $person = $db->query('SELECT id FROM ' . $db->protectIdentifiers($db->prefixTable('people'))
                . ' WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE', [$tenant, $personId])->getRowArray();
            if ($person === null) {
                throw new \InvalidArgumentException('Patient not found.', 404);
            }
            if ($queueEntryId !== null) {
                $queueTable = $db->protectIdentifiers($db->prefixTable('queue_entries'));
                // No-show/check-in lock appointments before tickets. Discover linkage without
                // locking, then revalidate it after taking locks in that same order.
                $candidate = $db->query('SELECT * FROM ' . $queueTable
                    . ' WHERE tenant_id=? AND id=? AND unit=?', [$tenant, $queueEntryId, 'clinic'])->getRowArray();
                if ($candidate === null || (int) $candidate['person_id'] !== $personId) {
                    throw new \InvalidArgumentException('Clinic ticket not found for this patient.', 404);
                }
                $linkedAppointmentId = empty($candidate['appointment_id']) ? null : (int) $candidate['appointment_id'];
                if ($appointmentId !== null && $appointmentId !== $linkedAppointmentId) {
                    throw new \RuntimeException('Ticket appointment changed. Reload before starting a visit.', 409);
                }
                $appointment = null;
                if ($linkedAppointmentId !== null) {
                    $appointment = $db->query('SELECT * FROM ' . $db->protectIdentifiers($db->prefixTable('appointments'))
                        . ' WHERE tenant_id=? AND id=? AND unit=? AND deleted_at IS NULL FOR UPDATE',
                        [$tenant, $linkedAppointmentId, 'clinic'])->getRowArray();
                    if ($appointment === null || (int) $appointment['person_id'] !== $personId) {
                        throw new \RuntimeException('Linked Clinic appointment is unavailable for this patient.', 409);
                    }
                }
                $ticket = $db->query('SELECT * FROM ' . $queueTable
                    . ' WHERE tenant_id=? AND id=? AND unit=? FOR UPDATE', [$tenant, $queueEntryId, 'clinic'])->getRowArray();
                if ($ticket === null || (int) $ticket['person_id'] !== $personId
                    || (empty($ticket['appointment_id']) ? null : (int) $ticket['appointment_id']) !== $linkedAppointmentId) {
                    throw new \RuntimeException('Ticket linkage changed. Reload before starting a visit.', 409);
                }
                $appointmentId = $linkedAppointmentId;
                $prior = $this->encounterModel->scoped()->where('queue_entry_id', $queueEntryId)->first();
                if ($prior !== null) {
                    if ((int) $prior['person_id'] !== $personId
                        || (empty($prior['appointment_id']) ? null : (int) $prior['appointment_id']) !== $appointmentId) {
                        throw new \RuntimeException('Ticket linkage differs from the existing visit.', 409);
                    }
                    return (int) $prior['id'];
                }
                if ($appointment !== null && $appointment['status'] !== 'checked_in') {
                    throw new \RuntimeException('Linked Clinic appointment is not checked in.', 409);
                }
                if (!in_array($ticket['status'], ['called', 'in_consultation'], true)) {
                    throw new \RuntimeException('Call this Clinic ticket before starting a visit.', 409);
                }
            } elseif ($appointmentId !== null) {
                throw new \InvalidArgumentException('Appointments must be started through their Clinic ticket.', 422);
            }
            $open = $this->encounterModel->scoped()->where('person_id', $personId)->where('status', 'in_progress')->first();
            if ($open !== null) {
                if ($queueEntryId !== null && (int) ($open['queue_entry_id'] ?? 0) !== $queueEntryId) {
                    throw new \RuntimeException('This patient already has an open visit. Resume it before starting another ticket.', 409);
                }
                return (int) $open['id'];
            }
            $now = date('Y-m-d H:i:s');
            $encounterId = $this->encounterModel->insert([
                'tenant_id'         => CurrentTenant::id(),
                'person_id'         => $personId,
                'attending_user_id' => $attendingUserId,
                'appointment_id'    => $appointmentId,
                'queue_entry_id'    => $queueEntryId,
                'status'            => 'in_progress',
                'chief_complaint'   => $chiefComplaint,
                'started_at'        => $now,
            ]);

            if ($encounterId === false || (int) $encounterId < 1) {
                throw new \RuntimeException('Could not create encounter.');
            }
            // QueueService must join this connection's transaction, never commit independently.
            if ($queueEntryId !== null) {
                $this->queueService->startConsultation($queueEntryId, 'clinic');
            }
            return (int) $encounterId;
        });
        return $this->getEncounterDetails($encounterId);
    }

    /**
     * Record or update patient vitals for an encounter.
     * Automatically calculates BMI if height and weight are provided.
     *
     * @param array{
     *     bp_systolic?: ?int,
     *     bp_diastolic?: ?int,
     *     pulse_bpm?: ?int,
     *     temp_c?: ?float,
     *     spo2_pct?: ?int,
     *     weight_kg?: ?float,
     *     height_cm?: ?float
     * } $vitals
     * @return array<string, mixed>
     */
    public function recordVitals(int $encounterId, array $vitals, int $recordedByUserId): array
    {
        $vitals = self::validateVitals($vitals);
        return $this->transaction(function () use ($encounterId, $vitals, $recordedByUserId): array {
            $this->lockOpenEncounter($encounterId);
            if ($recordedByUserId < 1) {
                throw new \InvalidArgumentException('An explicit vitals recorder is required.', 422);
            }
            $weight = $vitals['weight_kg'];
            $height = !empty($vitals['height_cm']) ? (float) $vitals['height_cm'] : null;

            $bmi = null;
            if ($weight !== null && $height !== null && $height > 0) {
                $heightMeters = $height / 100.0;
                $bmi = round($weight / ($heightMeters * $heightMeters), 2);
            }

            $now = date('Y-m-d H:i:s');
            $existing = $this->vitalsModel->findByEncounterId($encounterId);

            $data = [
                'tenant_id'           => CurrentTenant::id(),
                'encounter_id'        => $encounterId,
                'bp_systolic'         => !empty($vitals['bp_systolic']) ? (int) $vitals['bp_systolic'] : null,
                'bp_diastolic'        => !empty($vitals['bp_diastolic']) ? (int) $vitals['bp_diastolic'] : null,
                'pulse_bpm'           => !empty($vitals['pulse_bpm']) ? (int) $vitals['pulse_bpm'] : null,
                'temp_c'              => !empty($vitals['temp_c']) ? (float) $vitals['temp_c'] : null,
                'spo2_pct'            => $vitals['spo2_pct'],
                'weight_kg'           => $weight,
                'height_cm'           => $height,
                'bmi'                 => $bmi,
                'recorded_by_user_id' => $recordedByUserId,
                'recorded_at'         => $now,
            ];

            $saved = $existing !== null
                ? $this->vitalsModel->scoped()->update($existing['id'], $data)
                : $this->vitalsModel->insert($data);
            if ($saved === false) {
                throw new \RuntimeException('Could not save vitals.');
            }

            return $this->vitalsModel->findByEncounterId($encounterId);
        });
    }

    /**
     * Record chief complaint, clinical assessment, and treatment plan.
     */
    public function recordAssessment(
        int $encounterId,
        ?string $chiefComplaint = null,
        ?string $assessmentNotes = null,
        ?string $diagnosis = null,
        ?string $treatmentPlan = null,
        ?string $servicesRendered = null
    ): array {
        self::validateTexts(['chief_complaint' => $chiefComplaint, 'assessment_notes' => $assessmentNotes,
            'diagnosis' => $diagnosis, 'treatment_plan' => $treatmentPlan, 'services_rendered' => $servicesRendered]);
        return $this->transaction(function () use ($encounterId, $chiefComplaint, $assessmentNotes, $diagnosis, $treatmentPlan, $servicesRendered): array {
            $this->lockOpenEncounter($encounterId);
            // Update encounter level notes
            $encounterUpdates = [];
            if ($chiefComplaint !== null) {
                $encounterUpdates['chief_complaint'] = trim($chiefComplaint);
            }
            if ($assessmentNotes !== null) {
                $encounterUpdates['assessment_notes'] = trim($assessmentNotes);
            }
            if ($encounterUpdates !== [] && !$this->encounterModel->scoped()->update($encounterId, $encounterUpdates)) {
                throw new \RuntimeException('Could not save assessment.');
            }

            // Upsert treatment record
            $existingTreatment = $this->treatmentModel->findByEncounterId($encounterId);
            $treatmentData = [
                'tenant_id'         => CurrentTenant::id(),
                'encounter_id'      => $encounterId,
                'diagnosis'         => $diagnosis ? trim($diagnosis) : null,
                'treatment_plan'    => $treatmentPlan ? trim($treatmentPlan) : null,
                'services_rendered' => $servicesRendered ? trim($servicesRendered) : null,
            ];

            $saved = $existingTreatment !== null
                ? $this->treatmentModel->scoped()->update($existingTreatment['id'], $treatmentData)
                : $this->treatmentModel->insert($treatmentData);
            if ($saved === false) {
                throw new \RuntimeException('Could not save treatment.');
            }

            return $this->getEncounterDetails($encounterId);
        });
    }

    /**
     * @deprecated Use InventoryService::dispense() with an explicit actor and idempotency key.
     * Compatibility adapter for legacy integrations; stock logic lives exclusively in InventoryService.
     *
     * Dispense medicine using FEFO (First-Expired, First-Out).
     * Automatically allocates from the earliest-expiring batch first and adjusts inventory stock.
     *
     * @return array<int, array<string, mixed>> Created dispensation records
     */
    public function dispenseMedicine(
        int $encounterId,
        int $medicineId,
        mixed $requestedQuantity,
        ?string $instructions = null,
        int $dispensedByUserId = 0,
        ?string $idempotencyKey = null
    ): array {
        if ($dispensedByUserId <= 0 || $idempotencyKey === null || $idempotencyKey === '') {
            throw new \InvalidArgumentException('Dispensing requires an explicit actor and idempotency key.', 422);
        }
        return (new InventoryService($this->db))->dispense($encounterId, $medicineId, $requestedQuantity, $instructions, $dispensedByUserId, $idempotencyKey)['allocations'];
    }

    /**
     * Complete an encounter with final outcome and disposition.
     */
    public function completeEncounter(
        int $encounterId,
        string $disposition,
        ?string $dispositionNotes = null,
        ?string $followUpDate = null
    ): array {
        if (!in_array($disposition, self::DISPOSITIONS, true)) {
            throw new \InvalidArgumentException('Invalid clinical disposition.', 422);
        }
        self::validateTexts(['disposition_notes' => $dispositionNotes]);
        if ($followUpDate !== null && $followUpDate !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $followUpDate);
            if ($date === false || $date->format('Y-m-d') !== $followUpDate) {
                throw new \InvalidArgumentException('Follow-up date must be a valid YYYY-MM-DD date.', 422);
            }
        }
        $db = $this->db;
        // Completion and dispensing serialize on the same encounter row, before inventory locks.
        if ($db->transDepth !== 0) {
            throw new \RuntimeException('Encounter completion cannot nest transactions.');
        }
        $db->resetTransStatus();
        if ($db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || !$db->transBegin()) {
            throw new \RuntimeException('Could not begin encounter completion.');
        }
        try {
            $encounter = $db->query('SELECT * FROM ' . $db->protectIdentifiers($db->prefixTable('clinic_encounters')) . ' WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE', [CurrentTenant::id(), $encounterId])->getRowArray();
            if ($encounter === null) {
                throw new \InvalidArgumentException("Encounter ID {$encounterId} not found.");
            }
            if ($encounter['status'] !== 'in_progress') {
                throw new \RuntimeException('Encounter is no longer open.', 409);
            }

            $now = date('Y-m-d H:i:s');
            $status = ($disposition === 'referred_to_guidance') ? 'referred' : 'completed';
            if (!$this->encounterModel->scoped()->update($encounterId, [
                'status'            => $status,
                'disposition'       => $disposition,
                'disposition_notes' => $dispositionNotes ? trim($dispositionNotes) : null,
                'follow_up_date'    => !empty($followUpDate) ? $followUpDate : null,
                'completed_at'      => $now,
            ])) {
                throw new \RuntimeException('Could not update encounter.');
            }

            if (!empty($encounter['queue_entry_id'])) {
                $this->queueService->completeConsultation((int) $encounter['queue_entry_id'], 'clinic');
            }
            if (!$db->transStatus() || !$db->transCommit()) {
                throw new \RuntimeException('Could not complete encounter.');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();
            throw $e;
        }
        return $this->getEncounterDetails($encounterId);
    }

    private static function validateTexts(array $values): void
    {
        foreach ($values as $field => $value) {
            if ($value !== null && (mb_strlen($value) > self::TEXT_LIMITS[$field] || strlen($value) > 65535)) {
                throw new \InvalidArgumentException($field . ' exceeds the allowed length.', 422);
            }
        }
    }

    private static function validateVitals(array $values): array
    {
        $result = [];
        foreach (self::VITAL_BOUNDS as $field => [$min, $max, $integer]) {
            $value = $values[$field] ?? null;
            if ($value === null || $value === '') {
                $result[$field] = null;
                continue;
            }
            if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value)
                || !is_finite((float) $value) || (float) $value < $min || (float) $value > $max
                || ($integer && floor((float) $value) !== (float) $value)) {
                throw new \InvalidArgumentException('Invalid ' . $field . ': expected ' . $min . ' to ' . $max . ($integer ? ' (whole number).' : '.'), 422);
            }
            $result[$field] = $integer ? (int) $value : (float) $value;
        }
        if ($result['weight_kg'] !== null && $result['height_cm'] !== null
            && round($result['weight_kg'] / (($result['height_cm'] / 100) ** 2), 2) > 999.99) {
            throw new \InvalidArgumentException('Height and weight produce a BMI outside the supported range.', 422);
        }
        return $result;
    }

    private function lockOpenEncounter(int $id): array
    {
        $db = $this->db;
        $encounter = $db->query('SELECT * FROM ' . $db->protectIdentifiers($db->prefixTable('clinic_encounters'))
            . ' WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE', [CurrentTenant::id(), $id])->getRowArray();
        if ($encounter === null) {
            throw new \InvalidArgumentException('Encounter not found.', 404);
        }
        if ($encounter['status'] !== 'in_progress') {
            throw new \RuntimeException('Encounter is no longer open.', 409);
        }
        return $encounter;
    }

    private function transaction(callable $operation): mixed
    {
        $db = $this->db;
        if ($db->transDepth !== 0) {
            throw new \RuntimeException('Clinic changes require an independent transaction.');
        }
        $db->resetTransStatus();
        if (!$db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') || !$db->transBegin()) {
            throw new \RuntimeException('Could not begin Clinic transaction.');
        }
        try {
            $result = $operation();
            if (!$db->transStatus() || !$db->transCommit()) {
                throw new \RuntimeException('Could not commit Clinic changes.');
            }
            return $result;
        } catch (\Throwable $e) {
            try {
                $db->transRollback();
            } finally {
                $db->resetTransStatus();
            }
            throw $e;
        }
    }

    /**
     * Retrieve complete encounter package: encounter, patient, vitals, treatment, dispensations.
     *
     * @return array<string, mixed>
     */
    public function getEncounterDetails(int $encounterId): array
    {
        $encounter = $this->encounterModel->scoped()->find($encounterId);
        if ($encounter === null) {
            throw new \InvalidArgumentException("Encounter ID {$encounterId} not found.");
        }

        $patient = $this->personModel->scoped()->find($encounter['person_id']);
        if ($patient === null) {
            throw new \InvalidArgumentException('Encounter patient is unavailable.', 404);
        }
        $vitals = $this->vitalsModel->findByEncounterId($encounterId);
        $treatment = $this->treatmentModel->findByEncounterId($encounterId);
        $dispensations = $this->dispensationModel->getForEncounter($encounterId);

        return [
            'encounter'     => $encounter,
            'patient'       => $patient,
            'vitals'        => $vitals,
            'treatment'     => $treatment,
            'dispensations' => $dispensations,
        ];
    }
}
