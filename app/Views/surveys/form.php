<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php $questions = $survey['questions'] ?? [['type' => 'text', 'label' => '', 'required' => true, 'options' => []]]; ?>
<div x-data="SynapseSurveyBuilder(<?= esc(json_encode($questions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?>, <?= esc(json_encode($survey['audience'] ?? 'all'), 'attr') ?>, <?= esc(json_encode(site_url('guidance/question-bank/catalog')), 'attr') ?>)">
  <div class="alert alert-info small">Identified responses are visible only to authorized Guidance administrators and supervisors. Publishing permanently freezes the questions, audience and availability window.</div>
  <noscript><div class="alert alert-warning">Enable JavaScript to use the question builder.</div></noscript>
  <form action="<?= esc($formAction) ?>" method="post" data-modal-submit>
    <?= csrf_field() ?>
    <?php if ($survey !== null): ?><input type="hidden" name="version" value="<?= (int) $survey['version'] ?>"><?php endif; ?>
    <div class="mb-3"><label for="surveyTitle" class="form-label">Title</label><input id="surveyTitle" name="title" class="form-control" maxlength="200" required value="<?= esc($survey['title'] ?? '') ?>"></div>
    <div class="mb-3"><label for="surveyDescription" class="form-label">Description</label><textarea id="surveyDescription" name="description" class="form-control" maxlength="2000" rows="3"><?= esc($survey['description'] ?? '') ?></textarea></div>
    <div class="mb-3"><label for="surveyAudience" class="form-label">Audience</label><select id="surveyAudience" name="audience" class="form-select" x-model="audience"><?php foreach (['all' => 'Students and employees', 'students' => 'Students', 'employees' => 'Employees'] as $value => $label): ?><option value="<?= esc($value) ?>" <?= ($survey['audience'] ?? 'all') === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
    <?= view('announcements/_targeting', ['targetRecord' => $survey ?? [], 'targetPrefix' => 'survey'], ['saveData' => false]) ?>
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label for="surveyStartDisplay" class="form-label fw-semibold">Starts at (Asia/Manila)</label>
        <input type="text"
               id="surveyStartDisplay"
               class="form-control"
               data-datetimepicker
               data-target="#surveyStart"
               data-min="today"
               placeholder="DD/MM/YYYY hh:mm A"
               autocomplete="off">
        <input type="hidden"
               id="surveyStart"
               name="starts_at"
               value="<?= esc($survey['starts_at'] ?? '') ?>">
        <div class="form-text">Leave empty for immediate availability.</div>
      </div>
      <div class="col-md-6">
        <label for="surveyEndDisplay" class="form-label fw-semibold">Ends at (Asia/Manila)</label>
        <input type="text"
               id="surveyEndDisplay"
               class="form-control"
               data-datetimepicker
               data-target="#surveyEnd"
               data-min="today"
               data-default-hours="23"
               data-default-minutes="59"
               placeholder="DD/MM/YYYY hh:mm A"
               autocomplete="off">
        <input type="hidden"
               id="surveyEnd"
               name="ends_at"
               value="<?= esc($survey['ends_at'] ?? '') ?>">
        <div class="form-text">Leave empty for no scheduled end.</div>
      </div>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><h6 class="mb-0">Questions <span class="text-muted small" x-text="questions.length + ' / 20'"></span></h6><button type="button" class="btn btn-outline-primary btn-sm" @click="openBank()" :aria-expanded="bankOpen">Add from question bank</button></div>
    <section class="border rounded p-3 mb-3" x-show="bankOpen" x-cloak aria-label="Question bank picker">
      <p class="small text-muted">Select several questions and add them together, or add one copy at a time. Selections stay checked across pages and searches until added or cleared. Editing a copied question detaches its bank reference. Bank revisions and archiving never alter saved survey copies.</p>
      <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <button type="button" class="btn btn-primary btn-sm" @click="addSelectedBankQuestions()" :disabled="bankSelected.length === 0 || bankBusy">Add selected questions</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" @click="clearBankSelection()" :disabled="bankSelected.length === 0">Clear selection</button>
        <span class="small" role="status" x-text="bankSelected.length + ' selected · ' + remainingQuestionSlots() + ' slots available'"></span>
      </div>
      <p class="alert alert-warning small" role="alert" x-show="bankSelectionError" x-text="bankSelectionError"></p>

      <div class="row g-2 mb-3"><div class="col-md-4"><label class="form-label" for="surveyBankSearch">Search bank</label><input id="surveyBankSearch" class="form-control" type="search" maxlength="100" x-model="bankQuery" @input="bankFilterInput('bankQuery', $event)" @compositionstart="bankComposition('bankQuery', $event, true)" @compositionend="bankComposition('bankQuery', $event, false)" @keydown.enter="bankSearchEnter($event)"></div>
      <div class="col-md-3"><label class="form-label" for="surveyBankCategory">Category</label><input id="surveyBankCategory" class="form-control" maxlength="80" x-model="bankCategory" @input="bankFilterInput('bankCategory', $event)" @compositionstart="bankComposition('bankCategory', $event, true)" @compositionend="bankComposition('bankCategory', $event, false)" @keydown.enter="bankSearchEnter($event)" placeholder="Exact category"></div>
      <div class="col-md-3"><label class="form-label" for="surveyBankAudience">Intended audience</label><select id="surveyBankAudience" class="form-select" x-model="bankAudience" @change="bankFilterInput('bankAudience', $event, true)"><option value="">Any audience</option><option value="students">Students + both</option><option value="employees">Employees + both</option><option value="all">Both audiences</option></select></div>
      <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary" type="button" @click="searchBank(1)" :disabled="bankBusy">Search bank</button></div></div>
      <p class="small" role="status" x-show="bankBusy">Loading bank questions…</p>
      <p class="alert alert-danger small" role="alert" x-show="bankError" x-text="bankError"></p>
      <p class="text-muted small" x-show="!bankBusy && !bankError && bankItems.length === 0">No matching active questions. Adjust the filters or add an entry from the Question Bank page.</p>
      <label class="form-check" x-show="bankItems.length > 0"><input class="form-check-input" type="checkbox" :checked="bankPageSelected()" x-effect="$el.indeterminate = bankPagePartlySelected()" @change="toggleBankPage($event.target.checked); $event.target.checked = bankPageSelected()" :disabled="bankBusy"><span class="form-check-label">Select this page</span></label>
      <template x-for="item in bankItems" :key="item.id"><article class="border-bottom py-3">
        <label class="form-check"><input class="form-check-input" type="checkbox" :checked="isBankSelected(item)" :disabled="!isBankSelected(item) && bankSelected.length >= 20" @change="toggleBankSelection(item, $event.target.checked); $event.target.checked = isBankSelected(item)"><span class="form-check-label"><span class="visually-hidden">Select question: </span><strong x-text="item.question.label"></strong></span></label><small class="text-muted" x-text="item.category + ' · ' + item.audience + ' · revision ' + item.revision + ' · ' + item.question.type"></small>
        <ul class="small mt-2" x-show="item.question.options.length"><template x-for="(option, index) in item.question.options" :key="index"><li x-text="option"></li></template></ul>
        <p class="small text-muted mt-2" x-show="item.review_note" x-text="item.review_note"></p>
        <button class="btn btn-outline-primary btn-sm mt-2" type="button" @click="addBankQuestion(item)" :disabled="questions.length >= 20">Add copy</button>
      </article></template>
      <div class="d-flex gap-2 align-items-center mt-3"><button class="btn btn-outline-secondary btn-sm" type="button" @click="searchBank(bankPage - 1)" :disabled="bankBusy || bankPage <= 1">Previous</button><span class="small" x-text="'Page ' + bankPage + ' of ' + bankPages"></span><button class="btn btn-outline-secondary btn-sm" type="button" @click="searchBank(bankPage + 1)" :disabled="bankBusy || bankPage >= bankPages">Next</button></div>
    </section>
    <p class="small text-success" role="status" x-show="bankMessage" x-text="bankMessage"></p>
    <template x-for="(question, index) in questions" :key="index">
      <fieldset class="border rounded p-3 mb-3">
        <legend class="float-none w-auto fs-6 px-1" x-text="'Question ' + (index + 1)"></legend>
        <p class="small text-muted" x-show="question.bank_id" x-text="'Bank #' + question.bank_id + ' · revision ' + question.bank_revision + ' · independent survey copy'"></p>
        <template x-if="question.bank_id"><div><input type="hidden" :name="'questions[' + index + '][bank_id]'" :value="question.bank_id"><input type="hidden" :name="'questions[' + index + '][bank_revision]'" :value="question.bank_revision"></div></template>
        <label class="form-label d-block">Question text<input class="form-control" :name="'questions[' + index + '][label]'" x-model="question.label" @input="detach(question)" maxlength="500" required></label>
        <label class="form-label d-block">Answer type<select class="form-select" :name="'questions[' + index + '][type]'" x-model="question.type" @change="detach(question); question.options = ['single','multiple'].includes(question.type) ? ['', ''] : []"><option value="text">Text (up to 2,000 characters)</option><option value="single">Single choice</option><option value="multiple">Multiple choice</option><option value="rating">Rating (1–5)</option></select></label>
        <input type="hidden" :name="'questions[' + index + '][required]'" :value="question.required ? '1' : '0'">
        <label class="form-check mb-2"><input class="form-check-input" type="checkbox" x-model="question.required" @change="detach(question)"><span class="form-check-label">Required</span></label>
        <template x-for="(option, optionIndex) in question.options" :key="optionIndex">
          <div class="input-group mb-2"><input class="form-control" :aria-label="'Option ' + (optionIndex + 1)" :name="'questions[' + index + '][options][' + optionIndex + ']'" x-model="question.options[optionIndex]" @input="detach(question)" maxlength="200" required><button class="btn btn-outline-secondary" type="button" :disabled="question.options.length <= 2" @click="detach(question); question.options.splice(optionIndex, 1)" aria-label="Remove option">Remove</button></div>
        </template>
        <button class="btn btn-outline-primary btn-sm me-2" type="button" x-show="['single','multiple'].includes(question.type)" :disabled="question.options.length >= 10" @click="detach(question); question.options.push('')">Add option</button>
        <button class="btn btn-outline-danger btn-sm" type="button" :disabled="questions.length <= 1" @click="questions.splice(index, 1)">Remove question</button>
      </fieldset>
    </template>
    <button class="btn btn-outline-primary btn-sm mb-3" type="button" :disabled="questions.length >= 20" @click="questions.push({type:'text',label:'',required:true,options:[]})">Add question</button>
    <div class="form-actions"><a class="btn btn-outline-secondary" href="<?= esc($cancelUrl) ?>" data-app-modal-close>Cancel</a><button class="btn btn-primary" type="submit">Save draft</button></div>
  </form>
</div>
<?= $this->endSection() ?>
