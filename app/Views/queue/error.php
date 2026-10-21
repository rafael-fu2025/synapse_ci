<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="alert alert-warning" role="alert"><?= esc($message) ?></div>
<a class="btn btn-outline-primary" href="<?= site_url('/' . $unit . '/queue') ?>">Return to today’s queue</a>
<?= $this->endSection() ?>
