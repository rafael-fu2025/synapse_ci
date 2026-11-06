<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\LikePattern;
use App\Models\PersonModel;
use App\Services\AuthenticatedUser;

final class PatientLookupController extends BaseController
{
    /** Read-only local directory: never searches MIS or provisions source records. */
    public function search()
    {
        $this->response->setHeader('Cache-Control', 'no-store, private');
        $actor = (new AuthenticatedUser())->current();
        $role = $actor['role'] ?? null;
        $permissions = $actor['permissions'] ?? [];
        $allowed = $role === 'superadmin'
            || (in_array($role, ['clinic_admin', 'clinic_staff'], true)
                && in_array('clinic.patients.read', $permissions, true))
            || (in_array($role, ['guidance_admin', 'guidance_supervisor', 'counsellor'], true)
                && (in_array('guidance.cases.create', $permissions, true)
                    || in_array('guidance.cases.read', $permissions, true)));
        if (!$allowed) {
            return $this->response->setStatusCode(403)->setJSON(['message' => 'Patient lookup forbidden.']);
        }

        $query = $this->request->getGet('q');
        if (!is_string($query) || mb_strlen($query) > 150 || mb_strlen(trim($query)) < 2) {
            return $this->response->setStatusCode(422)->setJSON(['message' => 'q must be a string of 2 to 150 characters.']);
        }

        $results = (new PersonModel())->allowCallbacks(false)->scoped((int) $actor['tenant_id'])
            ->select('id, mis_id, kind, first_name, middle_name, last_name');
        LikePattern::applyGroup($results->builder(), ['first_name', 'last_name', 'mis_id'], trim($query));
        $results = $results->orderBy('last_name', 'ASC')->orderBy('first_name', 'ASC')->findAll(10);

        return $this->response->setJSON(['results' => $results]);
    }
}
