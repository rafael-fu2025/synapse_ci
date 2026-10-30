<?php require __DIR__ . '/_dates.php'; ?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a class="btn btn-primary btn-sm" href="<?= esc($baseUrl . '/create') ?>" data-app-modal-open><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New draft</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<p class="text-muted"><?= esc(ucfirst($unit)) ?> announcements only. Published content is immutable; archive and create a new announcement for corrections.</p>
<div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table align-middle">
<thead><tr><th>Announcement</th><th>Audience</th><th>Status / visibility (Asia/Manila)</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($announcements as $notice): $url = $baseUrl . '/' . (int) $notice['id']; ?>
<tr><td><strong><?= esc($notice['title']) ?></strong><details class="mt-2"><summary class="small">Preview text</summary><div class="mt-2" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= esc($notice['body']) ?></div></details></td>
<td><?= esc(ucfirst($notice['audience'])) ?><?= view('announcements/_targeting_summary', ['targetRecord' => $notice], ['saveData' => false]) ?></td><td><span class="badge bg-secondary"><?= esc(ucfirst($notice['status'])) ?></span><small class="d-block mt-1">From: <?= esc($notice['starts_at'] === null ? 'On publication' : $announcementDate($notice['starts_at'])) ?><br>Until: <?= esc($notice['ends_at'] === null ? 'No expiry' : $announcementDate($notice['ends_at'])) ?></small></td>
<td><div class="d-flex flex-wrap gap-2">
<?php if ($notice['status'] === 'draft'): ?>
<a class="btn btn-outline-primary btn-sm" href="<?= esc($url . '/edit') ?>" data-app-modal-open>Edit draft</a>
<form action="<?= esc($url . '/publish') ?>" method="POST" data-background-submit data-confirm="Publish this announcement to the selected audience? Its text, audience and visibility dates cannot be edited afterward." data-confirm-title="Publish announcement" data-confirm-label="Publish announcement" data-confirm-tone="warning"><?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $notice['version'] ?>"><button class="btn btn-primary btn-sm" type="submit">Publish</button></form>
<?php endif; ?>
<?php if ($notice['status'] !== 'archived'): ?>
<form action="<?= esc($url . '/archive') ?>" method="POST" data-background-submit data-confirm="Archive this announcement? It will no longer be visible in the portal and cannot be republished." data-confirm-title="Archive announcement" data-confirm-label="Archive announcement" data-confirm-tone="warning"><?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $notice['version'] ?>"><button class="btn btn-outline-secondary btn-sm" type="submit">Archive</button></form>
<?php endif; ?>
<?php if ($notice['published_at'] !== null): ?><a class="btn btn-outline-secondary btn-sm" href="<?= esc($baseUrl . '?' . http_build_query(['reads' => $notice['id'], 'page' => $pagination['page']])) ?>">Read history</a><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
<?php if ($announcements === []): ?><tr><td colspan="4" class="text-muted">No announcements yet.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<?= view('partials/table_pagination', [
    'pagination' => $pagination, 'baseUrl' => $baseUrl, 'filters' => [],
    'paginationExtraQuery' => isset($readHistory) ? ['reads' => $readHistory['announcement']['id'], 'read_page' => $readHistory['pagination']['page']] : [],
    'paginationLabel' => 'Announcement pages',
], ['saveData' => false]) ?>
<?php if (isset($readHistory)): ?>
<section class="card"><div class="card-body pt-3"><h2 class="h5">Read history: <?= esc($readHistory['announcement']['title']) ?></h2><p class="text-muted small">A read marker is not acknowledgement or proof of comprehension.</p>
<table class="table"><thead><tr><th>Person</th><th>Marked read at (Asia/Manila)</th></tr></thead><tbody><?php foreach ($readHistory['reads'] as $read): ?><tr><td><?= esc($read['first_name'] . ' ' . $read['last_name']) ?></td><td><?= esc($announcementDate($read['read_at'])) ?></td></tr><?php endforeach; ?><?php if ($readHistory['reads'] === []): ?><tr><td colspan="2">No read markers yet.</td></tr><?php endif; ?></tbody></table>
<?= view('partials/table_pagination', [
    'pagination' => $readHistory['pagination'], 'baseUrl' => $baseUrl, 'filters' => [],
    'paginationPageParam' => 'read_page', 'paginationLabel' => 'Read history pages',
    'paginationExtraQuery' => ['reads' => $readHistory['announcement']['id'], 'page' => $pagination['page']],
], ['saveData' => false]) ?>
</div></section>
<?php endif; ?>
<?= $this->endSection() ?>
