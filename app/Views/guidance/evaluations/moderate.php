<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <a href="<?= esc(site_url('/guidance/evaluations/dashboard'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>Evaluation Aggregates
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$pendingComments = $pendingComments ?? [];
$visitTypeLabels = \App\Controllers\GuidanceEvaluationController::VISIT_TYPE_LABELS;
$typeLabels = [
    \App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL => 'Psychosocial Support',
    \App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_COUNSELING   => 'Counseling Service',
];
$totalPending = count($pendingComments);
$psychCount = 0;
$counsCount = 0;
foreach ($pendingComments as $item) {
    if (($item['evaluation_type'] ?? '') === \App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_COUNSELING) {
        ++$counsCount;
    } else {
        ++$psychCount;
    }
}
?>
<section class="guidance-ui ce-workspace gse-workspace gse-mod-workspace"
         data-gse-mod-workspace
         aria-label="Qualitative comment moderation workspace">

  <!-- Context & RA 10173 Privacy Header -->
  <section class="ce-card gse-context" aria-label="Moderation queue summary and privacy notice">
    <div class="ce-card-body gse-context-body">
      <div class="gse-provider">
        <span class="gse-section-label">RA 10173 Comment Moderation</span>
        <h2>Unlinked Qualitative Comment Queue</h2>
        <p class="gse-caption">Comments are decrypted strictly for moderation to remove accidental PII or abusive language before cohort-safe release. No student, episode, or session identifiers exist in this dataset.</p>
      </div>
      <div class="gse-mod-status-col">
        <span class="ce-tag">
          <span class="ce-status-dot <?= $totalPending > 0 ? 'ce-status-amber' : 'ce-status-emerald' ?>" data-gse-queue-status>
            <?= $totalPending > 0 ? 'Pending Review' : 'Queue Clear' ?>
          </span>
        </span>
        <span class="ce-tag">
          <span class="gse-number" data-gse-pending-count><?= $totalPending ?></span>
          <span data-gse-pending-label><?= $totalPending === 1 ? 'pending comment' : 'pending comments' ?></span>
        </span>
      </div>
    </div>

    <div class="gse-mod-filters<?= $totalPending === 0 ? ' d-none' : '' ?>" role="toolbar" aria-label="Filter moderation queue by service" data-gse-mod-filters>
      <button type="button" class="gse-mod-filter-btn is-active" data-gse-filter="all" aria-pressed="true">
        <span>All Pending</span>
        <span class="gse-mod-filter-count gse-number" data-gse-filter-count="all"><?= $totalPending ?></span>
      </button>
      <button type="button" class="gse-mod-filter-btn" data-gse-filter="<?= esc(\App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL, 'attr') ?>" aria-pressed="false">
        <span>Psychosocial Support</span>
        <span class="gse-mod-filter-count gse-number" data-gse-filter-count="<?= esc(\App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL, 'attr') ?>"><?= $psychCount ?></span>
      </button>
      <button type="button" class="gse-mod-filter-btn" data-gse-filter="<?= esc(\App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_COUNSELING, 'attr') ?>" aria-pressed="false">
        <span>Counseling Service</span>
        <span class="gse-mod-filter-count gse-number" data-gse-filter-count="<?= esc(\App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_COUNSELING, 'attr') ?>"><?= $counsCount ?></span>
      </button>
    </div>
  </section>

  <!-- Pending Comment Cards -->
  <div class="gse-mod-list<?= $totalPending === 0 ? ' d-none' : '' ?>" data-gse-mod-list>
    <?php foreach ($pendingComments as $item): ?>
      <?php
        $evalId = (int) ($item['id'] ?? 0);
        $evalType = (string) ($item['evaluation_type'] ?? \App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL);
        $serviceName = $typeLabels[$evalType] ?? 'Guidance Service';
        $visitKey = (string) ($item['visit_type'] ?? '');
        $visitLabel = $visitTypeLabels[$visitKey] ?? '';
        $period = trim((string) (($item['academic_term'] ?? '') . ' ' . ($item['academic_year'] ?? '')));
        $submittedRaw = (string) ($item['submitted_date'] ?? '');
        $submittedFormatted = $submittedRaw !== '' && strtotime($submittedRaw) !== false
            ? date('M j, Y', strtotime($submittedRaw))
            : $submittedRaw;
      ?>
      <article class="ce-card gse-mod-card"
               data-gse-mod-card="<?= $evalId ?>"
               data-eval-type="<?= esc($evalType, 'attr') ?>">
        <header class="ce-card-header gse-mod-card-head">
          <div class="gse-mod-meta-left">
            <span class="ce-status-dot ce-status-amber">Pending Review</span>
            <span class="ce-tag"><?= esc($serviceName) ?></span>
            <span class="ce-tag ce-mono"><?= esc((string) ($item['form_code'] ?? '')) ?></span>
            <?php if ($visitLabel !== ''): ?>
              <span class="ce-tag"><?= esc($visitLabel) ?></span>
            <?php endif; ?>
            <?php if ($period !== ''): ?>
              <span class="ce-tag"><?= esc($period) ?></span>
            <?php endif; ?>
          </div>
          <div class="gse-mod-meta-right">
            <?php if ($submittedFormatted !== ''): ?>
              <span class="gse-caption">Submitted <?= esc($submittedFormatted) ?></span>
            <?php endif; ?>
            <span class="ce-tag ce-mono">Evaluation #<?= $evalId ?></span>
          </div>
        </header>

        <div class="ce-card-body gse-mod-card-body">
          <blockquote class="gse-mod-quote">
            <i class="bi bi-chat-quote" aria-hidden="true"></i>
            <p><?= nl2br(esc((string) ($item['comment_text'] ?? ''))) ?></p>
          </blockquote>
          <div class="alert alert-danger py-2 px-3 small mt-3 mb-0 d-none" role="alert" data-gse-mod-error></div>
        </div>

        <footer class="gse-mod-card-foot">
          <span class="gse-mod-foot-note">
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
            <span>Approved comments appear in aggregates only once the provider reaches 5+ responses.</span>
          </span>
          <div class="gse-mod-actions">
            <form method="POST"
                  action="<?= esc(site_url('/guidance/evaluations/moderate/' . $evalId), 'attr') ?>"
                  data-gse-mod-form
                  data-navigation-dirty="false">
              <?= csrf_field() ?>
              <input type="hidden" name="status" value="redacted">
              <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-eye-slash" aria-hidden="true"></i>Redact &amp; Exclude
              </button>
            </form>
            <form method="POST"
                  action="<?= esc(site_url('/guidance/evaluations/moderate/' . $evalId), 'attr') ?>"
                  data-gse-mod-form
                  data-navigation-dirty="false">
              <?= csrf_field() ?>
              <input type="hidden" name="status" value="approved">
              <button type="submit" class="btn btn-success btn-sm">
                <i class="bi bi-check-lg" aria-hidden="true"></i>Approve for Aggregate
              </button>
            </form>
          </div>
        </footer>
      </article>
    <?php endforeach; ?>
  </div>

  <!-- Filter-specific empty notice (shown when filtered service has 0 remaining items) -->
  <div class="ce-card gse-mod-empty d-none" id="gse-mod-filter-empty" role="status">
    <div class="ce-card-body gse-mod-empty-body">
      <i class="bi bi-funnel" aria-hidden="true"></i>
      <h3>No Pending Comments for This Service</h3>
      <p class="gse-caption">Switch back to <strong>All Pending</strong> to review remaining comments from other guidance services.</p>
      <button type="button" class="btn btn-outline-secondary btn-sm mt-3" data-gse-filter-reset>Show All Pending</button>
    </div>
  </div>

  <!-- Queue Clear Empty State (always in DOM so last card removal reveals it seamlessly) -->
  <div class="ce-card gse-mod-empty<?= $totalPending === 0 ? '' : ' d-none' ?>" id="gse-mod-empty" role="status">
    <div class="ce-card-body gse-mod-empty-body">
      <i class="bi bi-check2-all" aria-hidden="true"></i>
      <h3>Moderation Queue Clear</h3>
      <p class="gse-caption">There are no pending qualitative comments waiting for review.</p>
      <a href="<?= esc(site_url('/guidance/evaluations/dashboard'), 'attr') ?>" class="btn btn-outline-secondary btn-sm mt-3">
        <i class="bi bi-bar-chart-line" aria-hidden="true"></i>View Evaluation Aggregates
      </a>
    </div>
  </div>

