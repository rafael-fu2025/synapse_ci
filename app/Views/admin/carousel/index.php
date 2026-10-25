<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="row g-4 carousel-admin">

  <!-- Full-width: Slide List only. Add opens a modal. -->
  <div class="col-12">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <a href="<?= site_url('/admin/carousel/create') ?>"
         class="btn btn-primary btn-sm" data-app-modal-open>
        <i class="bi bi-plus-circle me-1"></i> Add Carousel Slide
      </a>
    </div>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent d-flex justify-content-between align-items-center gap-2 py-3">
        <div>
          <h5 class="fw-bold mb-0 text-dark">Carousel Slides</h5>
          <span class="text-muted small">Drag rows or use Move up / Move down to reorder. Inactive slides are hidden from the login screen.</span>

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
            <?php foreach ($slides as $position => $slide): ?>
              <?php $slideTitle = $slide['title'] ?: 'Untitled Slide'; ?>
              <li class="list-group-item d-flex align-items-center gap-3 py-3 slide-row" data-id="<?= esc($slide['id']) ?>" draggable="true">
                <span class="drag-handle text-muted" style="cursor: grab;" title="Drag to reorder">
                  <i class="bi bi-grip-vertical fs-5"></i>
                </span>

                <div class="d-flex gap-2 flex-shrink-0 slide-thumbnails">
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

                <div class="flex-grow-1 slide-copy">
                  <div class="fw-semibold text-dark slide-title"><?= esc($slideTitle) ?></div>
                  <div class="text-muted small slide-subtitle"><?= esc($slide['subtitle'] ?: '—') ?></div>
                  <span class="badge <?= $slide['fit_mode'] === 'cover' ? 'bg-info-subtle text-info' : 'bg-warning-subtle text-warning' ?> mt-1">
                    <?= $slide['fit_mode'] === 'cover' ? 'Full Cover' : 'Contained + Blur' ?>
                  </span>
                  <?php if ((int) $slide['is_active'] !== 1): ?>
                    <span class="badge bg-secondary-subtle text-secondary mt-1">Hidden</span>
                  <?php endif; ?>
                </div>

                <div class="d-flex align-items-center gap-2 flex-shrink-0 slide-actions">
                  <div class="d-flex gap-1 slide-reorder" role="group" aria-label="Reorder <?= esc($slideTitle, 'attr') ?>">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-slide-move="up"
                            aria-label="Move up: <?= esc($slideTitle, 'attr') ?>" title="Move up" <?= $position === 0 ? 'disabled' : '' ?>>
                      <i class="bi bi-arrow-up" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-slide-move="down"
                            aria-label="Move down: <?= esc($slideTitle, 'attr') ?>" title="Move down" <?= $position === count($slides) - 1 ? 'disabled' : '' ?>>
                      <i class="bi bi-arrow-down" aria-hidden="true"></i>
                    </button>
                  </div>
                  <a href="<?= site_url('/admin/carousel/' . $slide['id'] . '/edit') ?>"
                     class="btn btn-sm btn-outline-primary"
                     data-app-modal-open
                     title="Edit slide" aria-label="Edit slide: <?= esc($slideTitle, 'attr') ?>">
                                         <span class="d-none d-xl-inline">Edit</span>
                    <i class="bi bi-pencil"></i>
                  </a>

                  <form action="<?= site_url('/admin/carousel/' . $slide['id'] . '/toggle') ?>" method="POST" class="d-inline" data-background-submit>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm <?= (int) $slide['is_active'] === 1 ? 'btn-outline-success' : 'btn-outline-secondary' ?>" title="<?= (int) $slide['is_active'] === 1 ? 'Hide from login' : 'Show on login' ?>"
                            aria-label="<?= (int) $slide['is_active'] === 1 ? 'Hide from login' : 'Show on login' ?>: <?= esc($slideTitle, 'attr') ?>">
                      <i class="bi <?= (int) $slide['is_active'] === 1 ? 'bi-eye' : 'bi-eye-slash' ?>"></i>
                    </button>
                  </form>

                  <form action="<?= site_url('/admin/carousel/' . $slide['id'] . '/delete') ?>" method="POST" class="d-inline" data-background-submit
                                            data-confirm="Delete this slide and its uploaded images? This cannot be undone."
                                            data-confirm-title="Delete carousel slide"
                                            data-confirm-label="Delete slide"
                                            data-confirm-tone="danger">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete slide" aria-label="Delete slide: <?= esc($slideTitle, 'attr') ?>">
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

  .carousel-admin .slide-thumb {
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
  .carousel-admin .slide-thumb img { width: 100%; height: 100%; object-fit: cover; }
  .carousel-admin .slide-thumb-dark { background: #2b2b2b; }
  .carousel-admin .slide-row.dragging { opacity: 0.5; }
  .carousel-admin .slide-copy { min-width: 0; overflow-wrap: anywhere; }
  .carousel-admin .slide-actions .btn { min-width: 44px; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; }
  @media (max-width: 767.98px) {
    .carousel-admin .slide-row {
      display: grid !important;
      grid-template-columns: 24px minmax(0, 1fr);
      gap: 12px !important;
    }
    .carousel-admin .slide-copy,
    .carousel-admin .slide-actions { grid-column: 1 / -1; }
    .carousel-admin .slide-actions { flex-wrap: wrap; }
    .carousel-admin .card-header > div { min-width: 0; }
    .carousel-admin .card-header > .badge { flex-shrink: 0; }
  }
</style>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function() {
  function init() {

  // --- Drag-and-drop reordering of the slide list ---
  var list = document.getElementById('slideList');
  if (!list || list.dataset.reorderReady) return;
  list.dataset.reorderReady = 'true';

  var dragged = null;
  var submitting = false;
  var savedOrder = currentOrder();
  var feedback = null;

  function announce(message, state) {
    feedback = window.SynapseToast.show(message, {
      type: state === 'muted' ? 'info' : state === 'danger' ? 'error' : state,
      key: 'carousel-reorder',
      duration: state === 'success' ? 5000 : 0
    });
  }

  function guardNavigation(event) {
    if (submitting) event.preventDefault();
  }
  function guardUnload(event) {
    if (!submitting) return;
    event.preventDefault();
    event.returnValue = '';
  }
  document.addEventListener('synapse:before-navigate', guardNavigation);
  window.addEventListener('beforeunload', guardUnload);
  window.SynapseNavigation?.registerCleanup(function() {
    document.removeEventListener('synapse:before-navigate', guardNavigation);
    window.removeEventListener('beforeunload', guardUnload);
    feedback?.dismiss();
  });

  function currentOrder() {
    return [].slice.call(list.querySelectorAll('.slide-row')).map(function(row) { return row.dataset.id; }).join(',');
  }

  function updateControls() {
    var rows = list.querySelectorAll('.slide-row');
    rows.forEach(function(row, index) {
      row.draggable = !submitting;
      row.querySelectorAll('.slide-actions button').forEach(function(button) { button.disabled = submitting; });
      row.querySelectorAll('.slide-actions a').forEach(function(link) {
        if (submitting) link.setAttribute('aria-disabled', 'true');
        else link.removeAttribute('aria-disabled');
      });
      row.querySelector('[data-slide-move="up"]').disabled = submitting || index === 0;
      row.querySelector('[data-slide-move="down"]').disabled = submitting || index === rows.length - 1;
    });
  }

  list.addEventListener('click', function(event) {
    if (submitting && event.target.closest('button, a')) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    var button = event.target.closest('[data-slide-move]');
    if (!button || button.disabled || submitting || dragged) return;
    var row = button.closest('.slide-row');
    var sibling = button.dataset.slideMove === 'up' ? row.previousElementSibling : row.nextElementSibling;
    if (!sibling) return;
    if (button.dataset.slideMove === 'up') list.insertBefore(row, sibling);
    else list.insertBefore(sibling, row);
    persistOrder();
  }, true);
  updateControls();

  list.querySelectorAll('.slide-row').forEach(function(row) {
    row.addEventListener('dragstart', function(event) {
      if (submitting || event.target.closest('button, a, form')) {
        event.preventDefault();
        return;
      }
      dragged = row;
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.dataset.id);
      }
      row.classList.add('dragging');
    });
    row.addEventListener('dragend', function() {
      if (dragged !== row) return;
      row.classList.remove('dragging');
      dragged = null;
      persistOrder();
    });
  });

  list.addEventListener('dragover', function(e) {
    e.preventDefault();
    if (!dragged || submitting) return;
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

  async function persistOrder() {
    var orderValue = currentOrder();
    if (submitting || orderValue === savedOrder) return;
    submitting = true;
    var focused = list.contains(document.activeElement) ? document.activeElement : null;
    list.setAttribute('aria-busy', 'true');
    updateControls();
    if (!window.fetch) {
      postOrderNormally(orderValue);
      return;
    }
    announce('Saving slide order…', 'muted');
    var body = new URLSearchParams();
    body.set(document.querySelector('meta[name="csrf-name"]')?.content || <?= json_encode(csrf_token()) ?>,
      document.querySelector('meta[name="csrf-token"]')?.content || <?= json_encode(csrf_hash()) ?>);
    body.set('order', orderValue);
    var controller = new AbortController();
    var timeout = setTimeout(function() { controller.abort(); }, 15000);
    try {
      var response = await fetch(<?= json_encode(site_url('/admin/carousel/reorder')) ?>, {
        method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      });
      window.SynapseCsrf?.fromResponse(response);
      if (response.redirected || !(response.headers.get('Content-Type') || '').includes('application/json')) {
        throw new Error('Unexpected save response. Reload to check your session and slide order.');
      }
      var data = await response.json();
      window.SynapseCsrf?.sync(data.csrf);
      if (!response.ok || data.success !== true) {
        throw new Error(typeof data.message === 'string' ? data.message : 'The order could not be saved.');
      }
      savedOrder = orderValue;
      announce(data.message || 'Slide order updated.', 'success');
    } catch (error) {
      var rows = new Map([].slice.call(list.querySelectorAll('.slide-row')).map(function(row) { return [row.dataset.id, row]; }));
      savedOrder.split(',').forEach(function(id) { if (rows.has(id)) list.appendChild(rows.get(id)); });
      announce('Could not confirm the update. ' + (error.name === 'AbortError' ? 'The request timed out.' : error.message)
        + ' Display restored to the last confirmed order. Try reordering again or reload to verify.', 'danger');
    } finally {
      clearTimeout(timeout);
      submitting = false;
      list.removeAttribute('aria-busy');
      updateControls();
      if (focused?.isConnected && (document.activeElement === document.body || list.contains(document.activeElement))) {
        var target = focused.disabled ? focused.closest('.slide-row')?.querySelector('[data-slide-move]:not(:disabled)') : focused;
        target?.focus({ preventScroll: true });
      }
    }
  }

  function postOrderNormally(orderValue) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = <?= json_encode(site_url('/admin/carousel/reorder')) ?>;

    var csrf = document.createElement('input');
    csrf.type = 'hidden';
    // Modal saves and navigation can rotate the token after this page initializes.
    csrf.name = document.querySelector('meta[name="csrf-name"]')?.content || <?= json_encode(csrf_token()) ?>;
    csrf.value = document.querySelector('meta[name="csrf-token"]')?.content || <?= json_encode(csrf_hash()) ?>;
    form.appendChild(csrf);

    var order = document.createElement('input');
    order.type = 'hidden';
    order.name = 'order';
    order.value = orderValue;
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
