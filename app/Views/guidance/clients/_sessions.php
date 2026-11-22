<section class="cp-record-section" aria-labelledby="guidance-client-sessions-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-sessions-title">
      Session chronology
      <span class="cp-card-count"><?= number_format((int) $totals['sessions']) ?></span>
    </h4>
  </div>
  <p class="cp-record-section-note">Dates and statuses are shown for authorized cases. Note contents and version history are released only through their audited readers.</p>
  <?php if ($sessions === []): ?>
    <div class="cp-empty-pane">
      <i class="bi bi-chat-left-text" aria-hidden="true"></i>
      No sessions are available in your visible cases.
    </div>
  <?php else: ?>
    <div class="cp-table-card">
      <div class="cp-table-wrap">
        <table class="table align-middle mb-0 cp-table" data-mobile-cards="false">
          <caption class="visually-hidden">Authorized counseling session history, newest first</caption>
          <thead>
            <tr>
              <th scope="col">Date (Manila)</th>
              <th scope="col">Case / Session</th>
              <th scope="col">Type</th>
              <th scope="col">Practitioner</th>
              <th scope="col">Status</th>
              <th scope="col" class="text-end">Workspace</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sessions as $session): ?>
              <?php
                $sStatus = (string) ($session['status'] ?? '');
                $sTone = match ($sStatus) {
                    'completed' => 'cp-status-inline--active',
                    'in_progress' => 'cp-status-inline--warn',
                    default => 'cp-status-inline--muted',
                };
              ?>
              <tr class="cp-row">
                <td class="cp-cell-header" data-label="Date (Manila)">
                  <span class="cp-person-name cp-mono"><?= esc($formatDate($session['started_at'])) ?></span>
                </td>
                <td data-label="Case / Session">
                  <span class="cp-id-code"><?= esc((string) $session['case_number']) ?></span>
                  <span class="cp-person-sub">Session <?= (int) $session['session_number'] ?></span>
                </td>
                <td data-label="Type"><?= esc($displayStatus($session['session_type'])) ?></td>
                <td data-label="Practitioner"><?= esc($staffName($session['counsellor_user_id'])) ?></td>
                <td data-label="Status">
                  <span class="cp-status-inline <?= esc($sTone) ?>"><span class="cp-dot" aria-hidden="true"></span><?= esc($displayStatus($sStatus)) ?></span>
                </td>
                <td class="text-end cp-cell-actions">
                  <div class="table-card-actions cp-row-actions">
                    <a href="<?= esc(site_url('/guidance/cases/' . (int) $session['case_id'])) ?>" class="btn btn-outline-primary btn-sm">Case &amp; Note History</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
  <?= $historyPager('sessions') ?>
</section>

<section class="cp-record-section" aria-labelledby="guidance-client-followups-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-followups-title">
      Follow-up schedules
      <span class="cp-card-count"><?= number_format((int) $totals['followups']) ?></span>
    </h4>
  </div>
  <?php if ($followups === []): ?>
    <div class="cp-empty-pane">
      <i class="bi bi-calendar-check" aria-hidden="true"></i>
      No follow-ups are available in your visible cases.
    </div>
  <?php else: ?>
    <div class="cp-table-card">
      <div class="cp-table-wrap">
        <table class="table align-middle mb-0 cp-table" data-mobile-cards="false">
          <caption class="visually-hidden">Authorized Guidance follow-up schedules</caption>
          <thead>
            <tr>
              <th scope="col">Due date</th>
              <th scope="col">Case</th>
              <th scope="col">Status</th>
              <th scope="col">Completed</th>
              <th scope="col" class="text-end">Workspace</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($followups as $followup): ?>
              <?php
                $fStatus = (string) ($followup['status'] ?? '');
                $fTone = match ($fStatus) {
                    'completed' => 'cp-status-inline--active',
                    'pending' => 'cp-status-inline--warn',
                    'missed' => 'cp-status-inline--danger',
                    default => 'cp-status-inline--muted',
                };
              ?>
              <tr class="cp-row">
                <td class="cp-cell-header" data-label="Due date">
                  <span class="cp-person-name cp-mono"><?= esc($formatDate($followup['due_date'], false)) ?></span>
                </td>
                <td data-label="Case">
                  <span class="cp-id-code"><?= esc((string) $followup['case_number']) ?></span>
                </td>
                <td data-label="Status">
                  <span class="cp-status-inline <?= esc($fTone) ?>"><span class="cp-dot" aria-hidden="true"></span><?= esc($displayStatus($fStatus)) ?></span>
                </td>
                <td data-label="Completed" class="cp-mono"><?= esc($formatDate($followup['completed_at'])) ?></td>
                <td class="text-end cp-cell-actions">
                  <div class="table-card-actions cp-row-actions">
                    <a href="<?= esc(site_url('/guidance/cases/' . (int) $followup['case_id'])) ?>" class="btn btn-outline-primary btn-sm">View Follow-up</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
  <?= $historyPager('followups') ?>
</section>
