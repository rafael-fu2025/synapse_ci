<?php

declare(strict_types=1);

namespace Tests\Support;

trait AppointmentFixtureCleanup
{
    private array $appointmentFixtureUsers = [];
    private array $appointmentFixturePeople = [];
    private array $appointmentFixtureTenants = [];

    protected function cleanupAppointmentFixtures(): void
    {
        // Transactions cannot contain the independent-process allocation tests. Remove only
        // explicitly tracked fixture IDs, never prior-run data or fixtures owned by other suites.
        if ($this->appointmentFixturePeople !== []) {
            if ($this->db->tableExists('notifications')) {
                $this->db->table('notifications')->whereIn('recipient_person_id', $this->appointmentFixturePeople)->delete();
            }
            $this->db->table('queue_entries')->whereIn('person_id', $this->appointmentFixturePeople)->delete();
            $this->db->table('appointments')->whereIn('person_id', $this->appointmentFixturePeople)->delete();
        }
        if ($this->appointmentFixtureUsers !== []) {
            $this->db->table('availability_exceptions')->whereIn('provider_user_id', $this->appointmentFixtureUsers)->delete();
            $this->db->table('provider_availability')->whereIn('provider_user_id', $this->appointmentFixtureUsers)->delete();
            $this->db->table('users')->whereIn('id', $this->appointmentFixtureUsers)->delete();
        }
        if ($this->appointmentFixturePeople !== []) {
            $this->db->table('people')->whereIn('id', $this->appointmentFixturePeople)->delete();
        }
        if ($this->appointmentFixtureTenants !== []) {
            $this->db->table('tenants')->whereIn('id', $this->appointmentFixtureTenants)->delete();
        }
    }
}
