<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= esc($portalBackUrl ?? site_url('/portal'), 'attr') ?>" class="portal-back-btn">
  <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to My Health Portal
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$callSlips = is_array($callSlips ?? null) ? $callSlips : [];
$filter = (string) ($filter ?? 'all');
$counts = array_replace(['all' => 0, 'pending' => 0, 'acknowledged' => 0, 'history' => 0], is_array($counts ?? null) ? $counts : []);
$baseUrl = (string) ($baseUrl ?? site_url('/portal/call-slips'));
$perPage = (int) ($pagination['per_page'] ?? $filters['per_page'] ?? 20);
$perPage = in_array($perPage, [20, 25, 50, 100], true) ? $perPage : 20;

$statusMetaMap = [
    'pending'      => ['tone' => 'is-requested', 'label' => 'Awaiting acknowledgment'],
    'acknowledged' => ['tone' => 'is-queue',     'label' => 'Acknowledged'],
    'attended'     => ['tone' => 'is-completed', 'label' => 'Attended'],
    'missed'       => ['tone' => 'is-danger',    'label' => 'Missed'],
    'cancelled'    => ['tone' => 'is-inactive',  'label' => 'Cancelled'],
];
$purposeLabels = [
    'personal_counseling'    => 'Personal Counseling',
    'career_counseling'      => 'Career Counseling',
    'academic_counseling'    => 'Academic Counseling (INC or etc.)',
    'routine_interview'      => 'Interview (Initial, Routine & Exit)',
    'testing_interpretation' => 'Testing/ Test Interpretation',
    'others'                 => 'Others',
];

$tabs = [
    'all'          => 'All',
    'pending'      => 'Awaiting Action',
    'acknowledged' => 'Acknowledged',
    'history'      => 'Past / Closed',
];
?>

