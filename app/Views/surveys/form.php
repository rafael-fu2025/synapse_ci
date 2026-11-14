<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$externalUrl = trim((string) ($survey['external_url'] ?? ''));
$surveyMode = $externalUrl !== '' ? 'external' : 'internal';
$rawQuestions = $survey['questions'] ?? [];
$questions = is_array($rawQuestions) && $rawQuestions !== []
    ? $rawQuestions
    : [['type' => 'text', 'label' => '', 'required' => true, 'options' => []]];
$aiStatus = is_array($aiPrefillStatus ?? null) ? $aiPrefillStatus : ['available' => false, 'reason' => 'AI assistance is currently unavailable for Guidance.'];
$aiAvailable = !empty($aiStatus['available']);
$aiEndpoint = is_string($aiPrefillUrl ?? null) && $aiPrefillUrl !== '' ? $aiPrefillUrl : site_url('guidance/surveys/ai-prefill');
?>
<div x-data="SynapseSurveyBuilder(<?= esc(json_encode($questions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?>, <?= esc(json_encode($survey['audience'] ?? 'all'), 'attr') ?>, <?= esc(json_encode(site_url('guidance/question-bank/catalog')), 'attr') ?>, <?= esc(json_encode($surveyMode), 'attr') ?>, <?= esc(json_encode($externalUrl), 'attr') ?>, <?= esc(json_encode($aiEndpoint), 'attr') ?>)">
  <div class="alert alert-info small">In-app surveys collect encrypted identified responses visible only to authorized Guidance administrators and supervisors. External links (such as Google Forms) open on the external form provider. Publishing permanently freezes the configuration, audience, and availability window.</div>
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
    <div class="mb-3">
      <div class="form-label fw-semibold">Survey Delivery Mode</div>
      <div class="row g-2">
        <div class="col-md-6">
          <label class="d-flex align-items-start gap-2 p-2 rounded border bg-body-tertiary h-100" style="cursor: pointer;">
            <input class="form-check-input mt-1 flex-shrink-0" type="radio" name="survey_mode" value="internal" x-model="surveyMode" @change="setMode('internal')">
            <span class="small">
              <strong class="d-block">In-App Questions (Encrypted)</strong>
              <span class="text-muted">Collect identified responses directly inside Synapse.</span>
            </span>
          </label>
        </div>
        <div class="col-md-6">
          <label class="d-flex align-items-start gap-2 p-2 rounded border bg-body-tertiary h-100" style="cursor: pointer;">
            <input class="form-check-input mt-1 flex-shrink-0" type="radio" name="survey_mode" value="external" x-model="surveyMode" @change="setMode('external')">
            <span class="small">
              <strong class="d-block">Google Form / External Link</strong>
              <span class="text-muted">Direct respondents to an external Google Form or web questionnaire URL.</span>
            </span>
          </label>
        </div>
      </div>
    </div>

    <div class="border rounded p-3 mb-3 bg-body-tertiary" x-show="surveyMode === 'external'" x-cloak>
      <label for="surveyExternalUrl" class="form-label fw-semibold">Google Form / External Survey URL</label>
      <input id="surveyExternalUrl"
             name="external_url"
             type="url"
             class="form-control"
             maxlength="2048"
             x-model="externalUrl"
             :required="surveyMode === 'external'"
             :disabled="surveyMode !== 'external'"
             placeholder="https://docs.google.com/forms/d/e/.../viewform or https://forms.gle/..."
             autocomplete="off">
      <div class="form-text">
        Paste a full <code>https://</code> Google Form link (or other institutional form URL). Targeted respondents will be able to launch it in a new tab.
      </div>
    </div>

    <div x-show="surveyMode === 'internal'" x-cloak>
    <fieldset class="border-0 p-0 m-0" :disabled="surveyMode !== 'internal'">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h6 class="mb-0">Questions <span class="text-muted small" x-text="questions.length + ' / 20'"></span></h6>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($aiAvailable): ?>
          <button type="button" class="btn btn-outline-primary btn-sm" @click="toggleAi()" :aria-expanded="aiOpen">
            <i class="bi bi-stars me-1" aria-hidden="true"></i>Prefill with AI (Paste / Upload)
          </button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-primary btn-sm" @click="openBank()" :aria-expanded="bankOpen">Add from question bank</button>
      </div>
    </div>
    <?php if (!$aiAvailable && !empty($aiStatus['reason'])): ?>
      <p class="small text-muted mb-3"><i class="bi bi-stars me-1" aria-hidden="true"></i><?= esc((string) $aiStatus['reason']) ?></p>
    <?php endif; ?>
    <?php if ($aiAvailable): ?>
      <section class="border rounded p-3 mb-3 bg-body-tertiary" x-show="aiOpen" x-cloak aria-label="AI survey prefill">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
          <div>
            <strong class="d-block small">Prefill Questions from Text or File (AI)</strong>
            <p class="small text-muted mb-0">Paste survey items or upload <code>.txt</code>, <code>.md</code>, <code>.csv</code>, <code>.docx</code>, or <code>.pdf</code> (max 2 MB). Do not upload student records or counselling notes—AI runs strictly on this input with no access to system data.</p>
          </div>
          <button type="button" class="btn-close btn-sm" aria-label="Close AI prefill panel" @click="toggleAi()"></button>
        </div>

        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1" for="surveyAiSourceText">Paste survey text</label>
          <textarea id="surveyAiSourceText" class="form-control form-control-sm" rows="4" maxlength="24000" x-model="aiText" :disabled="aiBusy" placeholder="Example:&#10;1. How satisfied are you with Guidance orientation? (1-5)&#10;2. Which topics do you need support with? [Time management, Stress, Career]"></textarea>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold mb-1" for="surveyAiSourceFile">Or upload questionnaire file</label>
          <div class="input-group input-group-sm">
            <input id="surveyAiSourceFile" type="file" class="form-control" x-ref="aiFileInput" accept=".txt,.md,.csv,.docx,.pdf" @change="onAiFileChange($event)" :disabled="aiBusy">
            <button type="button" class="btn btn-outline-secondary" x-show="aiFileName !== ''" @click="clearAiFile($refs.aiFileInput)" :disabled="aiBusy">Clear file</button>
          </div>
          <div class="form-text" x-show="aiFileName !== ''" x-text="'Selected file: ' + aiFileName"></div>
        </div>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
          <div class="d-flex flex-wrap align-items-center gap-3 small">
            <div class="btn-group btn-group-sm" role="radiogroup" aria-label="AI question merge mode">
              <button type="button" class="btn" :class="aiMergeMode === 'replace' ? 'btn-primary' : 'btn-outline-secondary'" role="radio" :aria-checked="aiMergeMode === 'replace'" @click="aiMergeMode = 'replace'" :disabled="aiBusy">Replace draft questions</button>
              <button type="button" class="btn" :class="aiMergeMode === 'append' ? 'btn-primary' : 'btn-outline-secondary'" role="radio" :aria-checked="aiMergeMode === 'append'" @click="aiMergeMode = 'append'" :disabled="aiBusy">Append (up to 20 total)</button>
            </div>
            <label class="form-check form-check-inline m-0">
              <input class="form-check-input" type="checkbox" x-model="aiFillMeta" :disabled="aiBusy">
              <span class="form-check-label">Fill blank title &amp; description</span>
            </label>
          </div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" x-show="aiBusy" @click="cancelAiPrefill()">Cancel</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" x-show="aiRawLog.length > 0 || aiBusy" @click="aiShowRawSse = !aiShowRawSse" :aria-expanded="aiShowRawSse">
              <i class="bi bi-terminal me-1" aria-hidden="true"></i><span x-text="aiShowRawSse ? 'Hide Raw SSE' : 'Raw SSE (' + aiRawLog.length + ')'"></span>
            </button>
            <button type="button" class="btn btn-primary btn-sm" @click="runAiPrefill($refs.aiFileInput, document.getElementById('surveyTitle'), document.getElementById('surveyDescription'), false)" :disabled="aiBusy">
              <span x-show="!aiBusy">Extract Questions</span>
              <span x-show="aiBusy" x-cloak x-text="'Working (' + aiElapsedSec + 's)…'">Extracting…</span>
            </button>
          </div>
        </div>

        <div class="border rounded p-2 mb-2 bg-body" x-show="aiBusy || (aiPercent > 0 && aiPercent < 100) || (aiStage === 'done' && aiMessage)" aria-live="polite">
          <div class="d-flex justify-content-between align-items-center small mb-1">
            <span class="fw-semibold" x-text="aiStageLabel || 'Processing…'"></span>
            <span class="text-muted">
              <span x-show="aiTotalSteps > 0" x-text="'Step ' + aiStep + '/' + aiTotalSteps + ' · '"></span>
              <span x-text="aiPercent + '%'"></span>
              <span x-show="aiElapsedSec > 0" x-text="' · ' + aiElapsedSec + 's'"></span>
            </span>
          </div>
          <div class="progress" style="height: 8px;" role="progressbar" :aria-valuenow="aiPercent" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" :class="aiBusy ? 'progress-bar-striped progress-bar-animated' : 'bg-success'" :style="'width: ' + aiPercent + '%'"></div>
          </div>
        </div>

        <div class="border rounded p-2 mb-2 bg-body" x-show="aiShowRawSse" x-cloak>
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold">Raw AI SSE Stream (sanitized completion frames)</span>
            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" x-show="!aiBusy && aiRawLog.length > 0" @click="aiRawLog = []">Clear log</button>
          </div>
          <pre class="small mb-0 p-2 rounded border bg-body-tertiary font-monospace" style="max-height: 170px; overflow-y: auto; white-space: pre-wrap; word-break: break-word;" x-text="aiRawLog.length > 0 ? aiRawLog.join('\n') : 'Waiting for AI SSE frames…'"></pre>
        </div>

        <div class="border border-warning-subtle rounded p-2 mb-2 bg-body" x-show="aiClarifications.length > 0" x-cloak>
          <div class="small fw-semibold mb-1"><i class="bi bi-question-circle me-1" aria-hidden="true"></i>AI Clarifications (optional)</div>
          <p class="small text-muted mb-2">Answer any ambiguous scale/question items below and re-extract to refine the prefilled questions.</p>
          <template x-for="(clar, cIdx) in aiClarifications" :key="clar.id || cIdx">
            <div class="mb-2 pb-2 border-bottom">
              <label class="form-label small fw-semibold mb-1 d-block" :for="'aiClar-' + cIdx" x-text="clar.question"></label>
              <div class="d-flex flex-wrap gap-1 mb-1" x-show="clar.suggested_options.length > 0">
                <template x-for="(opt, oIdx) in clar.suggested_options" :key="oIdx">
                  <button type="button" class="btn btn-sm py-0 px-2" :class="clar.answer === opt ? 'btn-primary' : 'btn-outline-secondary'" @click="pickClarificationOption(clar, opt)" :disabled="aiBusy" x-text="opt"></button>
                </template>
              </div>
              <input :id="'aiClar-' + cIdx" type="text" class="form-control form-control-sm" maxlength="400" x-model="clar.answer" :disabled="aiBusy" placeholder="Type clarification answer (optional)…">
            </div>
          </template>
          <button type="button" class="btn btn-outline-primary btn-sm" @click="runAiPrefill($refs.aiFileInput, document.getElementById('surveyTitle'), document.getElementById('surveyDescription'), true)" :disabled="aiBusy">Apply Clarifications &amp; Re-extract</button>
        </div>

        <div class="alert alert-danger small py-2 mb-2" role="alert" x-show="aiError" x-text="aiError"></div>
        <div class="alert alert-success small py-2 mb-2" role="status" x-show="aiMessage" x-text="aiMessage"></div>
        <ul class="alert alert-warning small py-2 ps-4 mb-0" role="status" x-show="aiWarnings.length > 0">
          <template x-for="(warning, idx) in aiWarnings" :key="idx">
            <li x-text="warning"></li>
          </template>
        </ul>
      </section>
    <?php endif; ?>
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
        <label class="form-label d-block">Answer type<select class="form-select" :name="'questions[' + index + '][type]'" x-model="question.type" @change="detach(question); question.options = ['single','multiple'].includes(question.type) ? ['', ''] : []; if (!['single','multiple'].includes(question.type)) { delete question.allow_other; } if (question.type === 'rating') { question.scale_min_label = question.scale_min_label || ''; question.scale_max_label = question.scale_max_label || ''; } else { delete question.scale_min_label; delete question.scale_max_label; }"><option value="text">Text (up to 2,000 characters)</option><option value="single">Single choice</option><option value="multiple">Multiple choice</option><option value="rating">Rating (1–5)</option></select></label>
        <template x-if="question.type === 'rating'">
          <div class="row g-2 mb-2">
            <div class="col-sm-6">
              <label class="form-label small mb-1" :for="'qMinLabel-' + index">Label for 1 (optional)</label>
              <input class="form-control form-control-sm" :id="'qMinLabel-' + index" :name="'questions[' + index + '][scale_min_label]'" x-model="question.scale_min_label" @input="detach(question)" maxlength="100" placeholder="e.g. Lowest / Strongly disagree">
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1" :for="'qMaxLabel-' + index">Label for 5 (optional)</label>
              <input class="form-control form-control-sm" :id="'qMaxLabel-' + index" :name="'questions[' + index + '][scale_max_label]'" x-model="question.scale_max_label" @input="detach(question)" maxlength="100" placeholder="e.g. Highest / Strongly agree">
            </div>
          </div>
        </template>
        <input type="hidden" :name="'questions[' + index + '][required]'" :value="question.required ? '1' : '0'">
        <label class="form-check mb-2"><input class="form-check-input" type="checkbox" x-model="question.required" @change="detach(question)"><span class="form-check-label">Required</span></label>
        <template x-if="['single','multiple'].includes(question.type)">
          <div class="mb-2">
            <input type="hidden" :name="'questions[' + index + '][allow_other]'" :value="question.allow_other ? '1' : '0'">
            <label class="form-check"><input class="form-check-input" type="checkbox" x-model="question.allow_other" @change="detach(question)"><span class="form-check-label">Allow &ldquo;Other (please specify)&rdquo; custom answer</span></label>
          </div>
        </template>
        <template x-for="(option, optionIndex) in question.options" :key="optionIndex">
          <div class="input-group mb-2"><input class="form-control" :aria-label="'Option ' + (optionIndex + 1)" :name="'questions[' + index + '][options][' + optionIndex + ']'" x-model="question.options[optionIndex]" @input="detach(question)" maxlength="200" required><button class="btn btn-outline-secondary" type="button" :disabled="question.options.length <= 2" @click="detach(question); question.options.splice(optionIndex, 1)" aria-label="Remove option">Remove</button></div>
        </template>
        <button class="btn btn-outline-primary btn-sm me-2" type="button" x-show="['single','multiple'].includes(question.type)" :disabled="question.options.length >= 10" @click="detach(question); question.options.push('')">Add option</button>
        <button class="btn btn-outline-danger btn-sm" type="button" :disabled="questions.length <= 1" @click="questions.splice(index, 1)">Remove question</button>
      </fieldset>
    </template>
    <button class="btn btn-outline-primary btn-sm mb-3" type="button" :disabled="questions.length >= 20" @click="questions.push({type:'text',label:'',required:true,options:[],scale_min_label:'',scale_max_label:''})">Add question</button>
    </fieldset>
    </div>
    <div class="form-actions"><a class="btn btn-outline-secondary" href="<?= esc($cancelUrl) ?>" data-app-modal-close>Cancel</a><button class="btn btn-primary" type="submit">Save draft</button></div>
  </form>
</div>
<?= $this->endSection() ?>
