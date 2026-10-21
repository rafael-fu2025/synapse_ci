<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="row g-4">
  <!-- Full-width: Slide List only. Add opens a modal. -->
  <div class="col-12">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <a href="<?= site_url('/admin/carousel/create') ?>"
         class="btn btn-primary btn-sm" data-app-modal-open>
        <i class="bi bi-plus-circle me-1"></i> Add Carousel Slide
      </a>
    </div>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
        <div>
          <h5 class="fw-bold mb-0 text-dark">Carousel Slides</h5>
          <span class="text-muted small">Drag rows to reorder. Inactive slides are hidden from the login screen.</span>
        </div>
        <span class="badge bg-primary-subtle text-primary"><?= count($slides) ?> total</span>
      </div>

      <div class="card-body p-0">
        <?php if (empty($slides)): ?>
          <div class="text-center py-5 text-muted">
            <i class="bi bi-images fs-1 d-block mb-2 opacity-50"></i>
            No slides configured yet. The login screen will use default imagery.
          </div>
        <?php else: ?>
          <ul class="list-group list-group-flush" id="slideList">
            <?php foreach ($slides as $slide): ?>
              <li class="list-group-item d-flex align-items-center gap-3 py-3 slide-row" data-id="<?= esc($slide['id']) ?>" draggable="true">
                <span class="drag-handle text-muted" style="cursor: grab;" title="Drag to reorder">
                  <i class="bi bi-grip-vertical fs-5"></i>
                </span>

                <div class="d-flex gap-2 flex-shrink-0">
                  <!-- Light thumb -->
                  <div class="slide-thumb" title="Light mode variant">
                    <img src="<?= base_url(esc($slide['light_image'])) ?>" alt="Light" onerror="this.src='<?= base_url('assets/img/favicon.png') ?>'">
                  </div>
                  <!-- Dark thumb -->
                  <div class="slide-thumb slide-thumb-dark" title="Dark mode variant">
                    <?php if (! empty($slide['dark_image'])): ?>
                      <img src="<?= base_url(esc($slide['dark_image'])) ?>" alt="Dark" onerror="this.src='<?= base_url('assets/img/favicon.png') ?>'">
                    <?php else: ?>
                      <span class="text-muted small d-flex align-items-center justify-content-center h-100">none</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="flex-grow-1 min-w-0">
                  <div class="fw-semibold text-dark text-truncate"><?= esc($slide['title'] ?: 'Untitled Slide') ?></div>
                  <div class="text-muted small text-truncate"><?= esc($slide['subtitle'] ?: '—') ?></div>
                  <span class="badge <?= $slide['fit_mode'] === 'cover' ? 'bg-info-subtle text-info' : 'bg-warning-subtle text-warning' ?> mt-1">
                    <?= $slide['fit_mode'] === 'cover' ? 'Full Cover' : 'Contained + Blur' ?>
                  </span>
                  <?php if ((int) $slide['is_active'] !== 1): ?>
                    <span class="badge bg-secondary-subtle text-secondary mt-1">Hidden</span>
                  <?php endif; ?>
                </div>

                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                  <a href="<?= site_url('/admin/carousel/' . $slide['id'] . '/edit') ?>"
                     class="btn btn-sm btn-outline-primary"
                     data-app-modal-open
                     title="Edit slide" aria-label="Edit slide">
                                         <span class="d-none d-xl-inline">Edit</span>
                    <i class="bi bi-pencil"></i>
                  </a>

                  <form action="<?= site_url('/admin/carousel/' . $slide['id'] . '/toggle') ?>" method="POST" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm <?= (int) $slide['is_active'] === 1 ? 'btn-outline-success' : 'btn-outline-secondary' ?>" title="<?= (int) $slide['is_active'] === 1 ? 'Hide from login' : 'Show on login' ?>">
                      <i class="bi <?= (int) $slide['is_active'] === 1 ? 'bi-eye' : 'bi-eye-slash' ?>"></i>
                    </button>
                  </form>

                  <form action="<?= site_url('/admin/carousel/' . $slide['id'] . '/delete') ?>" method="POST" class="d-inline"
                                            data-confirm="Delete this slide and its uploaded images? This cannot be undone."
                                            data-confirm-title="Delete carousel slide"
                                            data-confirm-label="Delete slide"
                                            data-confirm-tone="danger">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete slide">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<style>
  .slide-thumb {
    width: 64px;
    height: 44px;
    border-radius: 0.5rem;
    overflow: hidden;
    background: #f1f3f5;
    border: 1px solid var(--na-border, #e9ecef);
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .slide-thumb img { width: 100%; height: 100%; object-fit: cover; }
  .slide-thumb-dark { background: #2b2b2b; }
  .slide-row.dragging { opacity: 0.5; }
  .min-w-0 { min-width: 0; }
</style>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function() {
  function init() {

  // --- Drag-and-drop reordering of the slide list ---
  var list = document.getElementById('slideList');
  if (!list) return;

  var dragged = null;
  var csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  list.querySelectorAll('.slide-row').forEach(function(row) {
    row.addEventListener('dragstart', function() {
      dragged = row;
      row.classList.add('dragging');
    });
    row.addEventListener('dragend', function() {
      row.classList.remove('dragging');
      dragged = null;
      persistOrder();
    });
  });

  list.addEventListener('dragover', function(e) {
    e.preventDefault();
    if (!dragged) return;
    var after = getDragAfterElement(list, e.clientY);
    if (after == null) {
      list.appendChild(dragged);
    } else {
      list.insertBefore(dragged, after);
    }
  });

  function getDragAfterElement(container, y) {
    var rows = [].slice.call(container.querySelectorAll('.slide-row:not(.dragging)'));
    return rows.reduce(function(closest, child) {
      var box = child.getBoundingClientRect();
      var offset = y - box.top - box.height / 2;
      if (offset < 0 && offset > closest.offset) {
        return { offset: offset, element: child };
      }
      return closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
  }

  function persistOrder() {
    var ids = [].slice.call(list.querySelectorAll('.slide-row')).map(function(r) { return r.dataset.id; });
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = <?= json_encode(site_url('/admin/carousel/reorder')) ?>;

    var csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = <?= json_encode(csrf_token()) ?>;
    csrf.value = csrfToken;
    form.appendChild(csrf);

    var order = document.createElement('input');
    order.type = 'hidden';
    order.name = 'order';
    order.value = ids.join(',');
    form.appendChild(order);

    document.body.appendChild(form);
    form.submit();
  }
  }

  if (window.SynapseNavigation) {
    document.addEventListener('synapse:page-load', init, { once: true });
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
</script>
<?= $this->endSection() ?>
