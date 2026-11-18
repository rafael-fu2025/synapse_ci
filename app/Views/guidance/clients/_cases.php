<section class="mb-4" aria-labelledby="guidance-client-cases-title">
  <h4 class="h6 fw-bold" id="guidance-client-cases-title">Authorized counseling cases <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['authorizedCases']) ?></span></h4>
  <?php if ($authorizedCases === []): ?>
    <div class="border rounded p-4 text-muted small">No counseling cases are available to you for this client. Cases appear here only when your Guidance access permits them.</div>
  <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($authorizedCases as $case): ?>
        <div class="list-group-item px-0 py-3 d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-start gap-3">
          <div class="min-width-0">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
              <span class="badge bg-body-secondary text-body border"><?= esc((string) $case['case_number']) ?></span>
              <strong><?= esc((string) $case['title']) ?></strong>
              <span class="badge bg-primary-subtle text-primary-emphasis"><?= esc($displayStatus($case['status'])) ?></span>
              <?php if (!empty($case['is_consultation'])): ?><span class="badge bg-info-subtle text-info-emphasis border">Consultation · Read-only</span><?php endif; ?>
            </div>
            <div class="small text-muted"><?= esc((string) $case['service_mode']) ?> · <?= esc((string) $case['handler_name']) ?> (<?= esc((string) $case['handler_tier']) ?>)</div>
            <div class="small text-muted mt-1">Opened <?= esc($formatDate($case['opened_at'])) ?><?= !empty($case['closed_at']) ? ' · Closed ' . esc($formatDate($case['closed_at'])) : '' ?></div>
          </div>
          <a href="<?= esc(site_url('/guidance/cases/' . (int) $case['id'])) ?>" class="btn btn-outline-primary btn-sm flex-shrink-0">Open Workspace</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('authorizedCases') ?>
</section>
<section aria-labelledby="guidance-client-episodes-title">
  <h4 class="h6 fw-bold" id="guidance-client-episodes-title">Guidance episodes <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['episodes']) ?></span></h4>
  <p class="small text-muted">An episode coordinates the client's authorized Guidance journey. Sensitive closure notes remain in its workspace.</p>
  <?php if ($episodes === []): ?>
    <div class="border rounded p-4 text-muted small">No episodes are available to you for this client.</div>
  <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($episodes as $episode): ?>
        <div class="list-group-item px-0 py-3 d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3">
          <div><div class="fw-semibold">Episode #<?= (int) $episode['id'] ?> <span class="badge bg-body-secondary text-body border ms-1"><?= esc($displayStatus($episode['status'])) ?></span></div>
            <div class="small text-muted"><?= esc((string) $episode['handler_name']) ?> · Opened <?= esc($formatDate($episode['opened_at'])) ?></div>
            <?php if (!empty($episode['closed_at'])): ?><div class="small text-muted">Closed <?= esc($formatDate($episode['closed_at'])) ?></div><?php endif; ?>
          </div>
          <a href="<?= esc(site_url('/guidance/episodes/' . (int) $episode['id'])) ?>" class="btn btn-outline-primary btn-sm flex-shrink-0">Open Episode</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('episodes') ?>
</section>
