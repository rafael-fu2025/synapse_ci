<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class WorkflowAlerts extends BaseConfig
{
    public bool $enabled = true;
    public array $kinds = [
        'appointment_reminder' => true,
        'guidance_followup_due' => true,
        'guidance_followup_overdue' => true,
        'inventory_low_stock' => true,
        'inventory_near_expiry' => true,
        'inventory_expired' => true,
        'bmg_device_offline' => true,
        'bmg_device_recovered' => true,
        'bmg_rtc_invalid' => true,
        'bmg_rtc_recovered' => true,
        'bmg_command_expired' => true,
    ];
    public int $appointmentLeadHours = 24;
    public int $nearExpiryDays = 90;
    public int $deviceOfflineMinutes = 15;
    public int $deviceFirstContactGraceMinutes = 15;
    public int $rtcReportMaxAgeMinutes = 15;
    // Rows per query, NOT a total invocation budget: each source sweeps through its captured ceiling.
    public int $batchLimit = 100;
    public int $dispatchLimit = 100;
    public int $maximumDispatchLimit = 1000;
    // WorkflowNotifications owns delivery retries and consumes this setting.
    public int $maxAttempts = 5;
}
