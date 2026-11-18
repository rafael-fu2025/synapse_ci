<section class="mb-4" aria-labelledby="guidance-client-sessions-title">
  <h4 class="h6 fw-bold" id="guidance-client-sessions-title">Session chronology <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['sessions']) ?></span></h4>
  <p class="small text-muted">Dates and statuses are shown for authorized cases. Note contents and version history are released only through their audited readers.</p>
  <?php if ($sessions === []): ?>
    <div class="border rounded p-4 text-muted small">No sessions are available in your visible cases.</div>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle">
      <caption class="visually-hidden">Authorized counseling session history, newest first</caption>
      <thead><tr><th scope="col">Date (Manila)</th><th scope="col">Case / session</th><th scope="col">Type</th><th scope="col">Practitioner</th><th scope="col">Status</th><th scope="col">Workspace</th></tr></thead>
      <tbody>
        <?php foreach ($sessions as $session): ?>
          <tr>
            <td><?= esc($formatDate($session['started_at'])) ?></td>
            <td><div class="fw-semibold"><?= esc((string) $session['case_number']) ?></div><div class="small text-muted">Session <?= (int) $session['session_number'] ?></div></td>
            <td><?= esc($displayStatus($session['session_type'])) ?></td>
            <td><?= esc($staffName($session['counsellor_user_id'])) ?></td>
            <td><span class="badge bg-body-secondary text-body border"><?= esc($displayStatus($session['status'])) ?></span></td>
            <td><div class="table-card-actions"><a href="<?= esc(site_url('/guidance/cases/' . (int) $session['case_id'])) ?>" class="btn btn-outline-primary btn-sm">Case &amp; Note History</a></div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <?= $historyPager('sessions') ?>
</section>
<section aria-labelledby="guidance-client-followups-title">
  <h4 class="h6 fw-bold" id="guidance-client-followups-title">Follow-up schedules <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['followups']) ?></span></h4>
  <?php if ($followups === []): ?>
    <div class="border rounded p-4 text-muted small">No follow-ups are available in your visible cases.</div>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle">
      <caption class="visually-hidden">Authorized Guidance follow-up schedules</caption>
      <thead><tr><th scope="col">Due date</th><th scope="col">Case</th><th scope="col">Status</th><th scope="col">Completed</th><th scope="col">Workspace</th></tr></thead>
      <tbody>
        <?php foreach ($followups as $followup): ?>
          <tr><td><?= esc($formatDate($followup['due_date'], false)) ?></td><td><?= esc((string) $followup['case_number']) ?></td>
            <td><span class="badge bg-body-secondary text-body border"><?= esc($displayStatus($followup['status'])) ?></span></td>
            <td><?= esc($formatDate($followup['completed_at'])) ?></td>
            <td><div class="table-card-actions"><a href="<?= esc(site_url('/guidance/cases/' . (int) $followup['case_id'])) ?>" class="btn btn-outline-primary btn-sm">View Follow-up</a></div></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <?= $historyPager('followups') ?>
</section>
