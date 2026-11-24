<?php

declare(strict_types=1);

namespace App\Services\Archive;

use InvalidArgumentException;
use RuntimeException;

/**
 * Canonical catalog of archivable domain entities and strictly immutable compliance ledgers.
 * Confidential clinical/guidance entities mask sensitive clinical text in central archive views.
 */
final class ArchiveEntityRegistry
{
    public const MODULES = [
        'clinic'     => 'Clinic & Inventory',
        'guidance'   => 'Guidance & Counseling',
        'facilities' => 'Facilities (BMG)',
        'teams'      => 'Operational Teams',
        'admin'      => 'Administration',
    ];

    /** @var array<string, array<string, mixed>> */
    public const ENTITIES = [
        'announcement' => [
            'label'        => 'Announcement',
            'module'       => null, // derived dynamically from unit ('clinic' | 'guidance')
            'unit_scoped'  => true,
            'table'        => 'announcements',
            'mechanism'    => 'status_archived',
            'confidential' => false,
        ],
        'survey' => [
            'label'        => 'Guidance Survey',
            'module'       => 'guidance',
            'unit_scoped'  => false,
            'table'        => 'surveys',
            'mechanism'    => 'deleted_at',
            'confidential' => false,
        ],
        'question_bank' => [
            'label'        => 'Question Bank Item',
            'module'       => 'guidance',
            'unit_scoped'  => false,
            'table'        => 'survey_question_bank',
            'mechanism'    => 'status_archived',
            'confidential' => false,
        ],
        'clinic_medicine' => [
            'label'        => 'Clinic Medicine',
            'module'       => 'clinic',
            'unit_scoped'  => false,
            'table'        => 'clinic_medicines',
            'mechanism'    => 'is_active',
            'confidential' => false,
        ],
        'clinic_encounter' => [
            'label'        => 'Clinic Encounter',
            'module'       => 'clinic',
            'unit_scoped'  => false,
            'table'        => 'clinic_encounters',
            'mechanism'    => 'deleted_at',
            'confidential' => true,
        ],
        'clinic_isolation' => [
            'label'        => 'Isolation Case',
            'module'       => 'clinic',
            'unit_scoped'  => false,
            'table'        => 'clinic_isolation_cases',
            'mechanism'    => 'deleted_at',
            'confidential' => true,
        ],
        'clinic_clearance' => [
            'label'        => 'Enrollment Clearance',
            'module'       => 'clinic',
            'unit_scoped'  => false,
            'table'        => 'clinic_enrollment_clearances',
            'mechanism'    => 'deleted_at',
            'confidential' => true,
        ],
        'counselling_case' => [
            'label'        => 'Counseling Case',
            'module'       => 'guidance',
            'unit_scoped'  => false,
            'table'        => 'counselling_cases',
            'mechanism'    => 'deleted_at',
            'confidential' => true,
        ],
        'guidance_episode' => [
            'label'        => 'Guidance Episode',
            'module'       => 'guidance',
            'unit_scoped'  => false,
            'table'        => 'guidance_episodes',
            'mechanism'    => 'deleted_at',
            'confidential' => true,
        ],
        'guidance_form_instance' => [
            'label'        => 'Guidance Controlled Form',
            'module'       => 'guidance',
            'unit_scoped'  => false,
            'table'        => 'guidance_form_instances',
            'mechanism'    => 'deleted_at',
            'confidential' => true,
        ],
        'bmg_unit' => [
            'label'        => 'BMG Composting Drum',
            'module'       => 'facilities',
            'unit_scoped'  => false,
            'table'        => 'bmg_units',
            'mechanism'    => 'deleted_at',
            'confidential' => false,
        ],
        'team_task' => [
            'label'        => 'Team Task',
            'module'       => 'teams',
            'unit_scoped'  => true,
            'table'        => 'team_tasks',
            'mechanism'    => 'deleted_at',
            'confidential' => false,
        ],
        'team_event' => [
            'label'        => 'Team Calendar Event',
            'module'       => 'teams',
            'unit_scoped'  => true,
            'table'        => 'team_events',
            'mechanism'    => 'deleted_at',
            'confidential' => false,
        ],
        'carousel_slide' => [
            'label'        => 'Login Carousel Slide',
            'module'       => 'admin',
            'unit_scoped'  => false,
            'table'        => 'carousel_slides',
            'mechanism'    => 'deleted_at',
            'confidential' => false,
        ],
    ];

