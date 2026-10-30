<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link href="<?= base_url('assets/css/queue.css') ?>" rel="stylesheet">
<?= $this->endSection() ?>

<?= $this->section('page_actions') ?>
<div class="d-flex align-items-center gap-2">
  <button type="button"
          id="queue-call-next"
          class="btn btn-primary"
          <?= empty($summary['waiting']) ? 'disabled' : '' ?>
          hx-post="<?= site_url($unit . '/queue/call-next') ?>"
          hx-include="#queueDateFilter_iso"
          hx-target="#queue-board-container"
          hx-swap="innerHTML">
    <i class="bi bi-megaphone-fill me-1"></i> Call Next Patient
  </button>

  <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#walkinModal">
    <i class="bi bi-person-plus-fill me-1"></i> New Walk-in
  </button>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
  <div>
    <h2 class="h5 fw-bold mb-0 text-dark">
      <?= ($unit === 'clinic') ? 'University Clinic Queue Board' : 'Guidance & Counseling Queue Board' ?>
    </h2>
    <span class="text-muted small">
      Daily queue · Refreshes automatically
    </span>
  </div>

  <div class="d-flex flex-wrap align-items-center gap-2">
    <?= view('queue/no_show_history', ['noShows' => $summary['no_show'] ?? [], 'queueDate' => $queueDate]) ?>
    <div class="d-flex align-items-center gap-2">
    <label for="queueDateFilter" class="form-label small text-muted mb-0">Date:</label>
    <!-- Custom date picker: visible trigger + ISO hidden value.
         The HTMX trigger lives on the hidden input so the request always
         carries the ISO date under its original param name. -->
    <input type="text"
           id="queueDateFilter"
           class="form-control form-control-sm"
           style="width: 160px;"
           data-datepicker
           data-target="#queueDateFilter_iso">
    <input type="hidden"
           name="date"
           id="queueDateFilter_iso"
           value="<?= esc($queueDate) ?>"
           hx-get="<?= site_url($unit . '/queue/board') ?>"
           hx-target="#queue-board-container"
           hx-trigger="change">
    </div>
  </div>
</div>

<div id="queue-action-error" class="alert alert-warning" role="alert" hidden></div>

<!-- Queue-only invalidation refresh; 15s fallback / 60s ready reconciliation. -->
<div id="queue-board-container"
     hx-get="<?= site_url($unit . '/queue/board') ?>"
     hx-include="#queueDateFilter_iso"
     data-queue-unit="<?= esc($unit, 'attr') ?>"
     hx-trigger="queue:refresh"
     hx-swap="innerHTML">
  <?= $this->include('queue/board_partial', ['summary' => $summary, 'unit' => $unit]) ?>
</div>

