# JavaScript organization

`core/main.js` owns persistent shell controls, theme persistence, and initial Bootstrap/editor/icon setup. `sidebar-state.js` restores sidebar scroll before paint.

## Request-scoped loading

`loading.js` exposes `SynapseLoading.begin(target, { label, skeleton, button, disableButton })`, which returns an idempotent cleanup function. Call it when real work starts and clean up in `finally` after the response body is consumed and rendered. Each target owns its current operation; a stale cleanup cannot clear a newer indicator. Navigation and live-table owners manage their own abort/sequence rules. Background polling deliberately does not trigger page skeletons.

`form-loading.js` enhances only unclaimed native submissions, after confirmation/validation and AJAX handlers. It keeps the native submitter enabled so its name/value and overrides serialize correctly, prevents duplicate submissions, and restores controls on `pageshow`. AJAX forms must own cleanup on failure; successful redirecting writes stay busy until the destination loads. Use `data-loading="false"` on a native form or submitter to opt out. No global `fetch` patch or artificial progress/duration is used.

Run `node --test tests/browser/loading.test.cjs` for request lifecycle, races, failure cleanup, reduced motion, and real native POST coverage.

## Progressive navigation

`navigation.js` fetches full server-rendered documents without HTMX fragment headers and replaces only `#main`, `#page-styles`, and `#page-scripts`. Header/sidebar nodes stay alive; PHP still owns authorization and active links. Existing HTMX widgets continue to work through `htmx.process` and `htmx:load` after each swap. Native forms, downloads, modified clicks, and explicit `data-navigation="false"` links retain normal browser behavior.

The authenticated layout supplies a user/tenant/portal fingerprint in `body[data-navigation-scope]`. A changed fingerprint, changed sidebar link set, login response, or incompatible layout causes full-document navigation. History stores URLs and scroll coordinates, never rendered patient data; Back/Forward fetches fresh content. Failed requests keep the outgoing page and offer Retry or a full-page link.

### Page lifecycle contract

- Put page CSS in the layout's `styles` section and scripts in `scripts`; the wrappers are replaced on navigation.
- Define Alpine factories before the new main is inserted. Inline scripts run per visit; external script URLs load once per document. Avoid global top-level `const`/`let` declarations in repeatable inline scripts; use an IIFE.
- Bind DOM-dependent setup to `synapse:page-load` (`event.detail.root` is the incoming main), not only `DOMContentLoaded`. One-off inline setup listeners should use `{ once: true }`.
- Release timers, subscriptions, and in-flight reads with `SynapseNavigation.registerCleanup(fn)` or `synapse:before-swap`. `page-lifecycle.js` owns page Bootstrap/editor setup; selects/datepickers and Alpine/HTMX retain their own teardown.
- Cancel `synapse:before-navigate` to guard an in-flight operation. POST form edits receive a leave confirmation, including edits made during a pending page request. Open form modals veto navigation until closed.
- Fragment owners such as `live-table.js` must use `SynapseNavigation.updateHistory(url, 'push' | 'replace')` rather than directly overwriting navigation's history state. Same-route table Back/Forward stays with the table controller.

`notifications.js` refreshes after content navigation without creating additional polling loops. CSRF metadata and hidden inputs are synchronized on every successful page response.

Run `node --test tests/browser/navigation.test.cjs` for shell identity, history, script/style lifecycle, CSRF, session fallback, request races, dirty edits, and modal/widget regression coverage.

`live-table.js` is loaded by the patient-directory and medicine-inventory views. It progressively enhances ordinary server-paginated GET forms/links with debounced search, abort/sequence protection, validated HTML fragments, filter-preserving URLs, retry, and history restoration. PHP remains responsible for authorization, counts, filtering, ordering, and page limits; do not initialize a client-only DataTable over these partial pages.

`pages/` owns feature-specific behavior. Scripts use route-safe feature names: `calendar.js`, `chat.js`, `contacts.js`, `file-manager.js`, `kanban.js`, `support.js`, and `todo.js`.

When extracting remaining inline scripts, move only page behavior into the matching file. Keep shared selectors and initialization in `core/main.js`, and update the route’s `<script src>` after testing the page independently.
