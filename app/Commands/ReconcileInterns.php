<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\StudentInternService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

final class ReconcileInterns extends BaseCommand
{
    protected $group       = 'Governance';
    protected $name        = 'interns:reconcile';
    protected $description = 'Activate scheduled student intern placements and expire overdue placements across active tenants.';

    public function run(array $params)
    {
        $db = Database::connect();
        if (!$db->tableExists('student_intern_placements')) {
            CLI::error('student_intern_placements table does not exist. Run migrations first.');
            return EXIT_ERROR;
        }

        $tenants = $db->table('tenants')
            ->select('id')
            ->where('is_active', 1)
            ->where('deleted_at', null)
            ->get()->getResultArray();

        $service = new StudentInternService($db);
        $totalActivated = 0;
        $totalExpired = 0;

        foreach ($tenants as $tenant) {
            $counts = $service->reconcileTenant((int) $tenant['id']);
            $totalActivated += $counts['activated'];
            $totalExpired += $counts['expired'];
        }

        CLI::write("Intern reconciliation complete: {$totalActivated} activated, {$totalExpired} expired.", 'green');
        return EXIT_SUCCESS;
    }
}
