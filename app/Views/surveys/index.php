<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a class="btn btn-outline-secondary btn-sm" href="<?= site_url('guidance/question-bank') ?>">Question bank</a>
<a class="btn btn-primary btn-sm" href="<?= esc(site_url('guidance/surveys/create')) ?>" data-app-modal-open>Create survey</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card"><div class="card-body pt-3">
  <div class="alert alert-info small">These surveys collect identified responses, not anonymous feedback. Published surveys cannot be edited; create a new survey for corrections. Windows use Asia/Manila when entered.</div>
  <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Survey</th><th>Audience</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($items as $survey): $base = site_url('guidance/surveys/' . $survey['id']); ?>
    <tr><td><?= esc($survey['title']) ?></td><td><?= esc(ucfirst($survey['audience'])) ?><?= view('announcements/_targeting_summary', ['targetRecord' => $survey], ['saveData' => false]) ?></td><td><span class="badge bg-secondary"><?= esc(ucfirst($survey['status'])) ?></span></td><td><div class="d-flex gap-2 flex-wrap">
      <?php if ($survey['status'] === 'draft'): ?>
        <a href="<?= esc($base . '/edit') ?>" class="btn btn-outline-primary btn-sm" data-app-modal-open>Edit draft</a>
        <form method="post" action="<?= esc($base . '/publish') ?>" data-confirm="Publish this identified survey? Questions, audience and dates will become immutable." data-confirm-title="Publish survey" data-confirm-label="Publish" data-confirm-tone="warning"><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit">Publish</button></form>
      <?php else: ?>
        <a href="<?= esc($base . '/responses') ?>" class="btn btn-outline-primary btn-sm">Responses</a>
        <?php if ($survey['status'] === 'published'): ?><form method="post" action="<?= esc($base . '/close') ?>" data-confirm="Close this survey? No further submissions will be accepted. Existing responses will remain available." data-confirm-title="Close survey" data-confirm-label="Close survey" data-confirm-tone="warning"><?= csrf_field() ?><button class="btn btn-outline-danger btn-sm" type="submit">Close</button></form><?php endif; ?>
      <?php endif; ?>
    </div></td></tr>
  <?php endforeach; ?>
  <?php if ($items === []): ?><tr><td colspan="4" class="text-center text-muted py-4">No surveys yet.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?= $this->include('partials/table_pagination') ?>
</div></div>
<?= $this->endSection() ?>