    /** Append-only compliance, audit, financial/stock, and clinical provenance ledgers. */
    public const IMMUTABLE_ENTITIES = [
        'audit_events'                => 'SHA-256 hash-chained audit ledger',
        'inventory_operations'        => 'Append-only clinic medicine stock operation ledger',
        'stock_movements'             => 'Append-only clinic medicine stock movement ledger',
        'clinic_inventory_operations' => 'Append-only clinic medicine stock operation ledger',
        'clinic_inventory_movements'  => 'Append-only clinic medicine stock movement ledger',
        'guidance_form_revisions'     => 'Immutable clinical/guidance form revision snapshot',
        'guidance_form_attestations'  => 'Immutable practitioner attestation / co-sign record',
        'guidance_consent_receipts'   => 'Immutable client privacy & consent receipt',
        'guidance_document_issuances' => 'Immutable document issuance & verification hash ledger',
    ];

    /** @return array<string, mixed> */
    public static function assertSupported(string $entityType): array
    {
        if (isset(self::IMMUTABLE_ENTITIES[$entityType])) {
            throw new RuntimeException('Immutable compliance ledger records cannot be archived or deleted.', 409);
        }
        if (!isset(self::ENTITIES[$entityType])) {
            throw new InvalidArgumentException('Unsupported archive entity type.', 422);
        }

        return self::ENTITIES[$entityType];
    }

    public static function label(string $entityType): string
    {
        return self::ENTITIES[$entityType]['label'] ?? ucwords(str_replace('_', ' ', $entityType));
    }

    public static function moduleLabel(string $module): string
    {
        return self::MODULES[$module] ?? ucfirst($module);
    }

    /** Resolve canonical module + optional department unit from the entity row. */
    public static function resolveModuleAndUnit(string $entityType, array $row): array
    {
        $def = self::assertSupported($entityType);
        if ($entityType === 'announcement') {
            $unit = in_array($row['unit'] ?? '', ['clinic', 'guidance'], true)
                ? (string) $row['unit']
                : 'clinic';
            return [$unit, $unit];
        }
        if (!empty($def['unit_scoped'])) {
            $unit = in_array($row['unit'] ?? '', ['clinic', 'guidance', 'facilities'], true)
                ? (string) $row['unit']
                : null;
            return [(string) $def['module'], $unit];
        }

        $module = (string) $def['module'];
        $unit = match ($module) {
            'clinic'     => 'clinic',
            'guidance'   => 'guidance',
            'facilities' => 'facilities',
            'admin'      => 'admin',
            default      => null,
        };

        return [$module, $unit];
    }

    /** Strictly non-PHI safe reference identifier suitable for audit and archive tables. */
    public static function safeRecordRef(string $entityType, array $row): string
    {
        $id = (int) ($row['id'] ?? 0);
        $raw = match ($entityType) {
            'counselling_case'       => trim((string) ($row['case_number'] ?? '')) ?: "Case #{$id}",
            'clinic_encounter'       => "Encounter #{$id}",
            'clinic_isolation'       => "Isolation Case #{$id}",
            'clinic_clearance'       => trim((string) ($row['school_year'] ?? '')) !== ''
                ? "Clearance #{$id} ({$row['school_year']} {$row['semester']})"
                : "Clearance #{$id}",
            'guidance_episode'       => "Episode #{$id}",
            'guidance_form_instance' => trim((string) ($row['instance_number'] ?? '')) ?: "Form #{$id}",
            'bmg_unit'               => trim((string) ($row['unit_code'] ?? '') . ' · ' . (string) ($row['name'] ?? ''), ' ·') ?: "Drum #{$id}",
            'clinic_medicine'        => static function () use ($row, $id): string {
                $name = trim((string) ($row['name'] ?? ''));
                $strength = trim((string) ($row['strength'] ?? ''));
                if ($name === '') {
                    return "Medicine #{$id}";
                }
                return $strength !== '' ? "{$name} ({$strength})" : $name;
            },
            'question_bank'          => "Question Bank #{$id}",
            'announcement'           => trim((string) ($row['title'] ?? '')) ?: "Announcement #{$id}",
            'survey'                 => trim((string) ($row['title'] ?? '')) ?: "Survey #{$id}",
            'team_task'              => trim((string) ($row['title'] ?? '')) ?: "Task #{$id}",
            'team_event'             => trim((string) ($row['title'] ?? '')) ?: "Event #{$id}",
            'carousel_slide'         => trim((string) ($row['title'] ?? '')) ?: "Slide #{$id}",
            default                  => ucfirst($entityType) . " #{$id}",
        };
        if ($raw instanceof \Closure) {
            $raw = $raw();
        }

        return mb_substr((string) $raw, 0, 128);
    }

