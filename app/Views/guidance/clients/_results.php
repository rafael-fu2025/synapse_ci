<?php
$clientRows = $clients ?? [];
$clientFiltered = (int) ($pagination['filtered'] ?? count($clientRows));
?>
<div class="cp-card">
  <div class="cp-card-header">
    <div class="cp-card-title-row">
      <h2 class="cp-card-title">Guidance Clients</h2>
      <span class="cp-card-count"><?= number_format($clientFiltered) ?></span>
    </div>
  </div>

  <div class="cp-table-wrap">
    <table class="table align-middle mb-0 cp-table" data-mobile-cards="false">
      <caption class="visually-hidden">Filtered Guidance client directory</caption>
      <thead>
        <tr>
          <th scope="col">MIS ID</th>
          <th scope="col">Client</th>
          <th scope="col">Type</th>
          <th scope="col">Program / Department</th>
          <th scope="col" class="text-end">Record</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($clientRows === []): ?>
          <tr class="cp-empty-row">
            <td colspan="5" class="cp-empty-cell">No campus clients match these filters. Try another name or MIS ID.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($clientRows as $client): ?>
            <?php
            $clientName = trim((string) ($client['last_name'] ?? '') . ', ' . (string) ($client['first_name'] ?? '') . ' ' . (string) ($client['middle_name'] ?? ''));
            $clientEmail = !empty($client['email'])
                ? (string) $client['email']
                : (class_exists(\App\Services\UniversityEmail::class)
                    ? \App\Services\UniversityEmail::derive($client['first_name'] ?? null, $client['last_name'] ?? null)
                    : null);
            $clientProgram = ($client['kind'] ?? '') === 'student'
                ? trim((string) ($client['program'] ?? '') . (!empty($client['year_level']) ? ' · Year ' . (int) $client['year_level'] : ''))
                : (string) ($client['department'] ?? '');
            ?>
            <tr class="cp-row">
              <td data-label="MIS ID">
                <span class="cp-id-code"><?= esc((string) ($client['mis_id'] ?? '—')) ?></span>
              </td>
              <td class="cp-cell-header">
                <span class="cp-person-name"><?= esc($clientName) ?></span>
                <?php if ($clientEmail !== null && $clientEmail !== ''): ?>
                  <span class="cp-person-sub"><?= esc($clientEmail) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Type">
                <span class="cp-tag"><?= esc(ucfirst((string) ($client['kind'] ?? 'member'))) ?></span>
              </td>
              <td data-label="Program / Department" class="cp-cell-full">
                <?= esc($clientProgram !== '' ? $clientProgram : 'Not on file') ?>
              </td>
              <td class="text-end cp-cell-actions">
                <div class="table-card-actions cp-row-actions">
                  <a href="<?= esc(site_url('/guidance/clients/' . (int) $client['id'])) ?>" class="btn btn-outline-secondary btn-sm" aria-label="<?= esc('Open Guidance record for ' . $clientName) ?>">
                    <i class="bi bi-journal-text" aria-hidden="true"></i>Open Record
                  </a>
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
        'pagination' => $pagination ?? null, 'rowCount' => count($clientRows),
        'filters' => ($filters ?? []) + ['q' => '', 'kind' => 'all'],
        'baseUrl' => $baseUrl ?? site_url('/guidance/clients'),
        'totalLabel' => 'campus clients', 'paginationLabel' => 'Guidance client directory pages',
    ], ['saveData' => false]) ?>
  </div>
</div>
