<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex align-items-center gap-2">
  <button type="button"
          class="btn btn-primary"
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
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h2 class="h5 fw-bold mb-0 text-dark">
      <?= ($unit === 'clinic') ? 'University Clinic Queue Board' : 'Guidance & Counseling Queue Board' ?>
    </h2>
    <span class="text-muted small">
      Live daily queue · Auto-syncs every 15s
    </span>
  </div>

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

<div id="queue-action-error" class="alert alert-warning" role="alert" hidden></div>

<!-- Dynamic HTMX Queue Board Container (refreshes automatically every 15 seconds) -->
<div id="queue-board-container"
     hx-get="<?= site_url($unit . '/queue/board') ?>"
     hx-include="#queueDateFilter_iso"
     hx-trigger="every 15s"
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
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form action="<?= site_url($unit . '/queue/walkin') ?>" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="person_id" :value="selectedPerson?.id">

        <div class="modal-body p-4">
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
              <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="selectedPerson = null">Change</button>
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
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" :disabled="!selectedPerson">
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
    return {
      searchTerm: '',
      results: [],
      selectedPerson: null,
      loading: false,

      async searchPatient() {
        if (this.searchTerm.trim().length < 2) {
          this.results = [];
          return;
        }

        this.loading = true;
        try {
          const res = await fetch(`<?= site_url('/api/patients/search?q=') ?>${encodeURIComponent(this.searchTerm)}`);
          if (res.ok) {
            const data = await res.json();
            this.results = data.results || [];
          }
        } catch (e) {
          console.error(e);
        } finally {
          this.loading = false;
        }
      },

      selectPerson(p) {
        this.selectedPerson = p;
        this.results = [];
      }
    };
  }
</script>
<?= $this->endSection() ?>
