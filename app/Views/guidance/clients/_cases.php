<section class="cp-record-section" aria-labelledby="guidance-client-cases-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-cases-title">
      Authorized counseling cases
      <span class="cp-card-count"><?= number_format((int) $totals['authorizedCases']) ?></span>
    </h4>
  </div>
  <?php if ($authorizedCases === []): ?>
    <div class="cp-empty-pane">
      <i class="bi bi-folder2" aria-hidden="true"></i>
      No counseling cases are available to you for this client. Cases appear here only when your Guidance access permits them.
    </div>
  <?php else: ?>
    <div class="cp-visit-list">
      <?php foreach ($authorizedCases as $case): ?>
        <?php
          $cStatus = (string) ($case['status'] ?? '');
          $cTone = match ($cStatus) {
              'open', 'active' => 'cp-status-inline--active',
              'monitoring' => 'cp-status-inline--warn',
              default => 'cp-status-inline--muted',
          };
        ?>
        <article class="cp-visit-card">
          <div class="cp-visit-head">
            <div class="cp-visit-title-col">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span class="cp-id-code"><?= esc((string) $case['case_number']) ?></span>
                <span class="cp-status-inline <?= esc($cTone) ?>"><span class="cp-dot" aria-hidden="true"></span><?= esc($displayStatus($cStatus)) ?></span>
                <?php if (!empty($case['is_consultation'])): ?>
                  <span class="cp-tag">Consultation · Read-only</span>
                <?php endif; ?>
              </div>
              <h5 class="cp-visit-title"><?= esc((string) $case['title']) ?></h5>
              <span class="cp-visit-meta">
                <?= esc((string) $case['service_mode']) ?> · <?= esc((string) $case['handler_name']) ?> (<?= esc((string) $case['handler_tier']) ?>)
                · Opened <span class="cp-mono"><?= esc($formatDate($case['opened_at'])) ?></span><?= !empty($case['closed_at']) ? ' · Closed <span class="cp-mono">' . esc($formatDate($case['closed_at'])) . '</span>' : '' ?>
              </span>
            </div>
            <div class="cp-visit-actions">
              <a href="<?= esc(site_url('/guidance/cases/' . (int) $case['id'])) ?>" class="btn btn-outline-primary btn-sm">Open Workspace</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('authorizedCases') ?>
</section>

<section class="cp-record-section" aria-labelledby="guidance-client-episodes-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-episodes-title">
      Guidance episodes
      <span class="cp-card-count"><?= number_format((int) $totals['episodes']) ?></span>
    </h4>
  </div>
  <p class="cp-record-section-note">An episode coordinates the client&rsquo;s authorized Guidance journey. Sensitive closure notes remain in its workspace.</p>
  <?php if ($episodes === []): ?>
    <div class="cp-empty-pane">
      <i class="bi bi-journal-medical" aria-hidden="true"></i>
      No episodes are available to you for this client.
    </div>
  <?php else: ?>
    <div class="cp-visit-list">
      <?php foreach ($episodes as $episode): ?>
        <?php
          $epStatus = (string) ($episode['status'] ?? '');
          $epTone = $epStatus === 'active' ? 'cp-status-inline--active' : 'cp-status-inline--muted';
        ?>
        <article class="cp-visit-card">
          <div class="cp-visit-head">
            <div class="cp-visit-title-col">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span class="cp-id-code">Episode #<?= (int) $episode['id'] ?></span>
                <span class="cp-status-inline <?= esc($epTone) ?>"><span class="cp-dot" aria-hidden="true"></span><?= esc($displayStatus($epStatus)) ?></span>
              </div>
              <span class="cp-visit-meta">
                <?= esc((string) $episode['handler_name']) ?> · Opened <span class="cp-mono"><?= esc($formatDate($episode['opened_at'])) ?></span><?= !empty($episode['closed_at']) ? ' · Closed <span class="cp-mono">' . esc($formatDate($episode['closed_at'])) . '</span>' : '' ?>
              </span>
            </div>
            <div class="cp-visit-actions">
              <a href="<?= esc(site_url('/guidance/episodes/' . (int) $episode['id'])) ?>" class="btn btn-outline-primary btn-sm">Open Episode</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('episodes') ?>
</section>
