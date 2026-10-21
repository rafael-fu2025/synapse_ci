<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a class="btn btn-outline-secondary btn-sm" href="<?= site_url('guidance/surveys') ?>">Surveys</a>
<a class="btn btn-primary btn-sm" href="<?= esc($baseUrl . '/create') ?>" data-app-modal-open>Add bank question</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<p class="text-muted">Reusable Guidance questions. Bank edits create revisions; existing survey copies never change. Audience labels are guidance for authors, not automatic survey targeting.</p>
<div class="alert alert-warning small">Review sensitive wellbeing questions before reuse. This library does not validate clinical screening tools or provide diagnostic scoring. Respect instrument licensing and administration requirements.</div>
<div class="card" data-live-filters data-filter-url="<?= esc($baseUrl) ?>" data-filter-key="question-bank"><div class="card-body pt-3">
<form method="GET" action="<?= esc($baseUrl) ?>" class="row g-2 mb-3" role="search" data-filter-form>
<div class="col-md-4"><label for="bank-search" class="form-label">Search questions</label><input id="bank-search" class="form-control" name="q" maxlength="100" value="<?= esc($search) ?>" placeholder="Question text or category"></div>
<div class="col-md-2"><label for="bank-category" class="form-label">Category</label><input id="bank-category" class="form-control" name="category" maxlength="80" value="<?= esc($category) ?>" placeholder="Exact category"></div>
<div class="col-md-2"><label for="bank-audience" class="form-label">Audience</label><select id="bank-audience" name="audience" class="form-select"><?php foreach (['' => 'Any audience', 'all' => 'Both audiences', 'students' => 'Students + both', 'employees' => 'Employees + both'] as $value => $label): ?><option value="<?= esc($value) ?>" <?= $audience === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label for="bank-status" class="form-label">Status</label><select id="bank-status" name="status" class="form-select"><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archived</option></select></div>
<div class="col-md-2 d-flex align-items-end gap-2"><button type="submit" class="btn btn-primary">Search</button><a href="<?= esc($baseUrl) ?>" class="btn btn-outline-secondary" data-filter-reset>Clear</a></div>
</form>
<?= view('partials/live_filter_feedback') ?>
<div data-filter-region="results">
<p class="small text-muted"><?= (int) $total ?> matching question(s)</p>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Question</th><th>Category / audience</th><th>Revision</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($items as $item): $url = $baseUrl . '/' . (int) $item['id']; ?>
<tr><td><strong><?= esc($item['question']['label']) ?></strong><small class="d-block text-muted"><?= esc(ucfirst($item['question']['type'])) ?> · <?= $item['question']['required'] ? 'Required by default' : 'Optional by default' ?></small>
<details class="mt-2"><summary class="small">Preview</summary><?= view('question_bank/_preview', ['question' => $item['question'], 'reviewNote' => $item['review_note']], ['saveData' => false]) ?></details></td>
<td><?= esc($item['category']) ?><small class="d-block text-muted"><?= esc($item['audience'] === 'all' ? 'Students and employees' : ucfirst($item['audience'])) ?></small></td><td>Revision <?= (int) $item['revision'] ?><small class="d-block text-muted"><?= esc(ucfirst($item['status'])) ?></small></td>
<td><div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary btn-sm" href="<?= esc($url) ?>">History</a>
<?php if ($item['status'] === 'active'): ?><a class="btn btn-outline-primary btn-sm" href="<?= esc($url . '/edit') ?>" data-app-modal-open>Revise</a>
<form method="POST" action="<?= esc($url . '/archive') ?>" data-confirm="Archive this question? It will disappear from new selections. Existing survey copies and revision history will be preserved." data-confirm-title="Archive bank question" data-confirm-label="Archive" data-confirm-tone="warning"><?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $item['version'] ?>"><button type="submit" class="btn btn-outline-secondary btn-sm">Archive</button></form><?php endif; ?>
</div></td></tr><?php endforeach; ?>
<?php if ($items === []): ?><tr><td colspan="4" class="text-muted">No matching questions. Add a bank question or adjust the filters.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="d-flex gap-2 align-items-center" aria-label="Question bank pages">
<?php $filters = ['q' => $search, 'category' => $category, 'audience' => $audience, 'status' => $status]; ?>
<?php if ($page > 1): ?><a class="btn btn-outline-secondary btn-sm" data-filter-link href="<?= esc($baseUrl . '?' . http_build_query($filters + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
<span class="small">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
<?php if ($page < $pages): ?><a class="btn btn-outline-secondary btn-sm" data-filter-link href="<?= esc($baseUrl . '?' . http_build_query($filters + ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
</nav></div></div></div>
<?= $this->endSection() ?>
