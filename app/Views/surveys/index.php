<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-surveys.css'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('guidance/question-bank'), 'attr') ?>"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>Question bank</a>
<a class="btn btn-primary btn-sm" href="<?= esc(site_url('guidance/surveys/create'), 'attr') ?>" data-app-modal-open><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Create survey</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section class="guidance-ui gs-workspace" data-refresh-owner="surveys.staff" data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div class="gs-sheet">
    <header class="gs-band gs-intro">
      <h2 class="gs-intro-title">Survey management</h2>
      <p class="gs-intro-desc">Prepare drafts, publish questionnaires, and review identified responses from your Guidance surveys.</p>
      <div class="gs-notice" role="note">
        <i class="bi bi-shield-lock" aria-hidden="true"></i>
        <p>These surveys collect identified responses, not anonymous feedback. Published surveys cannot be edited; create a new survey for corrections. Windows use Asia/Manila when entered.</p>
      </div>
    </header>
    <div class="gs-results-region" data-refresh-region="surveys.list">
      <div class="gs-results-head">
        <h3 class="gs-results-title">Survey library</h3>
        <span class="gs-count"><?= number_format((int) ($pagination['total'] ?? count($items))) ?> surveys</span>
      </div>
      <?php if ($items === []): ?>
        <div class="gs-empty" role="status"><i class="bi bi-clipboard2" aria-hidden="true"></i><h3>No surveys yet.</h3><p>Create a survey draft to begin collecting responses.</p></div>
      <?php else: ?>
        <div class="gs-table-wrap">
          <table class="gs-table gs-survey-table" role="table">
            <caption class="visually-hidden">Guidance surveys, newest first</caption>
            <thead role="rowgroup"><tr role="row"><th scope="col">Survey</th><th scope="col">Audience</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
            <tbody role="rowgroup">
              <?php foreach ($items as $survey): $base = site_url('guidance/surveys/' . $survey['id']); $externalUrl = trim((string) ($survey['external_url'] ?? '')); ?>
                <tr data-survey-row role="row">
                  <td class="gs-survey-cell" role="cell">
                    <strong class="gs-survey-title"><?= esc($survey['title']) ?></strong>
                    <span class="gs-meta"><i class="bi <?= $externalUrl !== '' ? 'bi-box-arrow-up-right' : 'bi-ui-checks' ?>" aria-hidden="true"></i><?= $externalUrl !== '' ? 'External Link' : 'In-app questionnaire' ?></span>
                  </td>
                  <td class="gs-audience-cell" role="cell">
                    <span class="gs-cell-label" aria-hidden="true">Audience</span>
                    <span><?= esc(ucfirst($survey['audience'])) ?></span>
                    <?= view('announcements/_targeting_summary', ['targetRecord' => $survey], ['saveData' => false]) ?>
                  </td>
                  <td class="gs-status-cell" role="cell">
                    <span class="gs-cell-label" aria-hidden="true">Status</span>
                    <span class="gs-status is-<?= esc($survey['status'], 'attr') ?>"><?= esc(ucfirst($survey['status'])) ?></span>
                  </td>
                  <td class="gs-actions-cell" role="cell">
                    <span class="gs-cell-label" aria-hidden="true">Actions</span>
                    <div class="gs-row-actions">
                      <?php if ($externalUrl !== ''): ?><a href="<?= esc($externalUrl, 'attr') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Open link</a><?php endif; ?>
                      <?php if ($survey['status'] === 'draft'): ?>
                        <a href="<?= esc($base . '/edit', 'attr') ?>" class="btn btn-outline-primary btn-sm" data-app-modal-open>Edit draft</a>
                        <form method="post" action="<?= esc($base . '/publish', 'attr') ?>" data-background-submit data-confirm="Publish this identified survey? Questions, audience and dates will become immutable." data-confirm-title="Publish survey" data-confirm-label="Publish" data-confirm-tone="warning"><?= csrf_field() ?><input type="hidden" name="version" value="<?= esc($survey['version'], 'attr') ?>"><button class="btn btn-primary btn-sm" type="submit">Publish</button></form>
                      <?php else: ?>
                        <a href="<?= esc($base . '/responses', 'attr') ?>" class="btn btn-outline-primary btn-sm">Responses</a>
                        <?php if ($survey['status'] === 'published'): ?><form method="post" action="<?= esc($base . '/close', 'attr') ?>" data-background-submit data-confirm="Close this survey? No further submissions will be accepted. Existing responses will remain available." data-confirm-title="Close survey" data-confirm-label="Close survey" data-confirm-tone="warning"><?= csrf_field() ?><input type="hidden" name="version" value="<?= esc($survey['version'], 'attr') ?>"><button class="btn btn-outline-danger btn-sm" type="submit">Close</button></form><?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <div class="gs-pagination-band"><?= $this->include('partials/table_pagination') ?></div>
    </div>
  </div>
</section>
<?= $this->endSection() ?>