<!-- Walk-In Patient Registration Modal -->
<div class="modal fade" id="walkinModal" tabindex="-1" aria-labelledby="walkinModalLabel" aria-hidden="true" x-data="walkinSearch()">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-bottom">
        <h5 class="modal-title fw-bold" id="walkinModalLabel">
          <i class="bi bi-ticket-perforated text-primary me-2"></i> Register Walk-in Patient
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" :disabled="submitting"></button>
      </div>

      <form action="<?= site_url($unit . '/queue/walkin') ?>" method="POST" x-ref="form" @submit="submitTicket($event)">
        <?= csrf_field() ?>
        <input type="hidden" name="person_id" :value="selectedPerson?.id">

        <div class="modal-body p-4" :inert="submitting || committed">
          <div class="alert alert-warning" role="alert" x-show="feedback" x-text="feedback" x-cloak></div>
          <!-- Search box -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Search Student or Employee</label>
            <div class="input-group">
              <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
              <input type="text"
                     class="form-control"
                     placeholder="Type Student ID, Employee ID, or Name..."
                     x-model="searchTerm"
                     @input.debounce.300ms="searchPatient()">
            </div>
            <div class="form-text small text-muted">Searches the local student and employee directory. No MIS connection is required.</div>
          </div>

          <!-- Loading state -->
          <div x-show="loading" class="text-center py-3 text-muted small">
            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
            Searching MIS directory...
          </div>

          <!-- Results list -->
          <div x-show="results.length > 0 && !selectedPerson" class="list-group mb-3 shadow-sm border" style="max-height: 200px; overflow-y: auto;">
            <template x-for="p in results" :key="p.id">
              <button type="button"
                      class="list-group-item list-group-item-action p-2 d-flex justify-content-between align-items-center"
                      @click="selectPerson(p)">
                <div>
                  <div class="fw-semibold" x-text="p.last_name + ', ' + p.first_name"></div>
                  <small class="text-muted" x-text="'ID: ' + p.mis_id + ' (' + p.kind.toUpperCase() + ')'"></small>
                </div>
                <span class="badge bg-primary-subtle text-primary">Select</span>
              </button>
            </template>
          </div>

          <!-- Selected Patient Card -->
          <div x-show="selectedPerson" style="display: none;" class="card border-primary-subtle bg-primary-subtle p-3 mb-3">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <span class="badge bg-primary text-white text-uppercase" x-text="selectedPerson?.kind || ''"></span>
                <h6 class="fw-bold text-dark mt-1 mb-0" x-text="selectedPerson ? (selectedPerson.last_name + ', ' + selectedPerson.first_name) : ''"></h6>
                <div class="text-muted small" x-text="selectedPerson ? ('MIS ID: ' + selectedPerson.mis_id) : ''"></div>
              </div>
              <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="changePerson()">Change</button>
            </div>
          </div>

          <!-- Priority Selection -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Triage Priority</label>
            <div class="d-flex gap-2">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="priority" id="priRoutine" value="routine" checked>
                <label class="form-check-label" for="priRoutine">Routine</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="priority" id="priUrgent" value="urgent">
                <label class="form-check-label text-warning fw-semibold" for="priUrgent">Urgent</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="priority" id="priEmergency" value="emergency">
                <label class="form-check-label text-danger fw-semibold" for="priEmergency">Emergency</label>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" :disabled="submitting">Cancel</button>
          <button type="submit" class="btn btn-primary" :disabled="!selectedPerson || submitting || committed">
            <i class="bi bi-plus-circle me-1"></i> Issue Queue Ticket
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
  document.addEventListener('htmx:responseError', function (event) {
    if (event.detail.target?.id !== 'queue-board-container') return;
    const notice = document.getElementById('queue-action-error');
    if (!notice) return;
    let message = 'Queue action failed. Reload the board before trying again.';
    try {
      const body = JSON.parse(event.detail.xhr.responseText);
      if (typeof body.error === 'string') message = body.error;
    } catch (_) {}
    notice.textContent = message;
    notice.hidden = false;
  });
  document.addEventListener('htmx:afterRequest', function (event) {
    if (event.detail.target?.id !== 'queue-board-container' || !event.detail.successful
        || event.detail.requestConfig?.verb !== 'post') return;
    const notice = document.getElementById('queue-action-error');
    if (notice) notice.hidden = true;
  });

  function walkinSearch() {
    let request = null;
    let sequence = 0;
    let events = null;
    let backgroundPending = false;

    return {
      searchTerm: '',
      results: [],
      selectedPerson: null,
      loading: false,
      submitting: false,
      committed: false,
      feedback: '',

      init() {
        events = new AbortController();
        this.$root.addEventListener('hide.bs.modal', event => {
          if (event.target === this.$root && this.submitting) event.preventDefault();
        }, { signal: events.signal });
        this.$root.addEventListener('hidden.bs.modal', event => {
          if (event.target === this.$root && !this.committed) this.discardDraft();
        }, { signal: events.signal });
        window.addEventListener('pageshow', () => { this.submitting = false; }, { signal: events.signal });
        document.addEventListener('synapse:before-navigate', event => {
          if (backgroundPending) event.preventDefault();
        }, { signal: events.signal });
        window.addEventListener('beforeunload', event => {
          if (!backgroundPending) return;
          event.preventDefault();
          event.returnValue = '';
        }, { signal: events.signal });
      },

      destroy() {
        this.cancelSearch();
        events?.abort();
      },

      cancelSearch() {
        ++sequence;
        request?.abort();
        request = null;
        this.loading = false;
      },

      discardDraft() {
        this.cancelSearch();
        this.selectedPerson = null;
        this.searchTerm = '';
        this.results = [];
        this.feedback = '';
        // Native reset restores priority and clears only this form's navigation dirty state.
        this.$refs.form.reset();
      },

      async submitTicket(event) {
        if (this.submitting || this.committed || !this.selectedPerson) {
          event.preventDefault();
          return;
        }
        if (event.defaultPrevented) return;
        // Without the shared refresh/feedback helpers, retain the native POST contract.
        if (!window.fetch || !window.SynapseMutations || !window.SynapseToast) {
          this.submitting = true;
          setTimeout(() => {
            if (event.defaultPrevented) this.submitting = false;
          }, 0);
          return;
        }
        event.preventDefault();
        const form = this.$refs.form;
        const submitter = event.submitter;
        const method = submitter?.getAttribute('formmethod') || form.method;
        const target = submitter?.getAttribute('formtarget') || form.target;
        const tell = message => {
          this.feedback = message;
          window.SynapseToast.show(message, { type: 'error', key: 'queue-walkin' });
        };
        let url;
        try {
          url = new URL(submitter?.getAttribute('formaction') || form.action, location.href);
        } catch (_) {
          tell('This action must be submitted to a valid page on this site.');
          return;
        }
        if (method.toUpperCase() !== 'POST' || (target && target !== '_self')
            || url.origin !== location.origin || url.username || url.password) {
          tell('This action must be submitted to a valid page on this site.');
          return;
        }
        const body = submitter ? new FormData(form, submitter) : new FormData(form);
        const controls = [...form.querySelectorAll('button, input, select, textarea')]
          .map(control => [control, control.disabled]);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 45000);
        this.cancelSearch();
        backgroundPending = true;
        this.submitting = true;
        this.feedback = '';
        form.setAttribute('aria-busy', 'true');
        controls.forEach(([control]) => { control.disabled = true; });
        const unlock = () => {
          backgroundPending = false;
          this.submitting = false;
          form.removeAttribute('aria-busy');
          controls.forEach(([control, disabled]) => { control.disabled = disabled; });
        };
        try {
          const response = await fetch(url.href, {
            method: 'POST', body, credentials: 'same-origin', redirect: 'error', signal: controller.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
              'X-Synapse-Feedback': 'toast', ...window.SynapseCsrf?.headers() }
          });
          window.SynapseCsrf?.fromResponse(response);
          if (!(response.headers.get('Content-Type') || '').toLowerCase().includes('application/json')) {
            throw new Error('The response could not be verified. Review the queue before retrying.');
          }
          const payload = await response.json();
          window.SynapseCsrf?.sync(payload.csrf);
          if (!response.ok || payload.success !== true) {
            throw new Error(typeof payload.message === 'string' ? payload.message : 'The ticket could not be issued.');
          }
          this.committed = true;
          unlock();
          await this.$nextTick();
          if (this.$root.classList.contains('show')) {
            await new Promise(resolve => {
              this.$root.addEventListener('hidden.bs.modal', resolve, { once: true });
              bootstrap.Modal.getOrCreateInstance(this.$root).hide();
            });
          }
          await window.SynapseMutations.refresh(location.href, payload.message, { form });
        } catch (error) {
          tell(this.committed
            ? 'Ticket issued, but refreshing failed. Do not submit again; reload to review the queue.'
            : error instanceof TypeError || error.name === 'AbortError'
              ? 'The response was interrupted; a ticket may have been issued. Review the queue before retrying.'
              : error.message);
        } finally {
          clearTimeout(timeout);
          if (!this.committed) unlock();
        }
      },

      async searchPatient() {
        this.cancelSearch();
        this.results = [];
        const term = this.searchTerm.trim();
        if (term.length < 2 || this.selectedPerson || this.submitting || this.committed || !this.$root.classList.contains('show')) return;

        const current = sequence;
        const controller = new AbortController();
        request = controller;
        this.loading = true;
        try {
          const res = await fetch(`<?= site_url('/api/patients/search?q=') ?>${encodeURIComponent(term)}`, { signal: controller.signal });
          if (controller.signal.aborted || window.SynapseCsrf?.fromResponse(res)) return;
          if (res.ok) {
            const data = await res.json();
            if (current === sequence && this.searchTerm.trim() === term) {
              this.results = Array.isArray(data.results) ? data.results : [];
            }
          }
        } catch (e) {
          if (e.name !== 'AbortError' && current === sequence) console.error(e);
        } finally {
          if (current === sequence) {
            request = null;
            this.loading = false;
          }
        }
      },

      selectPerson(p) {
        if (this.submitting || this.committed) return;
        this.cancelSearch();
        this.selectedPerson = p;
        this.results = [];
      },

      changePerson() {
        if (this.submitting || this.committed) return;
        this.cancelSearch();
        this.selectedPerson = null;
        this.results = [];
      }
    };
  }
</script>
<script src="<?= base_url('assets/js/queue-realtime.js') ?>"></script>
<?= $this->endSection() ?>
