<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-cosign-queue.css'), 'attr') ?>">
<?= $this->endSection() ?>

<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <a href="<?= esc(site_url('guidance'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left" aria-hidden="true"></i> Guidance Hub
  </a>
  <a href="<?= esc(site_url('guidance/cases'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-folder2-open" aria-hidden="true"></i> Counseling Cases
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$pendingForms       = $pendingForms ?? [];
$actor              = $actor ?? [];
$practitionerProfile = $practitionerProfile ?? null;
$canCoSign          = (bool) ($canCoSign ?? false);
$hasOversight       = (bool) ($hasOversight ?? false);
$actorId            = (int) ($actor['id'] ?? 0);
$pagination = $pagination ?? \App\Libraries\TablePage::forList([])->metadata(count($pendingForms), count($pendingForms));
$perPage = (int) $pagination['per_page'];
$pendingCount = (int) ($pendingCount ?? $pagination['total']);
$assignedCount = (int) ($assignedCount ?? count(array_filter($pendingForms, static fn (array $row): bool => (int) ($row['assigned_supervisor_user_id'] ?? 0) === $actorId)));
?>

<section class="guidance-ui guidance-cosign-queue ce-workspace" data-guidance-registry="cosign" aria-label="Supervisor co-sign workspace">
  <div class="ce-card gcq-summary">
    <div class="gcq-access">
      <div class="gcq-access-icon" aria-hidden="true"><i class="bi bi-person-check"></i></div>
      <div class="gcq-access-body">
        <h2 class="gcq-access-title">Review access</h2>
        <div class="gcq-access-tags">
          <span class="ce-tag">
            <span class="ce-status-dot <?= $canCoSign ? 'ce-status-emerald' : 'ce-status-slate' ?>">
              <?= $canCoSign ? 'Qualified Co-Signer' : 'Read / Oversight View' ?>
            </span>
          </span>
          <?php if ($hasOversight): ?>
            <span class="ce-tag"><i class="bi bi-eye" aria-hidden="true"></i> Oversight Enabled</span>
          <?php endif; ?>
        </div>
        <?php if (! empty($practitionerProfile['credential_tier'])): ?>
          <p class="gcq-credential">Credential tier <strong><?= esc(ucwords(str_replace('_', ' ', (string) $practitionerProfile['credential_tier']))) ?></strong></p>
        <?php endif; ?>
        <p class="gcq-access-hint">
          <?= $canCoSign ? 'Review and co-sign forms assigned to you in the individual form workspace.' : 'Inspect visible submissions. Co-signature requires an eligible assigned supervisor.' ?>
        </p>
      </div>
    </div>
    <dl class="gcq-metrics">
      <div class="gcq-metric">
        <dt>Awaiting co-sign</dt>
        <dd class="ce-mono" data-queue-count="pending"><?= $pendingCount ?></dd>
      </div>
      <div class="gcq-metric">
        <dt>Assigned to you</dt>
        <dd class="ce-mono" data-queue-count="assigned"><?= $assignedCount ?></dd>
      </div>
    </dl>
  </div>

  <div class="ce-notice ce-notice-sky">
    <div class="ce-notice-body">
      <i class="bi bi-shield-lock ce-notice-icon" aria-hidden="true"></i>
      <span><strong>Clinical review &amp; attestation</strong> — Preview the submitted form, then open its workspace for review. Inspecting a submission does not finalize or co-sign it.</span>
    </div>
  </div>

  <div class="ce-card gcq-ledger">
    <div class="ce-card-header">
      <div>
        <h2 class="ce-card-title" id="cosign-ledger-heading">
          <i class="bi bi-pen" aria-hidden="true"></i> Clinical Forms Pending Supervisor Co-Sign
        </h2>
        <p class="gcq-ledger-hint" id="cosign-ledger-description">
          Oldest submissions first · <?= $hasOversight ? 'Submissions visible through your oversight access' : 'Submissions assigned to you' ?>
        </p>
      </div>
      <div class="ce-registry-header-actions">
        <span class="ce-tag ce-mono"><?= $pendingCount ?> form<?= $pendingCount === 1 ? '' : 's' ?></span>
        <form method="get" action="<?= esc(site_url('guidance/clinical/cosign-queue'), 'attr') ?>" class="ce-registry-page-size">
          <input type="hidden" name="page" value="1">
          <label for="cosignQueuePerPage" class="form-label mb-0">Rows per page</label>
          <select id="cosignQueuePerPage" name="per_page" class="form-select form-select-sm w-auto">
            <?php foreach ([20, 25, 50, 100] as $size): ?>
              <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-outline-secondary btn-sm">Apply</button>
        </form>
      </div>
    </div>

    <?php if ($pendingForms === []): ?>
      <div class="gcq-empty">
        <div class="gcq-empty-icon" aria-hidden="true"><i class="bi bi-check2-circle"></i></div>
        <h3>No forms awaiting co-signature</h3>
        <p>No clinical forms are currently waiting for co-signature<?= $hasOversight ? ' in your visible queue.' : ' from you.' ?></p>
      </div>
    <?php else: ?>
      <div class="table-responsive gcq-table-wrap">
        <table class="table table-hover align-middle ce-table ce-table-mobile gcq-table"
               data-mobile-cards="false"
               aria-labelledby="cosign-ledger-heading"
               aria-describedby="cosign-ledger-description">
          <colgroup>
            <col class="gcq-col-form">
            <col class="gcq-col-client">
            <col class="gcq-col-author">
            <col class="gcq-col-supervisor">
            <col class="gcq-col-submitted">
            <col class="gcq-col-notes">
            <col class="gcq-col-actions">
          </colgroup>
          <thead>
            <tr>
              <th scope="col">Form / Instance</th>
              <th scope="col">Client / Case</th>
              <th scope="col">Associate Author</th>
              <th scope="col">Assigned Supervisor</th>
              <th scope="col">Submitted</th>
              <th scope="col">Submission Notes</th>
              <th scope="col" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingForms as $row): ?>
              <?php
              $id = (int) ($row['id'] ?? 0);
              $instanceNumber = (string) ($row['instance_number'] ?? ('#' . $id));
              $clientName = trim((string) (($row['client_first_name'] ?? '') . ' ' . ($row['client_last_name'] ?? '')));
              $authorName = trim((string) (($row['author_first_name'] ?? '') . ' ' . ($row['author_last_name'] ?? '')));
              if ($authorName === '') {
                  $authorName = (string) ($row['author_username'] ?? 'User #' . (int) ($row['created_by_user_id'] ?? 0));
              }
              $supervisorName = trim((string) (($row['supervisor_first_name'] ?? '') . ' ' . ($row['supervisor_last_name'] ?? '')));
              if ($supervisorName === '') {
                  $supervisorName = (string) ($row['supervisor_username'] ?? 'User #' . (int) ($row['assigned_supervisor_user_id'] ?? 0));
              }
              $isMineToSign = ((int) ($row['assigned_supervisor_user_id'] ?? 0) === $actorId) && $canCoSign;
              $reviewLabel = $isMineToSign ? 'Review & Co-Sign' : 'Inspect';
              $submittedAt = ! empty($row['submitted_for_cosign_at']) ? strtotime((string) $row['submitted_for_cosign_at']) : false;
              ?>
              <tr data-form-id="<?= $id ?>">
                <td data-label="Form / Instance" class="gcq-form-cell">
                  <span class="ce-tag ce-mono gcq-instance"><?= esc($instanceNumber) ?></span>
                  <div class="gcq-form-title"><?= esc((string) ($row['form_title'] ?? '')) ?></div>
                  <div class="gcq-form-code ce-mono"><?= esc((string) ($row['form_code'] ?? $row['form_key'] ?? '')) ?></div>
                </td>
                <td data-label="Client / Case">
                  <div class="gcq-name"><?= esc($clientName !== '' ? $clientName : ('Person #' . (int) ($row['person_id'] ?? 0))) ?></div>
                  <?php if (! empty($row['client_mis_id'])): ?>
                    <div class="ce-patient-sub ce-mono"><?= esc((string) $row['client_mis_id']) ?></div>
                  <?php endif; ?>
                  <?php if (! empty($row['case_id'])): ?>
                    <a href="<?= esc(site_url('guidance/cases/' . (int) $row['case_id']), 'attr') ?>" class="ce-patient-link ce-mono gcq-case-link">
                      Case #<?= (int) $row['case_id'] ?>
                    </a>
                  <?php endif; ?>
                </td>
                <td data-label="Associate Author"><span class="gcq-name gcq-staff-name"><?= esc($authorName) ?></span></td>
                <td data-label="Assigned Supervisor">
                  <span class="gcq-name gcq-staff-name"><?= esc($supervisorName) ?></span>
                  <?php if ($isMineToSign): ?>
                    <span class="ce-tag gcq-assignment"><span class="ce-status-dot ce-status-emerald">Assigned to You</span></span>
                  <?php endif; ?>
                </td>
                <td data-label="Submitted">
                  <?php if ($submittedAt !== false): ?>
                    <time class="gcq-submitted" datetime="<?= esc(date(DATE_ATOM, $submittedAt), 'attr') ?>">
                      <span class="ce-date-primary ce-mono"><?= esc(date('M d, Y', $submittedAt)) ?></span>
                      <span class="ce-date-sub ce-mono"><?= esc(date('g:i A', $submittedAt)) ?></span>
                    </time>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td data-label="Submission Notes" class="gcq-notes-cell">
                  <?php if (! empty($row['submission_notes'])): ?>
                    <p class="gcq-notes"><?= esc((string) $row['submission_notes']) ?></p>
                  <?php else: ?>
                    <span class="text-muted small fst-italic">No notes</span>
                  <?php endif; ?>
                </td>
                <td data-label="Actions" class="gcq-actions-cell">
                  <div class="gcq-row-actions">
                    <a href="<?= esc(site_url('guidance/clinical/forms/' . $id . '/pdf?mode=preview'), 'attr') ?>"
                       target="_blank"
                       rel="noopener"
                       class="btn btn-sm btn-outline-secondary"
                       aria-label="<?= esc('Preview ' . $instanceNumber . ' (opens in new tab)', 'attr') ?>">
                      <i class="bi bi-file-pdf" aria-hidden="true"></i> Preview
                    </a>
                    <a href="<?= esc(site_url('guidance/clinical/forms/' . $id), 'attr') ?>"
                       class="btn btn-sm <?= $isMineToSign ? 'btn-primary' : 'btn-outline-secondary' ?>"
                       aria-label="<?= esc($reviewLabel . ' ' . $instanceNumber, 'attr') ?>">
                      <i class="bi bi-<?= $isMineToSign ? 'pen' : 'eye' ?>" aria-hidden="true"></i> <?= esc($reviewLabel) ?>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?= view('partials/table_pagination', [
        'pagination' => $pagination,
        'baseUrl' => site_url('guidance/clinical/cosign-queue'),
        'paginationLabel' => 'Co-sign queue pages',
        'paginationNavigation' => true,
        'paginationCardFooter' => true,
    ]) ?>
  </div>
</section>
<?= $this->endSection() ?>
