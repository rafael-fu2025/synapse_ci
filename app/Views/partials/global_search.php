<div id="globalSearchDialog" class="modal fade" tabindex="-1" aria-labelledby="globalSearchTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 620px;">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-bottom py-3">
        <h5 id="globalSearchTitle" class="modal-title fs-6 fw-bold text-dark d-flex align-items-center mb-0">
          <i class="bi bi-search me-2 fs-5 text-primary" aria-hidden="true"></i>Find a page or action
        </h5>
        <button type="button" class="btn-close" data-search-close data-bs-dismiss="modal" aria-label="Close search"></button>
      </div>
      <div class="modal-body">
        <div class="global-search-field">
          <i class="bi bi-search text-muted" aria-hidden="true"></i>
          <label for="globalSearchInput" class="visually-hidden">Search pages and actions</label>
          <input id="globalSearchInput" class="form-control" type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="globalSearchResults" aria-describedby="globalSearchHelp" autocomplete="off" spellcheck="false" maxlength="120" placeholder="Search pages and actions…">
        </div>
        <div id="globalSearchResults" class="global-search-results" role="listbox" aria-label="Pages and actions"></div>
        <p class="global-search-empty text-muted small mb-0" data-search-empty hidden>No matching pages or actions. Try another keyword.</p>
        <p class="visually-hidden" data-search-status role="status" aria-live="polite" aria-atomic="true"></p>
      </div>
      <div class="modal-footer">
        <p id="globalSearchHelp" class="global-search-help text-muted small mb-0 me-auto">↑ ↓ to browse · Enter to open · Esc to close.<br>Records are searched within each module.</p>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
