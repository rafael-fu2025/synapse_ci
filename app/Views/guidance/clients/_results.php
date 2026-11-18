<?php
$clientRows = $clients ?? [];
$clientFiltered = (int) ($pagination['filtered'] ?? count($clientRows));
?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent py-3">
    <h2 class="h6 mb-0">Guidance Clients <span class="badge bg-secondary"><?= number_format($clientFiltered) ?></span></h2>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Filtered Guidance client directory</caption>
        <thead class="text-muted small text-uppercase">
          <tr>
            <th scope="col" class="ps-3">MIS ID</th>
            <th scope="col">Client</th>
            <th scope="col">Type</th>
            <th scope="col">Program / Department</th>
            <th scope="col" class="text-end pe-3">Record</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($clientRows === []): ?>
            <tr><td colspan="5" class="text-center py-5 text-muted">
              <i class="bi bi-people fs-1 d-block mb-2 opacity-50" aria-hidden="true"></i>
              No campus clients match these filters. Try another name or MIS ID.
            </td></tr>
          <?php else: ?>
            <?php foreach ($clientRows as $client): ?>
              <?php
              $clientName = trim((string) $client['last_name'] . ', ' . (string) $client['first_name'] . ' ' . (string) ($client['middle_name'] ?? ''));
              $clientProgram = ($client['kind'] ?? '') === 'student'
                  ? trim((string) ($client['program'] ?? '') . (!empty($client['year_level']) ? ' · Year ' . $client['year_level'] : ''))
                  : (string) ($client['department'] ?? '');
              ?>
              <tr>
                <td class="ps-3 fw-semibold"><?= esc((string) ($client['mis_id'] ?? '—')) ?></td>
                <td class="fw-semibold"><?= esc($clientName) ?></td>
                <td><span class="badge bg-body-secondary text-body border"><?= esc(ucfirst((string) $client['kind'])) ?></span></td>
                <td><?= esc($clientProgram !== '' ? $clientProgram : 'Not on file') ?></td>
                <td class="text-end pe-3">
                  <div class="table-card-actions">
                    <a href="<?= esc(site_url('/guidance/clients/' . (int) $client['id'])) ?>" class="btn btn-outline-primary btn-sm" aria-label="<?= esc('Open Guidance record for ' . $clientName) ?>">
                      <i class="bi bi-journal-text me-1" aria-hidden="true"></i> Open Record
                    </a>
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
    'pagination' => $pagination ?? null, 'rowCount' => count($clientRows),
    'filters' => ($filters ?? []) + ['q' => '', 'kind' => 'all'],
    'baseUrl' => $baseUrl ?? site_url('/guidance/clients'),
    'totalLabel' => 'campus clients', 'paginationLabel' => 'Guidance client directory pages',
], ['saveData' => false]) ?>