<div class="pa-workspace pa-callslips">
  <section class="pa-sheet" aria-labelledby="portal-callslips-heading">

    <div class="pa-band pa-cs-header-band">
      <div class="pa-cs-top">
        <div class="pa-cs-copy">
          <h2 class="pa-intro-title" id="portal-callslips-heading">Official Guidance Call Slips</h2>
          <p class="pa-intro-desc">
            Notices from the Guidance Office requesting you to report on the scheduled date and time.
          </p>
        </div>
        <div class="pa-cs-summary">
          <?php if ($counts['pending'] > 0): ?>
            <span class="pa-status is-requested"><?= (int) $counts['pending'] ?> awaiting acknowledgment</span>
          <?php else: ?>
            <span class="pa-cs-total"><?= (int) $counts['all'] ?> total</span>
          <?php endif; ?>
        </div>
      </div>

      <nav class="pa-cs-tabs" aria-label="Filter guidance call slips">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
          <?php
          $tabUrl = $baseUrl . '?' . http_build_query(['status' => $tabKey, 'per_page' => $perPage]);
          $isCurrent = $filter === $tabKey;
          ?>
          <a href="<?= esc($tabUrl, 'attr') ?>"
             class="pa-cs-tab <?= $isCurrent ? 'is-active' : '' ?>"
             <?= $isCurrent ? 'aria-current="page"' : '' ?>>
            <span><?= esc($tabLabel) ?></span>
            <span class="pa-cs-tab-count"><?= (int) ($counts[$tabKey] ?? 0) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
      <form method="get" action="<?= esc($baseUrl, 'attr') ?>" class="d-flex flex-wrap justify-content-end align-items-center gap-2 mt-3">
        <input type="hidden" name="status" value="<?= esc($filter, 'attr') ?>">
        <label for="call-slips-per-page" class="small text-muted">Call slips per page</label>
        <select name="per_page" id="call-slips-per-page" class="form-select form-select-sm w-auto">
          <?php foreach ([20, 25, 50, 100] as $size): ?>
            <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary btn-sm pa-btn" type="submit">Apply</button>
      </form>
    </div>

    <div class="pa-results-region">
      <?php if ($callSlips === []): ?>
        <div class="pa-empty">
          <i class="bi bi-ticket-perforated" aria-hidden="true"></i>
          <h3>
            <?= match ($filter) {
                'pending' => 'No call slips awaiting action',
                'acknowledged' => 'No acknowledged call slips',
                'history' => 'No past or closed call slips',
                default => 'No Guidance call slips yet',
            } ?>
          </h3>
          <p>
            <?= match ($filter) {
                'pending' => 'No call slips waiting for your acknowledgment.',
                'acknowledged' => 'No acknowledged active call slips.',
                'history' => 'No past or closed call slips recorded.',
                default => 'You have no Guidance call slips yet.',
            } ?>
          </p>
          <?php if ($filter !== 'all'): ?>
            <a href="<?= esc($baseUrl, 'attr') ?>" class="btn btn-outline-secondary btn-sm pa-btn">View all call slips</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="pa-cs-ledger">
          <?php foreach ($callSlips as $cs): ?>
            <?php
            $csId = (int) ($cs['id'] ?? 0);
            $csStatus = (string) ($cs['attendance_status'] ?? 'pending');
            $statusMeta = $statusMetaMap[$csStatus] ?? ['tone' => 'is-inactive', 'label' => ucfirst($csStatus)];
            $csSlipNumber = (string) ($cs['call_slip_number'] ?? ('#' . $csId));
            $csInstanceNum = trim((string) ($cs['form_instance_number'] ?? ''));
            $csDateRaw = (string) ($cs['call_date'] ?? '');
            $csDateFormatted = $csDateRaw !== '' && strtotime($csDateRaw) ? date('M j, Y', strtotime($csDateRaw)) : $csDateRaw;
            $csTimeRaw = (string) ($cs['call_time'] ?? '');
            $csTimeFormatted = $csTimeRaw !== '' && strtotime($csTimeRaw) ? date('g:i A', strtotime($csTimeRaw)) : $csTimeRaw;
            $csSchedule = trim($csDateFormatted . ($csTimeFormatted !== '' ? ' · ' . $csTimeFormatted : ''));
            $rawCsPurpose = (string) ($cs['purpose'] ?? 'personal_counseling');
            $csPurpose = $purposeLabels[$rawCsPurpose] ?? ucwords(str_replace('_', ' ', $rawCsPurpose));
            $csPurposeOther = trim((string) ($cs['purpose_other'] ?? ''));
            ?>
            <article class="pa-cs-row <?= $csStatus === 'pending' ? 'is-pending' : '' ?>">
              <div class="pa-cs-main">
                <div class="pa-cs-row-top">
                  <div class="pa-cs-ident">
                    <span class="pa-cs-number"><?= esc($csSlipNumber) ?></span>
                    <?php if ($csInstanceNum !== ''): ?>
                      <span class="pa-callslip-sep" aria-hidden="true">·</span>
                      <span class="pa-cs-instance"><?= esc($csInstanceNum) ?></span>
                    <?php endif; ?>
                  </div>
                  <span class="pa-status <?= esc($statusMeta['tone']) ?>"><?= esc($statusMeta['label']) ?></span>
                </div>

                <div class="pa-cs-details">
                  <?php if ($csSchedule !== ''): ?>
                    <span class="pa-cs-schedule">
                      <i class="bi bi-calendar-event" aria-hidden="true"></i>
                      <span><?= esc($csSchedule) ?></span>
                    </span>
                    <span class="pa-callslip-sep" aria-hidden="true">·</span>
                  <?php endif; ?>
                  <span class="pa-cs-purpose">
                    Purpose: <strong><?= esc($csPurpose) ?><?= $csPurposeOther !== '' ? ' (' . esc($csPurposeOther) . ')' : '' ?></strong>
                  </span>
                </div>

                <?php if (!empty($cs['acknowledged_at']) && $csStatus !== 'pending'): ?>
                  <div class="pa-cs-ack-note">
                    <i class="bi bi-check2" aria-hidden="true"></i>
                    <span>Acknowledged on <?= esc(date('M j, Y · g:i A', strtotime((string) $cs['acknowledged_at']) ?: time())) ?></span>
                  </div>
                <?php endif; ?>
              </div>

              <?php if ($csStatus === 'pending'): ?>
                <div class="pa-cs-actions">
                  <form method="post" action="<?= esc(site_url('/portal/guidance/call-slips/' . $csId . '/acknowledge'), 'attr') ?>" data-background-submit>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary btn-sm pa-btn">
                      <span>Acknowledge receipt</span>
                    </button>
                  </form>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="pa-pagination-band">
        <?= view('partials/table_pagination', [
            'pagination' => $pagination ?? [
                'page'     => 1,
                'per_page' => 20,
                'filtered' => count($callSlips),
                'total'    => (int) ($counts['all'] ?? count($callSlips)),
                'pages'    => 1,
            ],
            'filters' => $filters ?? ['status' => $filter, 'per_page' => 20],
            'rowCount' => count($callSlips),
            'baseUrl' => $baseUrl,
            'paginationPageParam' => 'page',
            'paginationLiveFilters' => false,
            'paginationNavigation' => true,
            'paginationLabel' => 'Guidance call slip pages',
            'totalLabel' => 'total call slips',
        ]) ?>
      </div>
    </div>

  </section>
</div>
<?= $this->endSection() ?>
