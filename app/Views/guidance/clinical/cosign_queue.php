<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= site_url('guidance') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Guidance Hub
</a>
<a href="<?= site_url('guidance/cases') ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-folder2-open me-1"></i>Cases
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$pendingForms = $pendingForms ?? [];
$actor = $actor ?? [];
$practitionerProfile = $practitionerProfile ?? null;
$canCoSign = (bool) ($canCoSign ?? false);
$hasOversight = (bool) ($hasOversight ?? false);
$actorId = (int) ($actor['id'] ?? 0);
?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2 small">
            <span class="badge bg-<?= $canCoSign ? 'success' : 'secondary' ?>">
                <?= $canCoSign ? 'Qualified Co-Signer' : 'Read / Oversight View' ?>
            </span>
            <?php if (! empty($practitionerProfile['credential_tier'])): ?>
                <span class="text-muted">
                    Credential Tier: <strong><?= esc(ucwords(str_replace('_', ' ', (string) $practitionerProfile['credential_tier']))) ?></strong>
                </span>
            <?php endif; ?>
            <?php if ($hasOversight): ?>
                <span class="badge bg-info-subtle text-info border">Oversight Enabled</span>
            <?php endif; ?>
        </div>
        <span class="small text-muted">
            <?= count($pendingForms) ?> clinical form<?= count($pendingForms) === 1 ? '' : 's' ?> awaiting co-signature
        </span>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 py-3">
        <h5 class="card-title mb-0 p-0">
            <i class="bi bi-pen me-2 text-warning"></i>Clinical Forms Pending Supervisor Co-Sign
        </h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pendingForms)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-check2-circle fs-3 d-block mb-2 text-success"></i>
                No clinical forms are currently waiting for co-signature.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Form / Instance</th>
                            <th>Client / Case</th>
                            <th>Associate Author</th>
                            <th>Assigned Supervisor</th>
                            <th>Submitted</th>
                            <th>Submission Notes</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingForms as $row): ?>
                            <?php
                            $id = (int) ($row['id'] ?? 0);
                            $clientName = trim((string) (($row['client_first_name'] ?? '') . ' ' . ($row['client_last_name'] ?? '')));
                            $authorName = trim((string) (($row['author_first_name'] ?? '') . ' ' . ($row['author_last_name'] ?? '')));
                            if ($authorName === '') {
                                $authorName = (string) ($row['author_username'] ?? 'User #' . (int) ($row['created_by_user_id'] ?? 0));
                            }
                            $supName = trim((string) (($row['supervisor_first_name'] ?? '') . ' ' . ($row['supervisor_last_name'] ?? '')));
                            if ($supName === '') {
                                $supName = (string) ($row['supervisor_username'] ?? 'User #' . (int) ($row['assigned_supervisor_user_id'] ?? 0));
                            }
                            $isMineToSign = ((int) ($row['assigned_supervisor_user_id'] ?? 0) === $actorId) && $canCoSign;
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= esc((string) ($row['form_code'] ?? $row['form_key'] ?? '')) ?></div>
                                    <div class="small text-muted"><?= esc((string) ($row['form_title'] ?? '')) ?></div>
                                    <span class="badge bg-warning text-dark mt-1"><?= esc((string) ($row['instance_number'] ?? ('#' . $id))) ?></span>
                                </td>
                                <td>
                                    <div class="fw-medium">
                                        <?= esc($clientName !== '' ? $clientName : ('Person #' . (int) ($row['person_id'] ?? 0))) ?>
                                    </div>
                                    <?php if (! empty($row['client_mis_id'])): ?>
                                        <div class="small text-muted"><?= esc((string) $row['client_mis_id']) ?></div>
                                    <?php endif; ?>
                                    <?php if (! empty($row['case_id'])): ?>
                                        <a href="<?= site_url('guidance/cases/' . (int) $row['case_id']) ?>" class="small text-decoration-none">
                                            Case #<?= (int) $row['case_id'] ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= esc($authorName) ?></td>
                                <td class="small">
                                    <?= esc($supName) ?>
                                    <?php if ($isMineToSign): ?>
                                        <span class="badge bg-primary-subtle text-primary border ms-1">Assigned to You</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted text-nowrap">
                                    <?= ! empty($row['submitted_for_cosign_at']) ? esc(date('M d, Y g:i A', strtotime((string) $row['submitted_for_cosign_at']))) : '—' ?>
                                </td>
                                <td class="small" style="max-width: 240px;">
                                    <?php if (! empty($row['submission_notes'])): ?>
                                        <span class="text-muted"><?= esc((string) $row['submission_notes']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">No notes</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="<?= site_url('guidance/clinical/forms/' . $id . '/pdf?mode=preview') ?>" target="_blank" class="btn btn-sm btn-outline-secondary me-1">
                                        <i class="bi bi-file-pdf me-1"></i>Preview
                                    </a>
                                    <a href="<?= site_url('guidance/clinical/forms/' . $id) ?>" class="btn btn-sm <?= $isMineToSign ? 'btn-warning' : 'btn-outline-primary' ?>">
                                        <i class="bi bi-<?= $isMineToSign ? 'pen' : 'eye' ?> me-1"></i><?= $isMineToSign ? 'Review & Co-Sign' : 'Inspect' ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
