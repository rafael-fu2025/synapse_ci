<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= site_url('/admin/carousel/create') ?>"
   class="btn btn-primary btn-sm" data-app-modal-open>
  <i class="bi bi-plus-circle me-1"></i> Add Carousel Slide
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<section data-refresh-owner="carousel.admin" data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
<div data-refresh-region="carousel.slides">
<div class="row g-4 carousel-admin">

  <!-- Full-width: Slide List only. Add opens a modal. -->
  <div class="col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent d-flex justify-content-between align-items-center gap-2 py-3">
        <div>
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
              <?php $isProtected = $protectedSlides[(int) $slide['id']] ?? false; ?>
              <li class="list-group-item d-flex align-items-center gap-3 py-3 slide-row<?= (int) $slide['is_active'] === 1 ? '' : ' slide-row-inactive' ?>" data-id="<?= esc($slide['id']) ?>" draggable="true">
                <span class="drag-handle text-muted" style="cursor: grab;" data-bs-toggle="tooltip" data-bs-title="Drag to reorder">
                  <i class="bi bi-grip-vertical fs-5"></i>
                </span>

                <?php $hasDarkVariant = ! empty($slide['dark_image']); ?>
                <div class="d-flex flex-shrink-0 slide-thumbnails<?= $hasDarkVariant ? '' : ' slide-thumbnails-single' ?>">
                  <!-- Light thumb: front card in light mode, peeks out behind in dark mode -->
                  <div class="slide-thumb slide-thumb-light" data-bs-toggle="tooltip" data-bs-title="Light mode variant">
                    <img src="<?= base_url(esc($slide['light_image'])) ?>" alt="Light">
                  </div>
                  <!-- Dark thumb: front card in dark mode, peeks out behind in light mode -->
                  <?php if ($hasDarkVariant): ?>
                    <div class="slide-thumb slide-thumb-dark" data-bs-toggle="tooltip" data-bs-title="Dark mode variant">
                      <img src="<?= base_url(esc($slide['dark_image'])) ?>" alt="Dark">
                    </div>
                  <?php endif; ?>
                </div>

                <div class="flex-grow-1 slide-copy">
                  <div class="fw-semibold text-dark slide-title"><?= esc($slideTitle) ?></div>
                  <div class="text-muted small slide-subtitle"><?= esc($slide['subtitle'] ?: '—') ?></div>
                  <span class="badge <?= $slide['fit_mode'] === 'cover' ? 'bg-info-subtle text-info' : 'bg-warning-subtle text-warning' ?> mt-1">
                    <?= $slide['fit_mode'] === 'cover' ? 'Full Cover' : 'Contained + Blur' ?>
                  </span>
                  <?php if ($isProtected): ?>
                    <span class="badge bg-dark-subtle text-dark mt-1">Default image &middot; protected</span>
                  <?php endif; ?>
                  <?php if ((int) $slide['is_active'] !== 1): ?>
                    <span class="badge bg-secondary-subtle text-secondary mt-1">Hidden</span>
                  <?php endif; ?>
                </div>

                <div class="d-flex align-items-center gap-1 flex-shrink-0 slide-actions">
                  <div class="d-flex gap-1 slide-reorder" role="group" aria-label="Reorder <?= esc($slideTitle, 'attr') ?>">
                    <button type="button" class="slide-btn" data-slide-move="up"
                            aria-label="Move up: <?= esc($slideTitle, 'attr') ?>" data-bs-toggle="tooltip" data-bs-title="Move up" <?= $position === 0 ? 'disabled' : '' ?>>
                      <i class="bi bi-arrow-up" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="slide-btn" data-slide-move="down"
                            aria-label="Move down: <?= esc($slideTitle, 'attr') ?>" data-bs-toggle="tooltip" data-bs-title="Move down" <?= $position === count($slides) - 1 ? 'disabled' : '' ?>>
                      <i class="bi bi-arrow-down" aria-hidden="true"></i>
                    </button>
                  </div>
                  <?php if ($isProtected): ?>
                    <span class="slide-btn slide-btn-primary" aria-disabled="true"
                          data-bs-toggle="tooltip" data-bs-title="The permanent default image cannot be edited">
                      <i class="bi bi-lock" aria-hidden="true"></i>
                    </span>
                  <?php else: ?>
                    <a href="<?= site_url('/admin/carousel/' . $slide['id'] . '/edit') ?>"
                       class="slide-btn slide-btn-primary"
                       data-app-modal-open
                       aria-label="Edit slide: <?= esc($slideTitle, 'attr') ?>" data-bs-toggle="tooltip" data-bs-title="Edit slide">
                      <i class="bi bi-pencil" aria-hidden="true"></i>
                    </a>
                  <?php endif; ?>

                  <?php
                  // The default image stays visible; only the platform owner may hide it.
                  $canToggle = !$isProtected || (int) $slide['is_active'] !== 1 || $isSuperadmin;
                  ?>
                  <?php if ($canToggle): ?>
                    <form action="<?= site_url('/admin/carousel/' . $slide['id'] . '/toggle') ?>" method="POST" class="d-inline" data-background-submit>
                      <?= csrf_field() ?>
                      <button type="submit" class="slide-btn slide-btn-success"
                              aria-label="<?= (int) $slide['is_active'] === 1 ? 'Hide from login' : 'Show on login' ?>: <?= esc($slideTitle, 'attr') ?>"
                              data-bs-toggle="tooltip" data-bs-title="<?= (int) $slide['is_active'] === 1 ? 'Hide from login' : 'Show on login' ?>">
                        <i class="bi <?= (int) $slide['is_active'] === 1 ? 'bi-eye' : 'bi-eye-slash' ?>" aria-hidden="true"></i>
                      </button>
                    </form>
                  <?php endif; ?>

                  <?php if (!$isProtected || $isSuperadmin): ?>
                    <form action="<?= site_url('/admin/carousel/' . $slide['id'] . '/delete') ?>" method="POST" class="d-inline" data-background-submit
                                              data-confirm="<?= $isProtected ? 'Delete the slide carrying the permanent default login image? This cannot be undone.' : 'Delete this slide and its uploaded images? This cannot be undone.' ?>"
                                              data-confirm-title="Delete carousel slide"
                                              data-confirm-label="Delete slide"
                                              data-confirm-tone="danger">
                      <?= csrf_field() ?>
                      <button type="submit" class="slide-btn slide-btn-danger" aria-label="Delete slide: <?= esc($slideTitle, 'attr') ?>"
                              data-bs-toggle="tooltip" data-bs-title="Delete slide">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="slide-btn slide-btn-danger" aria-disabled="true"
                          data-bs-toggle="tooltip" data-bs-title="Only a superadmin may delete the permanent default image">
                      <i class="bi bi-lock" aria-hidden="true"></i>
                    </span>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</div>
