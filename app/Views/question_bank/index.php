<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-question-bank.css'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('guidance/surveys'), 'attr') ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Surveys</a>
<a class="btn btn-primary btn-sm" href="<?= esc($baseUrl . '/create', 'attr') ?>" data-app-modal-open><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add bank question</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section class="guidance-ui qb-workspace" data-refresh-owner="question-bank.list" data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div class="qb-sheet" data-live-filters data-mutation-refresh data-filter-url="<?= esc($baseUrl, 'attr') ?>" data-filter-key="question-bank">
    <header class="qb-band qb-header-band">
      <h2 class="qb-intro-title">Reusable question library</h2>
      <p class="qb-intro-desc">Bank edits create revisions; existing survey copies never change. Audience labels are guidance for authors, not automatic survey targeting.</p>
      <div class="qb-notice" role="note">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        <p><strong>Author review.</strong> Review sensitive wellbeing questions before reuse. This library does not validate clinical screening tools or provide diagnostic scoring. Respect instrument licensing and administration requirements.</p>
      </div>
      <form method="GET" action="<?= esc($baseUrl, 'attr') ?>" class="qb-filter-grid" role="search" aria-label="Filter question bank" data-filter-form>
        <div class="qb-filter-field qb-search-field">
          <label for="bank-search" class="qb-filter-label">Search questions</label>
          <input id="bank-search" class="form-control" name="q" maxlength="100" value="<?= esc($search, 'attr') ?>" placeholder="Question text or category">
        </div>
        <div class="qb-filter-field">
          <label for="bank-category" class="qb-filter-label">Category</label>
          <input id="bank-category" class="form-control" name="category" maxlength="80" value="<?= esc($category, 'attr') ?>" placeholder="Exact category">
        </div>
        <div class="qb-filter-field">
          <label for="bank-audience" class="qb-filter-label">Audience</label>
          <select id="bank-audience" name="audience" class="form-select">
            <?php foreach (['' => 'Any audience', 'all' => 'Both audiences', 'students' => 'Students + both', 'employees' => 'Employees + both'] as $value => $label): ?>
              <option value="<?= esc($value, 'attr') ?>" <?= $audience === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="qb-filter-field">
          <label for="bank-status" class="qb-filter-label">Status</label>
          <select id="bank-status" name="status" class="form-select">
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archived</option>
          </select>
        </div>
        <div class="qb-filter-actions">
          <button type="submit" class="btn btn-primary qb-btn"><i class="bi bi-search" aria-hidden="true"></i>Search</button>
          <a href="<?= esc($baseUrl, 'attr') ?>" class="btn btn-outline-secondary qb-btn" data-filter-reset>Clear</a>
        </div>
      </form>
      <?= view('partials/live_filter_feedback') ?>
    </header>
    <div class="qb-results-region" data-filter-region="results" data-refresh-region="results">
      <div class="qb-results-head">
        <h3 class="qb-results-title"><?= $status === 'archived' ? 'Archived questions' : 'Active questions' ?></h3>
        <span class="qb-count"><?= number_format((int) $total) ?> <?= (int) $total === 1 ? 'question' : 'questions' ?></span>
      </div>
      <?php if ($items === []): ?>
        <div class="qb-empty" role="status">
          <i class="bi bi-journal-text" aria-hidden="true"></i>
          <h3>No matching questions</h3>
          <p>Add a bank question or adjust the filters.</p>
        </div>
      <?php else: ?>
        <div class="qb-table-wrap">
          <table class="qb-table" role="table">
            <caption class="visually-hidden">Reusable Guidance questions, newest entries first</caption>
            <thead role="rowgroup"><tr role="row"><th scope="col">Question</th><th scope="col">Category / audience</th><th scope="col">Revision</th><th scope="col">Actions</th></tr></thead>
            <tbody role="rowgroup">
              <?php foreach ($items as $item): $url = $baseUrl . '/' . (int) $item['id']; ?>
                <tr data-bank-row role="row">
                  <td class="qb-question-cell" role="cell">
                    <strong class="qb-question-title"><?= esc($item['question']['label']) ?></strong>
                    <small class="qb-question-meta"><?= esc(ucfirst($item['question']['type'])) ?> <span aria-hidden="true">·</span> <?= $item['question']['required'] ? 'Required by default' : 'Optional by default' ?></small>
                    <details class="qb-preview-disclosure">
                      <summary>Preview</summary>
                      <?= view('question_bank/_preview', ['question' => $item['question'], 'reviewNote' => $item['review_note']], ['saveData' => false]) ?>
                    </details>
                  </td>
                  <td class="qb-category-cell" role="cell">
                    <span class="qb-cell-label" aria-hidden="true">Category / audience</span>
                    <span class="qb-category"><?= esc($item['category']) ?></span>
                    <small class="qb-meta"><?= esc($item['audience'] === 'all' ? 'Students and employees' : ucfirst($item['audience'])) ?></small>
                  </td>
                  <td class="qb-revision-cell" role="cell">
                    <span class="qb-cell-label" aria-hidden="true">Revision</span>
                    <span class="qb-revision">Revision <?= (int) $item['revision'] ?></span>
                    <small class="qb-status <?= $item['status'] === 'active' ? 'is-active' : 'is-archived' ?>"><?= esc(ucfirst($item['status'])) ?></small>
                  </td>
                  <td class="qb-actions-cell" role="cell">
                    <span class="qb-cell-label" aria-hidden="true">Actions</span>
                    <div class="qb-row-actions">
                      <a class="btn btn-outline-secondary btn-sm" href="<?= esc($url, 'attr') ?>">History</a>
                      <?php if ($item['status'] === 'active'): ?>
                        <a class="btn btn-outline-primary btn-sm" href="<?= esc($url . '/edit', 'attr') ?>" data-app-modal-open>Revise</a>
                        <form method="POST" action="<?= esc($url . '/archive', 'attr') ?>" data-background-submit data-confirm="Archive this question? It will disappear from new selections. Existing survey copies and revision history will be preserved." data-confirm-title="Archive bank question" data-confirm-label="Archive" data-confirm-tone="warning">
                          <?= csrf_field() ?>
                          <input type="hidden" name="version" value="<?= (int) $item['version'] ?>">
                          <button type="submit" class="btn btn-outline-secondary btn-sm">Archive</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <div class="qb-pagination-band">
        <?= view('partials/table_pagination', [
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'per_page' => 20],
            'filters' => ['q' => $search, 'status' => $status], 'baseUrl' => $baseUrl,
            'paginationExtraQuery' => ['category' => $category, 'audience' => $audience],
            'paginationLiveFilters' => true, 'paginationLabel' => 'Question bank pages',
        ], ['saveData' => false]) ?>
      </div>
    </div>
  </div>
</section>
<?= $this->endSection() ?>
