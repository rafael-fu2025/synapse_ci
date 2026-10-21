<?php

declare(strict_types=1);

namespace App\Services\Clinic;

use App\Models\AppointmentModel;
use App\Models\ClinicEncounterModel;
use App\Models\ClinicVitalsModel;
use App\Models\EmployeeProfileModel;
use App\Models\HealthProfileModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;

final class PatientHistoryService
{
    private PersonModel $personModel;
    private StudentProfileModel $studentModel;
    private EmployeeProfileModel $employeeModel;
    private HealthProfileModel $healthModel;
    private ClinicEncounterModel $encounterModel;
    private ClinicVitalsModel $vitalsModel;
    private AppointmentModel $appointmentModel;

    public function __construct()
    {
        $this->personModel = new PersonModel();
        $this->studentModel = new StudentProfileModel();
        $this->employeeModel = new EmployeeProfileModel();
        $this->healthModel = new HealthProfileModel();
        $this->encounterModel = new ClinicEncounterModel();
        $this->vitalsModel = new ClinicVitalsModel();
        $this->appointmentModel = new AppointmentModel();
    }

    /**
     * Builds a comprehensive patient health chart.
     *
     * @return array<string, mixed>
     */
    public function getPatientChart(int $personId, bool $includeAppointments = true): array
    {
        $person = $this->personModel->scoped()->find($personId);
        if ($person === null) {
            throw new \InvalidArgumentException("Person ID {$personId} not found.");
        }
        if (empty($person['email']) && !empty($person['first_name']) && !empty($person['last_name'])) {
            $person['email'] = \App\Services\UniversityEmail::derive(
                (string) $person['first_name'],
                (string) $person['last_name']
            );
        }

        $studentProfile = ($person['kind'] === 'student') ? $this->studentModel->select('program, year_level, section, department')->findByPersonId($personId) : null;
        $employeeProfile = ($person['kind'] === 'employee') ? $this->employeeModel->select('department, position, employment_status, is_teaching')->findByPersonId($personId) : null;
        $healthProfile = $this->healthModel->findByPersonId($personId);

        // Past encounters
        $encounters = $this->encounterModel->scoped()
            ->select('clinic_encounters.*, u.username as attending_name')
            ->join('users u', 'u.id = clinic_encounters.attending_user_id AND u.tenant_id = clinic_encounters.tenant_id', 'left')
            ->where('clinic_encounters.person_id', $personId)
            ->orderBy('clinic_encounters.started_at', 'DESC')
            ->findAll();

        // Recent vitals trend
        $vitalsHistory = $this->vitalsModel->scoped()
            ->select('clinic_vitals.*, e.started_at')
            ->join('clinic_encounters e', 'e.id = clinic_vitals.encounter_id AND e.tenant_id = clinic_vitals.tenant_id AND e.deleted_at IS NULL')
            ->where('e.person_id', $personId)
            ->orderBy('clinic_vitals.recorded_at', 'DESC')
            ->findAll(10);

        $db = \Config\Database::connect();
        $tenant = \App\Services\CurrentTenant::id();
        $treatments = $db->table('clinic_treatments t')
            ->select('t.encounter_id, t.diagnosis, t.treatment_plan, t.services_rendered')
            ->join('clinic_encounters e', 'e.id = t.encounter_id AND e.tenant_id = t.tenant_id AND e.deleted_at IS NULL')
            ->where('t.tenant_id', $tenant)->where('e.person_id', $personId)
            ->orderBy('e.started_at', 'DESC')->get()->getResultArray();
        $dispensations = $db->table('clinic_dispensations d')
            ->select('d.encounter_id, d.quantity, d.instructions, d.created_at')
            ->join('clinic_encounters e', 'e.id = d.encounter_id AND e.tenant_id = d.tenant_id AND e.deleted_at IS NULL')
            ->join('clinic_medicines m', 'm.id = d.medicine_id AND m.tenant_id = d.tenant_id')
            ->join('clinic_medicine_batches b', 'b.id = d.batch_id AND b.medicine_id = d.medicine_id AND b.tenant_id = d.tenant_id')
            ->where('d.tenant_id', $tenant)->where('e.person_id', $personId);
        if ((new InventoryService($db))->isInstalled()) {
            $dispensations->select('COALESCE(d.snapshot_name, m.name) AS medicine_name, COALESCE(d.snapshot_strength, m.strength) AS strength, COALESCE(d.snapshot_unit, m.unit_of_measure) AS unit_of_measure, COALESCE(d.snapshot_batch_number, b.batch_number) AS batch_number, correction.id AS reversed_by_operation_id, correction.reversal_mode AS correction_mode', false)
                ->join('inventory_operations correction', 'correction.reversal_of = d.operation_id AND correction.tenant_id = d.tenant_id', 'left');
        } else {
            $dispensations->select('m.name AS medicine_name, m.strength, m.unit_of_measure, b.batch_number');
        }
        $dispensations = $dispensations->orderBy('d.created_at', 'DESC')->orderBy('d.id', 'DESC')->get()->getResultArray();

        $appointments = [];
        if ($includeAppointments) {
            // Never hide an actionable request behind the capped history list.
            $activeAppointments = $this->appointmentModel->scoped()
                ->select('appointments.*')
                ->select("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', provider.first_name, provider.last_name)), ''), u.username) AS provider_name", false)
                ->join('department_staff staff', 'staff.id = appointments.staff_assignment_id AND staff.tenant_id = appointments.tenant_id AND staff.unit = appointments.unit', 'left')
                ->join('people provider', 'provider.id = staff.person_id AND provider.tenant_id = staff.tenant_id', 'left')
                ->join('users u', 'u.id = appointments.provider_user_id AND u.tenant_id = appointments.tenant_id', 'left')
                ->where('appointments.person_id', $personId)
                ->whereIn('appointments.status', ['requested', 'scheduled', 'confirmed', 'checked_in'])
                ->orderBy('appointments.starts_at', 'ASC')
                ->findAll();
            $recentAppointments = $this->appointmentModel->scoped()
                ->select('appointments.*')
                ->select("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', provider.first_name, provider.last_name)), ''), u.username) AS provider_name", false)
                ->join('department_staff staff', 'staff.id = appointments.staff_assignment_id AND staff.tenant_id = appointments.tenant_id AND staff.unit = appointments.unit', 'left')
                ->join('people provider', 'provider.id = staff.person_id AND provider.tenant_id = staff.tenant_id', 'left')
                ->join('users u', 'u.id = appointments.provider_user_id AND u.tenant_id = appointments.tenant_id', 'left')
                ->where('appointments.person_id', $personId)
                ->whereIn('appointments.status', ['completed', 'cancelled', 'no_show'])
                ->orderBy('appointments.starts_at', 'DESC')
                ->findAll(10);
            $appointments = array_merge($activeAppointments, $recentAppointments);
        }

        return [
            'person'           => $person,
            'student_profile'  => $studentProfile,
            'employee_profile' => $employeeProfile,
            'health_profile'   => $healthProfile,
            'encounters'       => $encounters,
            'vitals_history'   => $vitalsHistory,
            'appointments'     => $appointments,
            'treatment_history' => $treatments,
            'dispensation_history' => $dispensations,
        ];
    }

