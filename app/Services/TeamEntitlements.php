<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/** Builds additive mobile Personal/Work entitlements from authoritative TeamAccess. */
final class TeamEntitlements
{
    private BaseConnection $db;

    public function __construct(private TeamAccess $access, private bool $workspaceEnabled, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{personal:array{enabled:bool},work:array{enabled:bool,units:list<array<string,mixed>>}} */
    public function forIdentity(array $identity): array
    {
        return [
            'personal' => ['enabled' => $this->personalEnabled($identity)],
            'work' => $this->work(),
        ];
    }

    private function personalEnabled(array $identity): bool
    {
        $personId = (int) ($identity['person_id'] ?? 0);
        $tenantId = (int) ($identity['tenant_id'] ?? 0);
        return $personId > 0 && $tenantId > 0
            && $this->db->table('people')->where('id', $personId)->where('tenant_id', $tenantId)
                ->whereIn('kind', ['student', 'employee'])->where('deleted_at', null)->countAllResults() === 1;
    }

    /** @return array{enabled:bool,units:list<array<string,mixed>>} */
    private function work(): array
    {
        if (!$this->workspaceEnabled) {
            return ['enabled' => false, 'units' => []];
        }
        $units = [];
        foreach ([
            'clinic' => 'Clinic',
            'guidance' => 'Guidance',
            'facilities' => 'BMG Facilities',
        ] as $unit => $label) {
            try {
                $actor = $this->access->actor($unit);
            } catch (\RuntimeException $error) {
                if ($error->getCode() === 403) {
                    continue;
                }
                throw $error;
            }
            $units[] = [
                'unit' => $unit,
                'label' => $label,
                'can_manage' => TeamAccess::managesRole($unit, (string) $actor['role']),
                'features' => [
                    'team_board' => true,
                    'my_tasks' => true,
                    'team_chat' => true,
                    'team_calendar' => true,
                ],
            ];
        }
        return ['enabled' => $units !== [], 'units' => $units];
    }
}
