<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-question-bank.css'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<a class="btn btn-outline-secondary btn-sm" href="<?= esc($baseUrl, 'attr') ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to question bank</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $refreshOwner = 'question-bank.history.' . (int) $entry['id']; ?>
<section class="guidance-ui qb-workspace qb-history" data-refresh-owner="<?= esc($refreshOwner, 'attr') ?>" data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div class="qb-sheet">
    <div class="qb-band qb-selected-revision" data-refresh-region="selected-revision">
      <div class="qb-history-top">
        <span class="qb-record-id">Bank #<?= (int) $entry['id'] ?> <span aria-hidden="true">·</span> Revision <?= (int) $selected['revision'] ?></span>
        <span class="qb-status <?= $entry['status'] === 'active' ? 'is-active' : 'is-archived' ?>"><?= esc(ucfirst($entry['status'])) ?></span>
      </div>
      <h2 class="qb-history-question"><?= esc($selected['question']['label']) ?></h2>
      <dl class="qb-history-meta">
        <div><dt>Category</dt><dd><?= esc($selected['category']) ?></dd></div>
        <div><dt>Intended audience</dt><dd><?= esc($selected['audience'] === 'all' ? 'Students and employees' : ucfirst($selected['audience'])) ?></dd></div>
        <div><dt>Answer type</dt><dd><?= esc(ucfirst($selected['question']['type'])) ?></dd></div>
        <div><dt>Response</dt><dd><?= $selected['question']['required'] ? 'Required' : 'Optional' ?> by default</dd></div>
      </dl>
      <p class="qb-history-note"><i class="bi bi-lock" aria-hidden="true"></i>Earlier revisions are read-only. Survey copies remain independent of the bank.</p>
      <?= view('question_bank/_preview', ['question' => $selected['question'], 'reviewNote' => $selected['review_note']], ['saveData' => false]) ?>
    </div>
    <section class="qb-history-revisions" aria-labelledby="bankRevisionHistory">
      <div class="qb-results-head">
        <h2 class="qb-results-title" id="bankRevisionHistory">Revision history</h2>
        <span class="qb-count"><?= number_format(count($revisions)) ?> <?= count($revisions) === 1 ? 'revision' : 'revisions' ?></span>
      </div>
      <ul class="qb-revision-list">
        <?php foreach ($revisions as $revision): ?>
          <li class="qb-revision-row <?= (int) $revision['revision'] === (int) $selected['revision'] ? 'is-selected' : '' ?>">
            <a href="<?= esc($baseUrl . '/' . (int) $entry['id'] . '?revision=' . (int) $revision['revision'], 'attr') ?>"<?= (int) $revision['revision'] === (int) $selected['revision'] ? ' aria-current="page"' : '' ?>>Revision <?= (int) $revision['revision'] ?></a>
            <span class="qb-revision-time"><?= esc($revision['created_at']) ?> UTC</span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>
</section>
<?= $this->endSection() ?>