    /** Non-PHI, non-clinical metadata snapshot for central Archive listings. */
    public static function safeMetadata(string $entityType, array $row): array
    {
        return match ($entityType) {
            'announcement' => [
                'audience'        => $row['audience'] ?? null,
                'previous_status' => $row['status'] ?? null,
            ],
            'survey' => [
                'audience' => $row['audience'] ?? null,
                'status'   => $row['status'] ?? null,
            ],
            'question_bank' => [
                'revision' => (int) ($row['current_revision'] ?? 1),
            ],
            'clinic_medicine' => [
                'category'    => $row['category'] ?? null,
                'dosage_form' => $row['dosage_form'] ?? null,
            ],
            'clinic_encounter' => [
                'status'       => $row['status'] ?? null,
                'confidential' => true,
            ],
            'clinic_isolation' => [
                'status'       => $row['clearance_status'] ?? null,
                'confidential' => true,
            ],
            'clinic_clearance' => [
                'status'       => $row['clearance_status'] ?? null,
                'school_year'  => $row['school_year'] ?? null,
                'semester'     => $row['semester'] ?? null,
                'confidential' => true,
            ],
            'counselling_case' => [
                'status'       => $row['status'] ?? null,
                'confidential' => true,
            ],
            'guidance_episode' => [
                'status'       => $row['status'] ?? null,
                'confidential' => true,
            ],
            'guidance_form_instance' => [
                'status'       => $row['status'] ?? null,
                'confidential' => true,
            ],
            'bmg_unit' => [
                'unit_code' => $row['unit_code'] ?? null,
            ],
            'team_task' => [
                'unit'   => $row['unit'] ?? null,
                'status' => $row['status'] ?? null,
            ],
            'team_event' => [
                'unit'   => $row['unit'] ?? null,
                'status' => $row['status'] ?? null,
            ],
            'carousel_slide' => [
                'fit_mode' => $row['fit_mode'] ?? null,
            ],
            default => [],
        };
    }

    /** Returns true when a row is already archived in its native table representation. */
    public static function isNativelyArchived(string $entityType, array $row): bool
    {
        $def = self::assertSupported($entityType);
        return match ($def['mechanism']) {
            'status_archived' => ($row['status'] ?? '') === 'archived',
            'is_active'       => (int) ($row['is_active'] ?? 1) === 0,
            default           => ($row['deleted_at'] ?? null) !== null,
        };
    }

    /** Entity types grouped cleanly for UI dropdowns/filters inside an actor's allowed modules. */
    public static function allowedEntityTypes(array $allowedModules): array
    {
        $result = [];
        foreach (self::ENTITIES as $type => $def) {
            if ($type === 'announcement') {
                if (in_array('clinic', $allowedModules, true) || in_array('guidance', $allowedModules, true)) {
                    $result[$type] = $def['label'];
                }
                continue;
            }
            if (in_array($def['module'], $allowedModules, true)) {
                $result[$type] = $def['label'];
            }
        }
        return $result;
    }
}
