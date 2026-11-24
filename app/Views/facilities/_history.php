<?php
$commandsPagination ??= ['page' => 1, 'per_page' => 20, 'total' => count($commands)];
$runsPagination ??= ['page' => 1, 'per_page' => 20, 'total' => count($runs)];
$batchesPagination ??= ['page' => 1, 'per_page' => 50, 'total' => count($pastBatches)];
$historyQuery ??= [
    'commands_page' => $commandsPagination['page'], 'commands_per_page' => $commandsPagination['per_page'],
    'runs_page' => $runsPagination['page'], 'runs_per_page' => $runsPagination['per_page'],
    'batches_page' => $batchesPagination['page'], 'batches_per_page' => $batchesPagination['per_page'],
];
$historyBaseUrl = site_url('/facilities/unit/' . (int) $unit['id']);
$formatBatchTime ??= static function (?string $utc): string {
    if ($utc === null || $utc === '') {
        return 'Not recorded';
    }
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
    if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $utc) {
        return $utc;
    }
    return \App\Services\Scheduling\SchedulingClock::formatManila($utc, 'Y-m-d H:i:s');
};
?>
<div class="bmg-history-grid" id="bmg-history">
  <section class="card ce-card bmg-panel mb-4" aria-labelledby="bmg-command-history-title">
    <div class="card-header bmg-panel-heading"><h2 class="card-title" id="bmg-command-history-title"><i class="bi bi-terminal" aria-hidden="true"></i>Recent command requests</h2><span class="ce-tag bmg-number"><?= number_format((int) $commandsPagination['total']) ?> total</span></div>
    <div class="card-body bmg-panel-body">
      <p class="bmg-caption"><?= (int) $commandsPagination['per_page'] ?> requests per page, newest first. Status and device acknowledgements do not verify physical movement.</p>
      <div class="bmg-table-wrap table-responsive" data-mobile-cards="false">
        <table class="table table-hover align-middle bmg-history-table bmg-command-table"><caption class="visually-hidden">Recent command requests for this drum</caption><thead><tr><th scope="col">Requested (Asia/Manila)</th><th scope="col">Command</th><th scope="col">Rotations</th><th scope="col">Status</th><th scope="col">Device acknowledgement</th><th scope="col">Expires</th></tr></thead><tbody>
          <?php if ($commands === []): ?><tr><td colspan="6" class="bmg-empty">No commands requested yet.</td></tr><?php endif; ?>
          <?php foreach ($commands as $command): ?>
          <tr class="bmg-history-row">
            <td data-label="Requested (Asia/Manila)"><?= esc($command['created_at']) ?></td>
            <td data-label="Command"><span class="bmg-code"><?= esc($command['command']) ?></span></td>
            <td data-label="Rotations" class="bmg-number"><?= isset($command['rotations']) ? (int) $command['rotations'] . ' rot' : '—' ?></td>
            <td data-label="Status"><span class="ce-tag"><?= esc($command['status']) ?></span></td>
            <td data-label="Device acknowledgement"><?= esc($command['ack_status'] ?? 'Not reported') ?><?php if (!empty($command['ack_message'])): ?><br><?= esc($command['ack_message']) ?><?php endif; ?><?php if (!empty($command['acknowledged_at'])): ?><br><?= esc($command['acknowledged_at']) ?><?php endif; ?></td>
            <td data-label="Expires"><?= esc($command['expires_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
      <?= view('partials/table_pagination', [
          'pagination' => $commandsPagination, 'filters' => [], 'baseUrl' => $historyBaseUrl,
          'paginationPageParam' => 'commands_page', 'paginationPerPageParam' => 'commands_per_page',
          'paginationExtraQuery' => $historyQuery, 'paginationLabel' => 'Drum command history pages',
          'paginationFragment' => 'bmg-history', 'paginationNavigation' => true,
      ], ['saveData' => false]) ?>
    </div>
  </section>
  <section class="card ce-card bmg-panel mb-4" aria-labelledby="bmg-motor-history-title">
    <div class="card-header bmg-panel-heading"><h2 class="card-title" id="bmg-motor-history-title"><i class="bi bi-clock-history" aria-hidden="true"></i>Device-reported motor events</h2><span class="ce-tag bmg-number"><?= number_format((int) $runsPagination['total']) ?> total</span></div>
    <div class="card-body bmg-panel-body">
      <p class="bmg-caption"><?= (int) $runsPagination['per_page'] ?> reports per page, newest received first. A reported event is not verified rotation.</p>
      <div class="bmg-table-wrap table-responsive" data-mobile-cards="false">
        <table class="table table-hover align-middle bmg-history-table"><caption class="visually-hidden">Device-reported motor events for this drum</caption><thead><tr><th scope="col">Occurred (Asia/Manila)</th><th scope="col">Reported event</th><th scope="col">Reported rotations</th><th scope="col">Received</th></tr></thead><tbody>
          <?php if ($runs === []): ?><tr><td colspan="4" class="bmg-empty">No motor events reported yet.</td></tr><?php endif; ?>
          <?php foreach ($runs as $run): ?>
          <tr class="bmg-history-row"><td data-label="Occurred (Asia/Manila)"><?= esc($run['occurred_at']) ?></td><td data-label="Reported event"><?= esc($run['motor_state']) ?></td><td data-label="Reported rotations" class="bmg-number"><?= (int) $run['rotations'] ?> rot</td><td data-label="Received"><?= esc($run['created_at']) ?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
      <?= view('partials/table_pagination', [
          'pagination' => $runsPagination, 'filters' => [], 'baseUrl' => $historyBaseUrl,
          'paginationPageParam' => 'runs_page', 'paginationPerPageParam' => 'runs_per_page',
          'paginationExtraQuery' => $historyQuery, 'paginationLabel' => 'Drum motor event pages',
          'paginationFragment' => 'bmg-history', 'paginationNavigation' => true,
      ], ['saveData' => false]) ?>
    </div>
  </section>
  <section class="card ce-card bmg-panel mb-4" aria-labelledby="bmg-batch-history-title">
    <div class="card-header bmg-panel-heading"><h2 class="card-title" id="bmg-batch-history-title"><i class="bi bi-archive" aria-hidden="true"></i>Past batches</h2><span class="ce-tag bmg-number"><?= number_format((int) $batchesPagination['total']) ?> total</span></div>
    <div class="card-body bmg-panel-body">
      <p class="bmg-caption"><?= (int) $batchesPagination['per_page'] ?> operator-entered batch records per page, newest first. Completion does not certify the material's quality or suitability for use.</p>
      <div class="bmg-table-wrap table-responsive" data-mobile-cards="false">
        <table class="table table-hover align-middle bmg-history-table"><caption class="visually-hidden">Past batch history for this drum</caption><thead><tr><th scope="col">Batch</th><th scope="col">Status</th><th scope="col">Started (Asia/Manila)</th><th scope="col">Completed (Asia/Manila)</th><th scope="col">Input</th><th scope="col">Output</th><th scope="col">Notes</th></tr></thead><tbody>
          <?php if ($pastBatches === []): ?><tr><td colspan="7" class="bmg-empty">No past batch records yet.</td></tr><?php endif; ?>
          <?php foreach ($pastBatches as $batch): ?>
          <tr class="bmg-history-row">
            <th scope="row" data-label="Batch" class="bmg-code"><?= esc($batch['batch_code']) ?></th>
            <td data-label="Status"><span class="ce-tag"><?= esc($batch['status']) ?></span></td>
            <td data-label="Started (Asia/Manila)"><?= esc($formatBatchTime($batch['started_at'] ?? null)) ?></td>
            <td data-label="Completed (Asia/Manila)"><?= esc($formatBatchTime($batch['completed_at'] ?? null)) ?></td>
            <td data-label="Input" class="bmg-number"><?= esc($batch['total_input_weight_kg']) ?> kg</td>
            <td data-label="Output" class="bmg-number"><?= isset($batch['output_weight_kg']) ? esc($batch['output_weight_kg']) . ' kg' : 'Not recorded' ?></td>
            <td data-label="Notes"><strong>Creation:</strong> <?= nl2br(esc($batch['notes'] ?? 'Not recorded')) ?><br><strong>Completion / cancellation:</strong> <?= nl2br(esc($batch['release_notes'] ?? 'Not recorded')) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
      <?= view('partials/table_pagination', [
          'pagination' => $batchesPagination, 'filters' => [], 'baseUrl' => $historyBaseUrl,
          'paginationPageParam' => 'batches_page', 'paginationPerPageParam' => 'batches_per_page',
          'paginationExtraQuery' => $historyQuery, 'paginationLabel' => 'Drum past batch pages',
          'paginationFragment' => 'bmg-history', 'paginationNavigation' => true,
      ], ['saveData' => false]) ?>
    </div>
  </section>
</div>
