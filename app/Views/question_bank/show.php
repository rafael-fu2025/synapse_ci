<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<a class="btn btn-outline-secondary btn-sm mb-3" href="<?= esc($baseUrl) ?>">Back to question bank</a>
<div class="card"><div class="card-body pt-3"><h2 class="h5"><?= esc($selected['question']['label']) ?></h2>
<p class="text-muted">Bank #<?= (int) $entry['id'] ?> · Revision <?= (int) $selected['revision'] ?> · <?= esc(ucfirst($entry['status'])) ?> · <?= esc($selected['category']) ?> · <?= esc($selected['audience']) ?></p>
<p class="small">Earlier revisions are read-only. Survey copies remain independent of the bank. <?= $selected['question']['required'] ? 'Required' : 'Optional' ?> by default.</p>
<?= view('question_bank/_preview', ['question' => $selected['question'], 'reviewNote' => $selected['review_note']], ['saveData' => false]) ?>
</div></div>
<div class="card"><div class="card-body pt-3"><h2 class="h6">Revision history</h2><ul class="list-group list-group-flush">
<?php foreach ($revisions as $revision): ?><li class="list-group-item d-flex flex-wrap justify-content-between gap-2"><a href="<?= esc($baseUrl . '/' . (int) $entry['id'] . '?revision=' . (int) $revision['revision']) ?>">Revision <?= (int) $revision['revision'] ?></a><span class="small text-muted"><?= esc($revision['created_at']) ?> UTC</span></li><?php endforeach; ?>
</ul></div></div>
<?= $this->endSection() ?>