    /**
     * Update health profile (allergies, chronic conditions, blood type, emergency contact).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateHealthProfile(int $personId, array $data): array
    {
        $existing = $this->healthModel->findByPersonId($personId);
        $payload = [
            'tenant_id'                      => \App\Services\CurrentTenant::id(),
            'person_id'                      => $personId,
            'blood_type'                     => !empty($data['blood_type']) ? trim((string) $data['blood_type']) : null,
            'allergies'                      => !empty($data['allergies']) ? trim((string) $data['allergies']) : null,
            'chronic_conditions'             => !empty($data['chronic_conditions']) ? trim((string) $data['chronic_conditions']) : null,
            'emergency_contact_name'         => !empty($data['emergency_contact_name']) ? trim((string) $data['emergency_contact_name']) : null,
            'emergency_contact_phone'        => !empty($data['emergency_contact_phone']) ? trim((string) $data['emergency_contact_phone']) : null,
            'emergency_contact_relationship' => !empty($data['emergency_contact_relationship']) ? trim((string) $data['emergency_contact_relationship']) : null,
        ];

        if ($existing !== null) {
            $this->healthModel->update($existing['id'], $payload);
        } else {
            $this->healthModel->insert($payload);
        }

        return $this->healthModel->findByPersonId($personId);
    }
}
