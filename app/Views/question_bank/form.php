<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-question-bank.css'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $question = $entry['question'] ?? ['type' => 'text', 'label' => '', 'required' => true, 'options' => [], 'allow_other' => false, 'scale_min_label' => '', 'scale_max_label' => '']; ?>
<?php $question = ['allow_other' => false, 'scale_min_label' => '', 'scale_max_label' => ''] + $question; ?>
<form class="guidance-ui qb-editor" method="POST" action="<?= esc($formAction, 'attr') ?>" data-modal-submit x-data="{ question: <?= esc(json_encode($question, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?> }">
  <?= csrf_field() ?>
  <?php if ($entry !== null): ?><input type="hidden" name="version" value="<?= (int) $entry['version'] ?>"><?php endif; ?>
  <div class="qb-editor-intro" role="note">
    <i class="bi bi-journal-check" aria-hidden="true"></i>
    <p>Saving <?= $entry === null ? 'creates revision 1' : 'creates a new revision' ?>. Existing survey copies and earlier revisions are never edited.</p>
  </div>
  <section class="qb-editor-section" aria-labelledby="bankQuestionSection">
    <div class="qb-section-head"><span class="qb-section-number" aria-hidden="true">01</span><h2 id="bankQuestionSection">Question details</h2></div>
    <div class="qb-field">
      <label class="form-label" for="bankQuestionText">Question text</label>
      <textarea id="bankQuestionText" class="form-control" name="question[label]" x-model="question.label" maxlength="500" rows="3" required></textarea>
    </div>
    <div class="qb-editor-grid">
      <div class="qb-field">
        <label for="bankQuestionCategory" class="form-label">Category</label>
        <input id="bankQuestionCategory" name="category" maxlength="80" required class="form-control" value="<?= esc($entry['category'] ?? '', 'attr') ?>" placeholder="e.g. Service feedback">
      </div>
      <div class="qb-field">
        <label for="bankQuestionAudience" class="form-label">Intended audience</label>
        <select id="bankQuestionAudience" name="audience" class="form-select">
          <?php foreach (['all' => 'Students and employees', 'students' => 'Students', 'employees' => 'Employees'] as $value => $label): ?>
            <option value="<?= esc($value, 'attr') ?>" <?= ($entry['audience'] ?? 'all') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <p class="qb-field-note">Audience labels guide survey authors; they do not automatically target a survey.</p>
  </section>
  <section class="qb-editor-section" aria-labelledby="bankAnswerSection">
    <div class="qb-section-head"><span class="qb-section-number" aria-hidden="true">02</span><h2 id="bankAnswerSection">Answer configuration</h2></div>
    <div class="qb-field">
      <label for="bankQuestionType" class="form-label">Answer type</label>
      <select id="bankQuestionType" name="question[type]" class="form-select" x-model="question.type" @change="question.options = ['single','multiple'].includes(question.type) ? ['', ''] : []; if (!['single','multiple'].includes(question.type)) { question.allow_other = false; } if (question.type !== 'rating') { question.scale_min_label = ''; question.scale_max_label = ''; }">
        <option value="text">Text (up to 2,000 characters)</option>
        <option value="single">Single choice</option>
        <option value="multiple">Multiple choice</option>
        <option value="rating">Rating (1–5)</option>
      </select>
    </div>
    <template x-if="question.type === 'rating'">
      <div class="qb-editor-grid qb-rating-fields">
        <div class="qb-field"><label class="form-label" for="bankScaleMin">Label for 1 (optional)</label><input id="bankScaleMin" name="question[scale_min_label]" class="form-control" x-model="question.scale_min_label" maxlength="100" placeholder="e.g. Lowest / Strongly disagree"></div>
        <div class="qb-field"><label class="form-label" for="bankScaleMax">Label for 5 (optional)</label><input id="bankScaleMax" name="question[scale_max_label]" class="form-control" x-model="question.scale_max_label" maxlength="100" placeholder="e.g. Highest / Strongly agree"></div>
      </div>
    </template>
    <input type="hidden" name="question[required]" :value="question.required ? '1' : '0'">
    <label class="form-check qb-check"><input id="qbQuestionRequired" type="checkbox" class="form-check-input" x-model="question.required"><span class="form-check-label">Required by default</span></label>
    <template x-if="['single','multiple'].includes(question.type)">
      <div class="qb-choice-settings">
        <input type="hidden" name="question[allow_other]" :value="question.allow_other ? '1' : '0'">
        <label class="form-check qb-check"><input id="qbQuestionAllowOther" type="checkbox" class="form-check-input" x-model="question.allow_other"><span class="form-check-label">Allow &ldquo;Other (please specify)&rdquo; custom answer</span></label>
        <p class="qb-field-note">Use 2–10 options. Each option can contain up to 200 characters.</p>
      </div>
    </template>
    <div class="qb-option-list">
      <template x-for="(option, index) in question.options" :key="index">
        <div class="qb-option-row">
          <span class="qb-option-number" aria-hidden="true" x-text="String(index + 1).padStart(2, '0')"></span>
          <input class="form-control" :aria-label="'Option ' + (index + 1)" :name="'question[options][' + index + ']'" x-model="question.options[index]" maxlength="200" required>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="question.options.length <= 2" @click="question.options.splice(index, 1)">Remove option</button>
        </div>
      </template>
    </div>
    <button type="button" class="btn btn-outline-primary btn-sm qb-add-option" x-show="['single','multiple'].includes(question.type)" :disabled="question.options.length >= 10" @click="question.options.push('')"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add option</button>
  </section>
  <section class="qb-editor-section" aria-labelledby="bankReviewSection">
    <div class="qb-section-head"><span class="qb-section-number" aria-hidden="true">03</span><h2 id="bankReviewSection">Author guidance</h2></div>
    <div class="qb-field">
      <label for="bankReviewNote" class="form-label">Author review note (optional)</label>
      <textarea id="bankReviewNote" name="review_note" class="form-control" rows="3" maxlength="1000" placeholder="Intended use, sensitivity or licensing considerations. Do not include personal information."><?= esc($entry['review_note'] ?? '') ?></textarea>
      <p class="qb-field-note">Visible to survey authors only; not copied into respondents’ questions. A bank entry does not establish clinical validity.</p>
    </div>
  </section>
  <div class="form-actions qb-form-actions">
    <a class="btn btn-outline-secondary" href="<?= esc($cancelUrl, 'attr') ?>" data-app-modal-close>Cancel</a>
    <button class="btn btn-primary" type="submit">Save bank question</button>
  </div>
</form>
<?= $this->endSection() ?>
