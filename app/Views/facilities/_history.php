<div class="bmg-history-grid" id="bmg-history">
<section class="bmg-panel mb-4" aria-labelledby="bmg-command-history-title">
  <div class="bmg-panel-heading"><h2 class="bmg-panel-title h5 mb-0" id="bmg-command-history-title"><i class="bi bi-terminal" aria-hidden="true"></i> Recent command requests</h2><span class="bmg-status-pill is-muted"><?= count($commands) ?> shown</span></div>
  <div class="bmg-panel-body pb-0"><p class="bmg-caption">Latest 20 requests, newest first. Status and device acknowledgements do not verify physical movement.</p>
  <div class="bmg-table-wrap table-responsive"><table class="table table-hover align-middle small mb-0"><caption class="visually-hidden">Recent command requests for this drum</caption><thead><tr><th scope="col">Requested (Asia/Manila)</th><th scope="col">Command</th><th scope="col">Duration</th><th scope="col">Status</th><th scope="col">Device acknowledgement</th><th scope="col">Expires</th></tr></thead><tbody>
    <?php if ($commands === []): ?><tr><td colspan="6" class="bmg-empty text-muted py-4">No commands requested yet.</td></tr><?php endif; ?>
    <?php foreach ($commands as $command): ?><tr><td><?= esc($command['created_at']) ?></td><td><?= esc($command['command']) ?></td><td><?= isset($command['duration_seconds']) ? (int) $command['duration_seconds'] . ' s' : '—' ?></td><td><?= esc($command['status']) ?></td><td class="text-break"><?= esc($command['ack_status'] ?? 'Not reported') ?><?php if (!empty($command['ack_message'])): ?><br><?= esc($command['ack_message']) ?><?php endif; ?><?php if (!empty($command['acknowledged_at'])): ?><br><?= esc($command['acknowledged_at']) ?><?php endif; ?></td><td><?= esc($command['expires_at']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div></section>
<section class="bmg-panel mb-4" aria-labelledby="bmg-motor-history-title">
  <div class="bmg-panel-heading"><h2 class="bmg-panel-title h5 mb-0" id="bmg-motor-history-title"><i class="bi bi-clock-history" aria-hidden="true"></i> Device-reported motor events</h2><span class="bmg-status-pill is-muted"><?= count($runs) ?> shown</span></div>
  <div class="bmg-panel-body pb-0"><p class="bmg-caption">Latest 20 reports, newest received first. A reported event is not verified rotation.</p>
  <div class="bmg-table-wrap table-responsive"><table class="table table-hover align-middle small mb-0"><caption class="visually-hidden">Device-reported motor events for this drum</caption><thead><tr><th scope="col">Occurred (Asia/Manila)</th><th scope="col">Reported event</th><th scope="col">Reported duration</th><th scope="col">Received</th></tr></thead><tbody>
    <?php if ($runs === []): ?><tr><td colspan="4" class="bmg-empty text-muted py-4">No motor events reported yet.</td></tr><?php endif; ?>
    <?php foreach ($runs as $run): ?><tr><td><?= esc($run['occurred_at']) ?></td><td><?= esc($run['motor_state']) ?></td><td><?= (int) $run['duration_seconds'] ?> s</td><td><?= esc($run['created_at']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div></section>
<section class="bmg-panel mb-4" aria-labelledby="bmg-batch-history-title">
  <div class="bmg-panel-heading"><h2 class="bmg-panel-title h5 mb-0" id="bmg-batch-history-title"><i class="bi bi-archive" aria-hidden="true"></i> Past batches</h2><span class="bmg-status-pill is-muted"><?= count($pastBatches) ?> shown</span></div>
  <div class="bmg-panel-body pb-0"><p class="bmg-caption">Latest 50 operator-entered batch records. Completion does not certify the material's quality or suitability for use.</p>
  <div class="bmg-table-wrap table-responsive"><table class="table table-hover align-middle small mb-0"><caption class="visually-hidden">Latest 50 past batch records for this drum</caption><thead><tr><th scope="col">Batch</th><th scope="col">Status</th><th scope="col">Started</th><th scope="col">Completed</th><th scope="col">Input</th><th scope="col">Output</th><th scope="col">Notes</th></tr></thead><tbody>
    <?php if ($pastBatches === []): ?><tr><td colspan="7" class="bmg-empty text-muted py-4">No past batch records yet.</td></tr><?php endif; ?>
    <?php foreach ($pastBatches as $batch): ?><tr><th scope="row"><?= esc($batch['batch_code']) ?></th><td><?= esc($batch['status']) ?></td><td><?= esc($batch['started_at']) ?></td><td><?= esc($batch['completed_at'] ?? 'Not recorded') ?></td><td><?= esc($batch['total_input_weight_kg']) ?> kg</td><td><?= isset($batch['output_weight_kg']) ? esc($batch['output_weight_kg']) . ' kg' : 'Not recorded' ?></td><td class="text-break"><strong>Creation:</strong> <?= nl2br(esc($batch['notes'] ?? 'Not recorded')) ?><br><strong>Completion / cancellation:</strong> <?= nl2br(esc($batch['release_notes'] ?? 'Not recorded')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div></section>
</div>
