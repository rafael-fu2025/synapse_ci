<?php
$patientRows = $patients ?? [];
$patientFiltered = (int) ($pagination['filtered'] ?? count($patientRows));
?>
<div class="cp-card">
  <div class="cp-card-header">
    <div class="cp-card-title-row">
      <h2 class="cp-card-title">Patients</h2>
      <span class="cp-card-count"><?= number_format($patientFiltered) ?></span>
    </div>
  </div>

  <div class="cp-table-wrap">
    <table class="table align-middle mb-0 clinic-patients-table cp-table" data-mobile-cards="false">
      <caption class="visually-hidden">Filtered patient directory</caption>
      <thead>
        <tr>
          <th scope="col">ID Number</th>
          <th scope="col">Full Name</th>
          <th scope="col">Type</th>
          <th scope="col">Program / Department</th>
          <th scope="col">Gender</th>
          <th scope="col">Blood Type</th>
          <th scope="col">Clinic Visits</th>
          <th scope="col" class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($patientRows === []): ?>
          <tr class="cp-empty-row">
            <td colspan="8" class="cp-empty-cell">No patients found matching your search.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($patientRows as $p): ?>
            <?php
              $patientEmail = !empty($p['email'])
                  ? $p['email']
                  : \App\Services\UniversityEmail::derive($p['first_name'] ?? null, $p['last_name'] ?? null);
              $bloodType = trim((string) ($p['blood_type'] ?? ''));
              $vCount = (int) ($p['visit_count'] ?? 0);
            ?>
            <tr class="cp-row">
              <td data-label="ID Number">
                <span class="cp-id-code"><?= esc($p['mis_id']) ?></span>
              </td>
              <td class="cp-cell-header">
                <span class="cp-person-name"><?= esc(trim($p['last_name'] . ', ' . $p['first_name'] . ' ' . ($p['middle_name'] ?? ''))) ?></span>
                <span class="cp-person-sub"><?= esc($patientEmail ?? 'No email on file') ?></span>
              </td>
              <td data-label="Type">
                <span class="cp-tag"><?= esc(ucfirst((string) $p['kind'])) ?></span>
              </td>
              <td data-label="Program / Department" class="cp-cell-full">
                <?= esc($p['program_or_dept'] ?? '—') ?>
              </td>
              <td data-label="Gender">
                <?= esc(ucfirst((string) ($p['gender'] ?? '—'))) ?>
              </td>
              <td data-label="Blood Type">
                <?php if ($bloodType !== '' && strcasecmp($bloodType, 'Unknown') !== 0): ?>
                  <span class="cp-tag cp-tag--blood"><?= esc($bloodType) ?></span>
                <?php else: ?>
                  <span class="text-muted small">Unknown</span>
                <?php endif; ?>
              </td>
              <td data-label="Clinic Visits">
                <?php if ($vCount >= 3): ?>
                  <span class="cp-status-inline cp-status-inline--warn"><span class="cp-dot" aria-hidden="true"></span><span class="cp-mono"><?= $vCount ?></span> visits</span>
                <?php else: ?>
                  <span class="cp-mono fw-semibold"><?= $vCount ?></span> <span class="text-muted small"><?= $vCount === 1 ? 'visit' : 'visits' ?></span>
                <?php endif; ?>
                <?php if (!empty($p['last_visit_at'])): ?>
                  <span class="cp-person-sub">Last: <?= esc(date('M j, Y', strtotime((string) $p['last_visit_at'] . ' UTC'))) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-end cp-cell-actions">
                <div class="table-card-actions cp-row-actions">
                  <a href="<?= esc(site_url('/clinic/patients/' . (int) $p['id'])) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-file-earmark-medical" aria-hidden="true"></i>Chart
                  </a>
                  <?php if (!empty($openVisits[$p['id']])): ?>
                    <a href="<?= esc(site_url('/clinic/encounters/' . (int) $openVisits[$p['id']])) ?>" class="btn btn-success btn-sm">Resume Visit</a>
                  <?php elseif (!empty($canStartVisit)): ?>
                    <form action="<?= esc(site_url('/clinic/encounters/start-direct/' . (int) $p['id'])) ?>" method="POST" class="d-inline">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle" aria-hidden="true"></i>Start Visit
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

  <div class="cp-pagination-band">
    <?= view('partials/table_pagination', [
        'pagination' => $pagination ?? null, 'rowCount' => count($patientRows),
        'filters' => ($filters ?? []) + ['q' => $searchQuery ?? '', 'kind' => 'all'],
        'baseUrl' => $baseUrl ?? site_url('/clinic/patients'),
        'totalLabel' => 'registered patients', 'paginationLabel' => 'Patient directory pages',
    ], ['saveData' => false]) ?>
  </div>
</div>
