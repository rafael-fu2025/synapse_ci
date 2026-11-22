<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link href="<?= base_url('assets/css/queue.css') ?>" rel="stylesheet">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="q-error-wrap">
  <div class="alert" data-banner-dismiss="off" role="alert"><?= esc($message) ?></div>
  <a class="btn btn-outline-primary btn-sm" href="<?= site_url('/' . $unit . '/queue') ?>">Return to today’s queue</a>
</div>
<?= $this->endSection() ?>
