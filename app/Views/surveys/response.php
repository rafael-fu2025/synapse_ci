<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-surveys.css'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('guidance/surveys/' . $survey['id'] . '/responses'), 'attr') ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to responses</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section class="guidance-ui gs-workspace gs-response">
  <div class="gs-sheet">
    <header class="gs-band">
      <div class="gs-response-top">
        <div>
          <h2 class="gs-intro-title"><?= esc($survey['title']) ?></h2>
          <p class="gs-respondent"><?= esc(trim($response['first_name'] . ' ' . $response['last_name'])) ?></p>
          <p class="gs-meta gs-timestamp">Submitted <?= esc($response['submitted_at']) ?> UTC</p>
        </div>
        <?php if (!empty($response['person_id'])): ?>
          <a href="<?= esc(site_url('/guidance/students/' . (int) $response['person_id'] . '/cumulative'), 'attr') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-lines-fill me-1" aria-hidden="true"></i>Cumulative Record</a>
        <?php endif; ?>
      </div>
      <div class="gs-notice" role="note"><i class="bi bi-shield-lock" aria-hidden="true"></i><p>Confidential identified response. This access has been audited. Share only through authorized Guidance processes.</p></div>
    </header>
    <section class="gs-answer-section" aria-labelledby="surveyResponseAnswers">
      <div class="gs-results-head"><h3 class="gs-results-title" id="surveyResponseAnswers">Submitted answers</h3></div>
      <?php if (!empty($answers['_external_link_completed']) || (!empty($survey['external_url']) && !array_is_list($answers))): ?>
        <div class="gs-band gs-external-notice" role="note">
          <p><strong>External Survey Link Completion:</strong> This respondent confirmed completion on the external survey URL<?php if (!empty($survey['external_url'])): ?> (<a href="<?= esc((string) $survey['external_url'], 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= esc((string) $survey['external_url']) ?></a>)<?php endif; ?>. Individual item answers are stored on the external form provider.</p>
        </div>
      <?php else: ?>
        <dl class="gs-answer-ledger">
          <?php foreach ($answers as $answer): ?>
            <div class="gs-answer-row">
              <dt><?= esc($answer['question']) ?></dt>
              <dd><?php if ($answer['value'] === null): ?><span class="gs-muted">Not answered</span><?php elseif (is_array($answer['value'])): ?><ul><?php foreach ($answer['value'] as $value): ?><li><?= esc($value) ?></li><?php endforeach; ?></ul><?php else: ?><?= nl2br(esc((string) $answer['value'])) ?><?php endif; ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
      <?php endif; ?>
    </section>
    <section class="gs-interview-section" aria-labelledby="surveyInterviewHeading">
      <div class="gs-results-head gs-interview-head">
        <h3 class="gs-results-title" id="surveyInterviewHeading">Staff-Only Interview Attribution &amp; Personnel Remarks</h3>
        <?php if (!empty($response['interviewed_by_user_id'])): ?>
          <span class="gs-status is-completed">Interviewed by <?= esc((string) ($response['interviewer_name'] ?? 'Guidance Staff')) ?><?php if (!empty($response['interviewer_tier'])): ?> (<?= esc((string) $response['interviewer_tier']) ?>)<?php endif; ?> · <?= esc(date('M j, Y', strtotime((string) $response['interviewed_at']))) ?></span>
        <?php else: ?>
          <span class="gs-status is-pending">Pending Staff Interview</span>
        <?php endif; ?>
      </div>
      <div class="gs-band">
        <p class="gs-intro-desc">Guidance personnel only: confirm whether this routine/intake response was reviewed during an interview and store encrypted staff remarks. Students never see this section.</p>
        <form action="<?= esc(site_url('guidance/surveys/' . $survey['id'] . '/responses/' . $response['id'] . '/interview'), 'attr') ?>" method="post" data-background-submit class="gs-interview-form">
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
          <div class="gs-interview-grid">
            <div class="gs-field">
              <label class="form-label" for="survey-interview-date-display">Interview Date (Asia/Manila)</label>
              <input id="survey-interview-date-display" type="text" class="form-control" data-datepicker data-target="#survey-interview-date" value="<?= esc(date('d/m/Y', strtotime($interviewIso)), 'attr') ?>" autocomplete="off" required>
              <input id="survey-interview-date" type="hidden" name="interview_date" value="<?= esc($interviewIso, 'attr') ?>">
            </div>
            <div class="gs-field">
              <label class="form-label" for="survey-staff-remarks">Confidential Personnel Remarks (Encrypted)</label>
              <textarea id="survey-staff-remarks" name="staff_remarks" rows="3" maxlength="2000" class="form-control" placeholder="Record interview observations, follow-up needs, or referral notes..."><?= esc((string) ($response['staff_remarks'] ?? '')) ?></textarea>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Save Interview Attribution &amp; Remarks</button>
        </form>
      </div>
    </section>
  </div>
</section>
<?= $this->endSection() ?>
