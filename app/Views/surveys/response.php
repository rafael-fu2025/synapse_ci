<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body p-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h5 class="card-title mb-1"><?= esc($survey['title']) ?></h5>
      <p class="mb-0">
        <strong><?= esc(trim($response['first_name'] . ' ' . $response['last_name'])) ?></strong><br>
        <span class="text-muted small">Submitted <?= esc($response['submitted_at']) ?> UTC</span>
      </p>
    </div>
    <?php if (!empty($response['person_id'])): ?>
      <a href="<?= esc(site_url('/guidance/students/' . (int) $response['person_id'] . '/cumulative')) ?>" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-person-lines-fill me-1"></i> Cumulative Record
      </a>
    <?php endif; ?>
  </div>
  <div class="alert alert-warning small">Confidential identified response. This access has been audited. Share only through authorized Guidance processes.</div>
  <?php if (!empty($answers['_external_link_completed']) || (!empty($survey['external_url']) && !array_is_list($answers))): ?>
    <div class="alert alert-info small">
      <strong>External Survey Link Completion:</strong> This respondent confirmed completion on the external survey URL
      <?php if (!empty($survey['external_url'])): ?>
        (<a href="<?= esc((string) $survey['external_url'], 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= esc((string) $survey['external_url']) ?></a>)
      <?php endif; ?>. Individual item answers are stored on the external form provider.
    </div>
  <?php else: ?>
    <dl><?php foreach ($answers as $answer): ?><dt class="mb-2"><?= esc($answer['question']) ?></dt><dd class="text-break mb-4"><?php if ($answer['value'] === null): ?><span class="text-muted">Not answered</span><?php elseif (is_array($answer['value'])): ?><ul><?php foreach ($answer['value'] as $value): ?><li><?= esc($value) ?></li><?php endforeach; ?></ul><?php else: ?><?= nl2br(esc((string) $answer['value'])) ?><?php endif; ?></dd><?php endforeach; ?></dl>
  <?php endif; ?>
  <a href="<?= esc(site_url('guidance/surveys/' . $survey['id'] . '/responses')) ?>" class="btn btn-outline-secondary">Back to responses</a>
</div></div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
    <h6 class="fw-bold mb-0"><i class="bi bi-person-check text-primary me-2"></i>Staff-Only Interview Attribution &amp; Personnel Remarks</h6>
    <?php if (!empty($response['interviewed_by_user_id'])): ?>
      <span class="badge bg-success-subtle text-success border border-success-subtle">
        Interviewed by <?= esc((string) ($response['interviewer_name'] ?? 'Guidance Staff')) ?>
        <?php if (!empty($response['interviewer_tier'])): ?> (<?= esc((string) $response['interviewer_tier']) ?>)<?php endif; ?>
        · <?= esc(date('M j, Y', strtotime((string) $response['interviewed_at']))) ?>
      </span>
    <?php else: ?>
      <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Pending Staff Interview</span>
    <?php endif; ?>
  </div>
  <div class="card-body p-4">
    <p class="small text-muted mb-3">
      Guidance personnel only: confirm whether this routine/intake response was reviewed during an interview and store encrypted staff remarks. Students never see this section.
    </p>
    <form action="<?= esc(site_url('guidance/surveys/' . $survey['id'] . '/responses/' . $response['id'] . '/interview')) ?>" method="post" data-background-submit>
      <?= csrf_field() ?>
      <?php
        $interviewIso = date('Y-m-d');
        if (!empty($response['interviewed_at'])) {
            try {
                $interviewIso = (new DateTimeImmutable((string) $response['interviewed_at'], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Asia/Manila'))
                    ->format('Y-m-d');
            } catch (Throwable) {
                $interviewIso = date('Y-m-d');
            }
        }
      ?>
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <label class="form-label small fw-semibold" for="survey-interview-date-display">Interview Date (Asia/Manila)</label>
          <input id="survey-interview-date-display" type="text" class="form-control" data-datepicker data-target="#survey-interview-date" value="<?= esc(date('d/m/Y', strtotime($interviewIso))) ?>" autocomplete="off" required>
          <input id="survey-interview-date" type="hidden" name="interview_date" value="<?= esc($interviewIso) ?>">
        </div>
        <div class="col-md-8">
          <label class="form-label small fw-semibold" for="survey-staff-remarks">Confidential Personnel Remarks (Encrypted)</label>
          <textarea id="survey-staff-remarks" name="staff_remarks" rows="3" maxlength="2000" class="form-control" placeholder="Record interview observations, follow-up needs, or referral notes..."><?= esc((string) ($response['staff_remarks'] ?? '')) ?></textarea>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">
        <i class="bi bi-shield-check me-1"></i> Save Interview Attribution &amp; Remarks
      </button>
    </form>
  </div>
</div>
<?= $this->endSection() ?>