</section>

<style>

  .carousel-admin .slide-thumbnails {
    position: relative;
    width: 78px;
    height: 58px;
  }
  /* Single-variant slides have no stack: one flat thumb, no reserved peek space. */
  .carousel-admin .slide-thumbnails-single { width: 64px; height: 44px; }
  .carousel-admin .slide-thumb {
    position: absolute;
    top: 0;
    left: 0;
    width: 64px;
    height: 44px;
    border-radius: 0.5rem;
    overflow: hidden;
    background: #f1f3f5;
    border: 1px solid var(--na-border, #e9ecef);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s ease, opacity 0.2s ease;
  }
  .carousel-admin .slide-thumb img { width: 100%; height: 100%; object-fit: cover; }
  .carousel-admin .slide-thumb-dark { background: #2b2b2b; }
  /* Card stack: the active theme's variant sits in front; the other variant
     peeks out from behind, tilted, and the stack swaps live on theme toggle. */
  .carousel-admin .slide-thumbnails:not(.slide-thumbnails-single) .slide-thumb-light {
    z-index: 2;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.18);
  }
  .carousel-admin .slide-thumbnails:not(.slide-thumbnails-single) .slide-thumb-dark {
    z-index: 1;
    transform: rotate(6deg) translate(9px, 5px);
  }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .carousel-admin .slide-thumbnails:not(.slide-thumbnails-single) .slide-thumb-dark {
    z-index: 2;
    transform: none;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.45);
  }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .carousel-admin .slide-thumbnails:not(.slide-thumbnails-single) .slide-thumb-light {
    z-index: 1;
    transform: rotate(6deg) translate(9px, 5px);
    box-shadow: none;
  }
  /* Hover: the deck fans out a little further, front card lifts slightly. */
  .carousel-admin .slide-thumbnails:not(.slide-thumbnails-single):hover .slide-thumb-dark {
    transform: rotate(11deg) translate(13px, 8px);
  }
  .carousel-admin .slide-thumbnails:not(.slide-thumbnails-single):hover .slide-thumb-light {
    transform: scale(1.03);
  }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .carousel-admin .slide-thumbnails:not(.slide-thumbnails-single):hover .slide-thumb-light {
    transform: rotate(11deg) translate(13px, 8px);
  }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .carousel-admin .slide-thumbnails:not(.slide-thumbnails-single):hover .slide-thumb-dark {
    transform: scale(1.03);
  }
  /* Hidden slides ease the back card further out while fading it: the active
     theme's variant remains alone until the slide is shown again. */
  .carousel-admin .slide-row-inactive .slide-thumbnails:not(.slide-thumbnails-single) .slide-thumb-dark {
    opacity: 0;
    transform: rotate(6deg) translate(14px, 8px);
  }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .carousel-admin .slide-row-inactive .slide-thumbnails:not(.slide-thumbnails-single) .slide-thumb-dark {
    opacity: 1;
    transform: none;
  }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .carousel-admin .slide-row-inactive .slide-thumbnails:not(.slide-thumbnails-single) .slide-thumb-light {
    opacity: 0;
    transform: rotate(6deg) translate(14px, 8px);
  }
  .carousel-admin .slide-row.dragging { opacity: 0.5; }
  .carousel-admin .slide-copy { min-width: 0; overflow-wrap: anywhere; }
  /* Quiet ghost actions: neutral at rest, semantic tone on hover only. */
  .carousel-admin .slide-btn {
    width: 44px;
    height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: 0;
    border-radius: 0.5rem;
    background: transparent;
    color: #6b7280;
    font-size: 1.05rem;
    transition: background-color 0.15s ease, color 0.15s ease, opacity 0.15s ease;
  }
  .carousel-admin .slide-btn:hover { background: rgba(15, 15, 15, 0.07); color: #374151; }
  .carousel-admin .slide-btn:focus-visible { outline: 2px solid var(--fu-brand, #800000); outline-offset: 2px; }
  .carousel-admin .slide-btn[disabled], .carousel-admin .slide-btn[aria-disabled="true"] { opacity: 0.35; }
  .carousel-admin .slide-btn-primary:hover { background: rgba(128, 0, 27, 0.10); color: var(--fu-brand, #800000); }
  .carousel-admin .slide-btn-success:hover { background: rgba(46, 202, 106, 0.13); color: #1e9e58; }
  .carousel-admin .slide-btn-danger:hover { background: rgba(255, 77, 77, 0.13); color: #d43a3a; }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .carousel-admin .slide-btn { color: #9ca3af; }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .carousel-admin .slide-btn:hover { background: rgba(255, 255, 255, 0.09); color: #e5e7eb; }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .carousel-admin .slide-btn-primary:hover { background: rgba(255, 138, 138, 0.12); color: #ff8a8a; }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .carousel-admin .slide-btn-success:hover { background: rgba(46, 202, 106, 0.14); color: #8bebb0; }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .carousel-admin .slide-btn-danger:hover { background: rgba(255, 77, 77, 0.14); color: #ff9999; }
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
  var lifecycle = new AbortController();
  var disposeCurrent = null;
  function init(root) {

  // --- Drag-and-drop reordering of the slide list ---
  var list = (root || document).querySelector('#slideList');
  if (!list || list.dataset.reorderReady) return;
  list.dataset.reorderReady = 'true';

  var dragged = null;
  var submitting = false;
  var savedOrder = currentOrder();
  var feedback = null;
  var events = new AbortController();
  disposeCurrent = function() {
    events.abort();
    feedback?.dismiss();
    delete list.dataset.reorderReady;
    disposeCurrent = null;
  };
  var options = { signal: events.signal };
  list.querySelectorAll('.slide-thumb img').forEach(function(image) {
    image.addEventListener('error', function fallback() {
      image.src = <?= json_encode(base_url('assets/img/favicon.png')) ?>;
    }, { once: true, signal: events.signal });
  });

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
  document.addEventListener('synapse:before-navigate', guardNavigation, options);
  window.addEventListener('beforeunload', guardUnload, options);
  window.SynapseNavigation?.registerCleanup(function() {
    lifecycle.abort();
    disposeCurrent?.();
  });
  document.addEventListener('synapse:before-region-swap', function(event) {
    if (event.detail?.owner === 'carousel.admin') disposeCurrent?.();
  }, { signal: lifecycle.signal });

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
  }, { capture: true, signal: events.signal });
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
    }, options);
    row.addEventListener('dragend', function() {
      if (dragged !== row) return;
      row.classList.remove('dragging');
      dragged = null;
      persistOrder();
    }, options);
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
  }, options);

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
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json',
          ...(window.SynapseMutations ? { 'X-Synapse-Feedback': 'toast' } : {}) }
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
      if (data.refresh !== undefined && data.refresh?.mode !== 'none') {
        throw new Error('Unexpected reorder refresh instruction. Reload to verify the saved order.');
      }
      if (data.refresh?.mode === 'none') {
        await window.SynapseMutations?.refresh(location.href, data.message, { descriptor: data.refresh, key: 'carousel-reorder' });
      }
      savedOrder = orderValue;
      if (data.refresh?.mode !== 'none') announce(data.message || 'Slide order updated.', 'success');
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
    document.addEventListener('synapse:page-load', function(event) { init(event.detail?.root); }, { once: true });
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { init(document); }, { once: true });
  } else {
    init(document);
  }
  document.addEventListener('synapse:region-load', function(event) {
    if (event.detail?.owner === 'carousel.admin') init(event.detail.root);
  }, { signal: lifecycle.signal });
})();
</script>
<?= $this->endSection() ?>
