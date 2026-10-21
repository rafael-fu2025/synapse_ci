<?php
$patientRows = $patients ?? [];
$patientFiltered = (int) ($pagination['filtered'] ?? count($patientRows));
?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent py-3">
    <h2 class="h6 mb-0">Patients <span class="badge bg-secondary"><?= number_format($patientFiltered) ?></span></h2>
  </div>

  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Filtered patient directory</caption>
        <thead class="text-muted small text-uppercase">
          <tr>
            <th scope="col" class="ps-3">ID Number</th>
            <th scope="col">Full Name</th>
            <th scope="col">Type</th>
            <th scope="col">Program / Department</th>
            <th scope="col">Gender</th>
            <th scope="col">Blood Type</th>
            <th scope="col" class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($patientRows === []): ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">
                <i class="bi bi-people fs-1 d-block mb-2 text-muted opacity-50"></i>
                No patients found matching your search.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($patientRows as $p): ?>
              <tr>
                <td class="ps-3 fw-bold">
                  <?= esc($p['mis_id']) ?>
                </td>
                <td>
                  <div class="fw-semibold"><?= esc($p['last_name'] . ', ' . $p['first_name'] . ' ' . ($p['middle_name'] ?? '')) ?></div>
                  <?php 
                    $patientEmail = !empty($p['email']) 
                        ? $p['email'] 
                        : \App\Services\UniversityEmail::derive($p['first_name'] ?? null, $p['last_name'] ?? null);
                  ?>
                  <div class="text-muted small"><?= esc($patientEmail ?? 'No email on file') ?></div>
                </td>
                <td>
                  <span class="badge bg-body-secondary text-body border text-uppercase">
                    <?= esc($p['kind']) ?>
                  </span>
                </td>
                <td>
                  <?= esc($p['program_or_dept'] ?? '—') ?>
                </td>
                <td>
                  <?= esc(ucfirst($p['gender'] ?? '—')) ?>
                </td>
                <td>
                  <span class="badge bg-danger-subtle text-danger">
                    <?= esc($p['blood_type'] ?? 'Unknown') ?>
                  </span>
                </td>
                <td class="text-end pe-3">
                  <div class="btn-group">
                    <a href="<?= esc(site_url('/clinic/patients/' . (int) $p['id'])) ?>" class="btn btn-outline-primary btn-sm">
                      <i class="bi bi-file-earmark-medical me-1"></i> Chart
                    </a>
                    <?php if (!empty($openVisits[$p['id']])): ?>
                      <a href="<?= esc(site_url('/clinic/encounters/' . (int) $openVisits[$p['id']])) ?>" class="btn btn-primary btn-sm ms-1">Resume Visit</a>
                    <?php elseif (!empty($canStartVisit)): ?>
                                        <form action="<?= esc(site_url('/clinic/encounters/start-direct/' . (int) $p['id'])) ?>" method="POST" class="d-inline">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-primary btn-sm ms-1">
                        <i class="bi bi-plus-circle me-1"></i> Start Visit
                      </button>
                    </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?= view('partials/table_pagination', [
    'pagination' => $pagination ?? null, 'rowCount' => count($patientRows),
    'filters' => ($filters ?? []) + ['q' => $searchQuery ?? '', 'kind' => 'all'],
    'baseUrl' => $baseUrl ?? site_url('/clinic/patients'),
    'totalLabel' => 'registered patients', 'paginationLabel' => 'Patient directory pages',
], ['saveData' => false]) ?>
