<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="card"><div class="card-body pt-3">
  <h5 class="card-title"><?= esc($survey['title']) ?></h5>
  <p><strong><?= esc(trim($response['first_name'] . ' ' . $response['last_name'])) ?></strong><br><span class="text-muted small">Submitted <?= esc($response['submitted_at']) ?> UTC</span></p>
  <div class="alert alert-warning small">Confidential identified response. This access has been audited. Share only through authorized Guidance processes.</div>
  <dl><?php foreach ($answers as $answer): ?><dt class="mb-2"><?= esc($answer['question']) ?></dt><dd class="text-break mb-4"><?php if ($answer['value'] === null): ?><span class="text-muted">Not answered</span><?php elseif (is_array($answer['value'])): ?><ul><?php foreach ($answer['value'] as $value): ?><li><?= esc($value) ?></li><?php endforeach; ?></ul><?php else: ?><?= nl2br(esc((string) $answer['value'])) ?><?php endif; ?></dd><?php endforeach; ?></dl>
  <a href="<?= esc(site_url('guidance/surveys/' . $survey['id'] . '/responses')) ?>" class="btn btn-outline-secondary">Back to responses</a>
</div></div>
<?= $this->endSection() ?>
