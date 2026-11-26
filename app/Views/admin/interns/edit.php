<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$status = (string) ($placement['status'] ?? 'expired');
$isOpen = in_array($status, ['active', 'scheduled'], true);
$studentName = trim((string) ($placement['student_first_name'] ?? '') . ' ' . (string) ($placement['student_last_name'] ?? ''));
$studentId = (string) ($placement['student_mis_id'] ?: $placement['student_username']);
$unitLabel = \App\Services\StudentInternService::UNITS[$placement['unit']] ?? $placement['unit'];
$statusTone = match (strtolower($status)) {
    'active'    => 'is-active',
    'scheduled' => 'is-scheduled',
    'revoked'   => 'is-suspended',
    default     => 'is-inactive',
};
$toInputLocal = static function (?string $dt): string {
    if ($dt === null || trim($dt) === '') {
        return '';
    }
    return str_replace(' ', 'T', substr(trim($dt), 0, 16));
};
$toDisplayLocal = static function (?string $dt): string {
    if ($dt === null || trim($dt) === '') {
        return '';
    }
    $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr(trim($dt), 0, 19))
        ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', substr(trim($dt), 0, 16));
    return $parsed instanceof \DateTimeImmutable ? $parsed->format('d/m/Y h:i A') : trim($dt);
};
?>
<div class="adm-modal">
  <div class="adm-identity">
    <div class="adm-identity-top">
      <h2 class="adm-identity-name"><?= esc($studentName) ?></h2>
      <span class="adm-status <?= esc($statusTone) ?>"><?= esc(ucfirst($status)) ?></span>
    </div>
    <p class="adm-identity-sub">
      <span class="adm-identity-id"><?= esc($studentId) ?></span>
      <span aria-hidden="true"> · </span>
      <span><?= esc($unitLabel) ?> (<code><?= esc($placement['intern_role']) ?></code>)</span>
    </p>
    <div class="adm-identity-meta">
      <span>Account role: <strong><?= esc($placement['current_user_role'] ?? 'student') ?></strong></span>
      <span>Term: <strong><?= esc($placement['academic_term'] ?: 'Not set') ?></strong></span>
      <span>Program: <strong><?= esc($placement['student_program'] ?: 'Student') ?></strong></span>
    </div>
  </div>

  <?php if ($isOpen): ?>
    <form action="<?= esc($baseUrl . '/' . (int) $placement['id'] . '/update') ?>" method="POST" data-modal-submit data-confirm="Save changes to this student intern placement schedule or supervisor?" data-confirm-title="Update intern placement" data-confirm-label="Save placement" data-confirm-tone="primary">
      <?= csrf_field() ?>
      <input type="hidden" name="version" value="<?= (int) ($placement['version'] ?? 1) ?>">

      <div class="adm-field">
        <label class="adm-label" for="intern-edit-supervisor">Assigned supervising staff</label>
        <select id="intern-edit-supervisor" name="supervisor_user_id" class="form-select" required>
          <?php foreach ($supervisors ?? [] as $sup): ?>
            <option value="<?= (int) $sup['user_id'] ?>" <?= (int) $sup['user_id'] === (int) $placement['supervisor_user_id'] ? 'selected' : '' ?>>
              <?= esc($sup['name']) ?> (<?= esc(ucwords(str_replace('_', ' ', $sup['role']))) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row g-2">
        <div class="col-md-6">
          <div class="adm-field">
            <label class="adm-label" for="intern-edit-starts-display">Starts at (Asia/Manila)</label>
            <?php if ($status === 'active'): ?>
              <input id="intern-edit-starts-display"
                     type="text"
                     class="form-control"
                     value="<?= esc($toDisplayLocal($placement['starts_at'] ?? null)) ?>"
                     aria-describedby="intern-edit-starts-help"
                     disabled>
              <small id="intern-edit-starts-help" class="adm-help">Locked once placement is active.</small>
            <?php else: ?>
              <input id="intern-edit-starts-display"
                     type="text"
                     class="form-control"
                     data-datetimepicker
                     data-target="#intern-edit-starts"
                     placeholder="DD/MM/YYYY hh:mm A"
                     autocomplete="off"
                     required>
            <?php endif; ?>
            <input type="hidden" id="intern-edit-starts" name="starts_at" value="<?= esc($toInputLocal($placement['starts_at'] ?? null)) ?>">
          </div>
        </div>
        <div class="col-md-6">
          <div class="adm-field">
            <label class="adm-label" for="intern-edit-ends-display">Ends at (Asia/Manila)</label>
            <input id="intern-edit-ends-display"
                   type="text"
                   class="form-control"
                   data-datetimepicker
                   data-target="#intern-edit-ends"
                   placeholder="DD/MM/YYYY hh:mm A"
                   autocomplete="off"
                   required>
            <input type="hidden" id="intern-edit-ends" name="ends_at" value="<?= esc($toInputLocal($placement['ends_at'] ?? null)) ?>">
          </div>
        </div>
      </div>

      <div class="adm-field">
        <label class="adm-label" for="intern-edit-term">Academic term / cohort (optional)</label>
        <input id="intern-edit-term" name="academic_term" type="text" class="form-control" maxlength="64" value="<?= esc($placement['academic_term'] ?? '') ?>">
      </div>

      <div class="adm-field">
        <label class="adm-label" for="intern-edit-reason">Reason for update</label>
        <textarea id="intern-edit-reason" name="reason" class="form-control adm-textarea" maxlength="500" rows="2" required placeholder="Explain extension, supervisor handoff, or schedule adjustment"></textarea>
      </div>

      <div class="form-actions adm-modal-actions">
        <a class="btn btn-outline-secondary adm-btn" href="<?= esc($baseUrl) ?>" data-app-modal-close>Cancel</a>
        <button class="btn btn-primary adm-btn" type="submit">Save Placement Updates</button>
      </div>
    </form>

    <div class="adm-intern-revoke-box">
      <h3 class="h6 text-danger mb-2"><i class="bi bi-shield-slash me-1" aria-hidden="true"></i>Revoke Internship Access</h3>
      <p class="small text-muted mb-2">Revoking immediately reverts this account back to standard Student access, invalidates active workstation sessions and mobile bearer tokens, and closes Guidance supervision assignments.</p>
      <form action="<?= esc($baseUrl . '/' . (int) $placement['id'] . '/revoke') ?>" method="POST" data-modal-submit data-confirm="Immediately revoke this student's intern access and terminate their active workstation sessions?" data-confirm-title="Revoke student intern placement" data-confirm-label="Revoke placement" data-confirm-tone="danger">
        <?= csrf_field() ?>
        <input type="hidden" name="version" value="<?= (int) ($placement['version'] ?? 1) ?>">
        <div class="adm-field">
          <label class="adm-label" for="intern-revoke-reason">Revocation reason</label>
          <textarea id="intern-revoke-reason" name="reason" class="form-control adm-textarea" maxlength="500" rows="2" required placeholder="Required reason for early completion or administrative revocation"></textarea>
        </div>
        <div class="form-actions adm-modal-actions">
          <button class="btn btn-outline-danger adm-btn" type="submit">Revoke Placement Immediately</button>
        </div>
      </form>
    </div>
  <?php else: ?>
    <dl class="row small mb-3">
      <dt class="col-4 text-muted">Starts (Manila)</dt>
      <dd class="col-8"><?= esc($placement['starts_at'] ?? '—') ?></dd>
      <dt class="col-4 text-muted">Ends (Manila)</dt>
      <dd class="col-8"><?= esc($placement['ends_at'] ?? '—') ?></dd>
      <dt class="col-4 text-muted">Ended At</dt>
      <dd class="col-8"><?= esc($placement['ended_at'] ?? '—') ?></dd>
      <dt class="col-4 text-muted">Endorsement</dt>
      <dd class="col-8"><?= esc($placement['assignment_reason'] ?? '—') ?></dd>
      <?php if (!empty($placement['revoke_reason'])): ?>
        <dt class="col-4 text-muted">Revocation Reason</dt>
        <dd class="col-8 text-danger"><?= esc($placement['revoke_reason']) ?></dd>
      <?php endif; ?>
    </dl>
    <div class="form-actions adm-modal-actions">
      <a class="btn btn-outline-secondary adm-btn" href="<?= esc($baseUrl) ?>" data-app-modal-close>Close</a>
    </div>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>
