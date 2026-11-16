<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= esc($portalBackUrl ?? site_url('/portal')) ?>" class="btn btn-outline-secondary btn-sm">
  <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to My Health Portal
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$callSlips = is_array($callSlips ?? null) ? $callSlips : [];
$filter = (string) ($filter ?? 'all');
$counts = array_replace(['all' => 0, 'pending' => 0, 'acknowledged' => 0, 'history' => 0], is_array($counts ?? null) ? $counts : []);
$baseUrl = (string) ($baseUrl ?? site_url('/portal/call-slips'));

$statusBadgeMap = [
    'pending'      => ['class' => 'text-bg-warning', 'label' => 'Awaiting Acknowledgment'],
    'acknowledged' => ['class' => 'text-bg-info', 'label' => 'Acknowledged'],
    'attended'     => ['class' => 'text-bg-success', 'label' => 'Attended'],
    'missed'       => ['class' => 'text-bg-danger', 'label' => 'Missed'],
    'cancelled'    => ['class' => 'text-bg-secondary', 'label' => 'Cancelled'],
];

$tabs = [
    'all'          => 'All',
    'pending'      => 'Awaiting Action',
    'acknowledged' => 'Acknowledged',
    'history'      => 'Past / Closed',
];
?>

<style>
  .portal-callslips-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
  }
  .portal-callslip-row-card {
    border: 1px solid var(--na-border, rgba(0, 0, 0, 0.08));
    border-radius: 10px;
    padding: 0.9rem 1rem;
  }
  [data-theme="dark"] .portal-callslip-row-card {
    border-color: rgba(255, 255, 255, 0.12);
    background: var(--na-surface-2, #222222);
  }
  @media (max-width: 575.98px) {
    .portal-callslips-tabs {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.4rem;
    }
    .portal-callslips-tabs .btn {
      width: 100%;
      display: inline-flex;
      justify-content: space-between;
      align-items: center;
      padding-inline: 0.65rem;
    }
    .portal-callslip-row-card {
      padding: 0.85rem;
    }
    .portal-callslip-row-actions,
    .portal-callslip-row-actions form,
    .portal-callslip-row-actions button {
      width: 100%;
    }
  }
</style>

<section class="card border-0 shadow-sm" aria-labelledby="portal-callslips-heading">
  <div class="card-header bg-transparent py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h5 class="fw-bold mb-0" id="portal-callslips-heading">
        <i class="bi bi-ticket-perforated-fill text-warning me-2" aria-hidden="true"></i>Guidance Call Slips
      </h5>
      <p class="small text-muted mb-0 mt-1">
        Notices from the Guidance Office requesting you to report on the scheduled date and time.
      </p>
    </div>
    <?php if ($counts['pending'] > 0): ?>
      <span class="badge text-bg-warning"><?= (int) $counts['pending'] ?> awaiting acknowledgment</span>
    <?php else: ?>
      <span class="badge text-bg-secondary"><?= (int) $counts['all'] ?> total</span>
    <?php endif; ?>
  </div>

  <div class="card-body p-3">
    <nav class="portal-callslips-tabs mb-3" aria-label="Filter guidance call slips">
      <?php foreach ($tabs as $tabKey => $tabLabel): ?>
        <?php
        $tabUrl = $tabKey === 'all' ? $baseUrl : ($baseUrl . '?status=' . urlencode($tabKey));
        $isCurrent = $filter === $tabKey;
        ?>
        <a href="<?= esc($tabUrl) ?>"
           class="btn btn-sm <?= $isCurrent ? 'btn-primary' : 'btn-outline-secondary' ?>"
           <?= $isCurrent ? 'aria-current="page"' : '' ?>>
          <span><?= esc($tabLabel) ?></span>
          <span class="badge <?= $isCurrent ? 'bg-light text-dark' : 'bg-secondary-subtle text-body-secondary' ?> ms-1">
            <?= (int) ($counts[$tabKey] ?? 0) ?>
          </span>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if ($callSlips === []): ?>
      <div class="text-center py-5 text-muted">
        <i class="bi bi-ticket-perforated fs-1 d-block mb-2 opacity-50" aria-hidden="true"></i>
        <p class="mb-2 fw-medium">
          <?= match ($filter) {
              'pending' => 'No call slips waiting for your acknowledgment.',
              'acknowledged' => 'No acknowledged active call slips.',
              'history' => 'No past or closed call slips recorded.',
              default => 'You have no Guidance call slips yet.',
          } ?>
        </p>
        <?php if ($filter !== 'all'): ?>
          <a href="<?= esc($baseUrl) ?>" class="btn btn-outline-primary btn-sm">View all call slips</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="d-grid gap-2">
        <?php foreach ($callSlips as $cs): ?>
          <?php
          $csId = (int) ($cs['id'] ?? 0);
          $csStatus = (string) ($cs['attendance_status'] ?? 'pending');
          $badgeMeta = $statusBadgeMap[$csStatus] ?? ['class' => 'text-bg-secondary', 'label' => ucfirst($csStatus)];
          $csSlipNumber = (string) ($cs['call_slip_number'] ?? ('#' . $csId));
          $csDateRaw = (string) ($cs['call_date'] ?? '');
          $csDateFormatted = $csDateRaw !== '' && strtotime($csDateRaw) ? date('M d, Y', strtotime($csDateRaw)) : $csDateRaw;
          $csTimeRaw = (string) ($cs['call_time'] ?? '');
          $csTimeFormatted = $csTimeRaw !== '' && strtotime($csTimeRaw) ? date('g:i A', strtotime($csTimeRaw)) : $csTimeRaw;
          $csSchedule = trim($csDateFormatted . ($csTimeFormatted !== '' ? ' · ' . $csTimeFormatted : ''));
          $csPurpose = ucwords(str_replace('_', ' ', (string) ($cs['purpose'] ?? 'counseling')));
          $csPurposeOther = trim((string) ($cs['purpose_other'] ?? ''));
          ?>
          <article class="portal-callslip-row-card d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
            <div>
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span class="fw-semibold"><?= esc($csSlipNumber) ?></span>
                <span class="badge <?= esc($badgeMeta['class']) ?>"><?= esc($badgeMeta['label']) ?></span>
              </div>
              <div class="small text-muted d-flex flex-wrap align-items-center gap-2">
                <?php if ($csSchedule !== ''): ?>
                  <span><i class="bi bi-calendar-event me-1" aria-hidden="true"></i><?= esc($csSchedule) ?></span>
                <?php endif; ?>
                <span><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i><?= esc($csPurpose) ?><?= $csPurposeOther !== '' ? ' (' . esc($csPurposeOther) . ')' : '' ?></span>
              </div>
              <?php if (!empty($cs['acknowledged_at']) && $csStatus !== 'pending'): ?>
                <div class="small text-muted mt-1">
                  Acknowledged on <?= esc(date('M d, Y g:i A', strtotime((string) $cs['acknowledged_at']) ?: time())) ?>
                </div>
              <?php endif; ?>
            </div>

            <?php if ($csStatus === 'pending'): ?>
              <div class="portal-callslip-row-actions flex-shrink-0">
                <form method="post" action="<?= esc(site_url('/portal/guidance/call-slips/' . $csId . '/acknowledge'), 'attr') ?>" data-background-submit>
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Acknowledge receipt
                  </button>
                </form>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?= $this->endSection() ?>