</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(() => {
  'use strict';
  const root = document.querySelector('[data-gse-mod-workspace]');
  if (!root || root.dataset.gseModBound === '1') return;
  root.dataset.gseModBound = '1';

  const listWrap = root.querySelector('[data-gse-mod-list]');
  const filtersBar = root.querySelector('[data-gse-mod-filters]');
  const emptyQueue = document.getElementById('gse-mod-empty');
  const filterEmpty = document.getElementById('gse-mod-filter-empty');
  const pendingCountEl = root.querySelector('[data-gse-pending-count]');
  const pendingLabelEl = root.querySelector('[data-gse-pending-label]');
  const queueStatusEl = root.querySelector('[data-gse-queue-status]');

  let activeFilter = 'all';

  function syncCsrf(csrf) {
    if (!csrf || !csrf.name || !csrf.hash) return;
    root.querySelectorAll(`input[name="${csrf.name}"]`).forEach(input => {
      input.value = csrf.hash;
    });
    window.SynapseCsrf?.sync?.(csrf);
  }

  function refreshCountsAndFilter() {
    const cards = [...root.querySelectorAll('[data-gse-mod-card]')];
    const total = cards.length;
    const byType = new Map();
    let visibleCount = 0;

    for (const card of cards) {
      const type = card.dataset.evalType || '';
      byType.set(type, (byType.get(type) || 0) + 1);
      const matches = activeFilter === 'all' || type === activeFilter;
      card.classList.toggle('d-none', !matches);
      if (matches) ++visibleCount;
    }

    if (pendingCountEl) pendingCountEl.textContent = String(total);
    if (pendingLabelEl) pendingLabelEl.textContent = total === 1 ? 'pending comment' : 'pending comments';
    if (queueStatusEl) {
      queueStatusEl.textContent = total > 0 ? 'Pending Review' : 'Queue Clear';
      queueStatusEl.classList.toggle('ce-status-amber', total > 0);
      queueStatusEl.classList.toggle('ce-status-emerald', total === 0);
    }

    root.querySelectorAll('[data-gse-filter-count]').forEach(el => {
      const key = el.dataset.gseFilterCount;
      el.textContent = String(key === 'all' ? total : (byType.get(key) || 0));
    });

    filtersBar?.classList.toggle('d-none', total === 0);
    listWrap?.classList.toggle('d-none', total === 0 || visibleCount === 0);
    filterEmpty?.classList.toggle('d-none', total === 0 || visibleCount > 0);
    emptyQueue?.classList.toggle('d-none', total > 0);
  }

  function setFilter(next) {
    activeFilter = next || 'all';
    root.querySelectorAll('[data-gse-filter]').forEach(btn => {
      const isAct = btn.dataset.gseFilter === activeFilter;
      btn.classList.toggle('is-active', isAct);
      btn.setAttribute('aria-pressed', String(isAct));
    });
    refreshCountsAndFilter();
  }

  root.querySelectorAll('[data-gse-filter]').forEach(btn => {
    btn.addEventListener('click', () => setFilter(btn.dataset.gseFilter));
  });
  root.querySelector('[data-gse-filter-reset]')?.addEventListener('click', () => setFilter('all'));

  root.addEventListener('submit', async event => {
    const form = event.target.closest('[data-gse-mod-form]');
    if (!form || !root.contains(form)) return;
    event.preventDefault();

    const card = form.closest('[data-gse-mod-card]');
    if (!card || card.dataset.busy === '1') return;

    const errBox = card.querySelector('[data-gse-mod-error]');
    if (errBox) {
      errBox.textContent = '';
      errBox.classList.add('d-none');
    }

    card.dataset.busy = '1';
    const buttons = [...card.querySelectorAll('button[type="submit"]')];
    buttons.forEach(b => { b.disabled = true; });

    try {
      const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(window.SynapseCsrf?.headers?.() || {}),
      };
      const res = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: new FormData(form),
      });
      const data = await res.json();
      syncCsrf(data.csrf);

      if (!res.ok || !data.success) {
        if (errBox) {
          errBox.textContent = data.message || 'Unable to update moderation status.';
          errBox.classList.remove('d-none');
        }
        card.dataset.busy = '0';
        buttons.forEach(b => { b.disabled = false; });
        return;
      }

      const statusVal = form.querySelector('input[name="status"]')?.value;
      window.SynapseToast?.show?.(
        data.message || 'Moderation decision saved.',
        { type: statusVal === 'approved' ? 'success' : 'info' }
      );

      card.classList.add('is-removing');
      window.setTimeout(() => {
        card.remove();
        refreshCountsAndFilter();
      }, 170);
    } catch (_) {
      if (errBox) {
        errBox.textContent = 'Network error while saving moderation decision. Please try again.';
        errBox.classList.remove('d-none');
      }
      card.dataset.busy = '0';
      buttons.forEach(b => { b.disabled = false; });
    }
  });
})();
</script>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-service-evaluations.css'), 'attr') ?>">
<?= $this->endSection() ?>
