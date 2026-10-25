# JavaScript organization

`core/main.js` owns persistent shell controls, theme persistence, and initial Bootstrap/editor/icon setup. `sidebar-state.js` restores sidebar scroll and the desktop collapsed preference before paint.

## Sidebar rail

The application shell keeps its branding inside the sidebar. At widths of 992px and above, the header toggle switches between the full navigation and a 72px icon rail (`body.sidebar-collapsed`). The sidebar remains interactive in either desktop state; the header and content follow its width. `synapse-sidebar-collapsed` in local storage remembers the preference. Smaller screens retain the full drawer with overlay, Escape dismissal, and focus trapping.

Collapsed desktop navigation uses dedicated Bootstrap hover/focus tooltips placed to the right in `body`, avoiding clipping by the scrolling sidebar. They are disabled when expanded or on mobile, and dismissed when scrolling, navigating, or opening a modal. These instances are owned by the sidebar code rather than the generic tooltip initializer. Icon-only links retain accessible labels.

Run `node --test tests/browser/sidebar-rail.test.cjs` and `node --test tests/browser/mobile-layout.test.cjs` for branding, rail geometry, tooltips, persistence, and mobile drawer coverage.

## Background saves and floating feedback

`toast.js` owns the persistent, keyed `SynapseToast.show(message, options)` banners. `background-actions.js` owns only explicit `form[data-background-submit]` small mutations, after shared confirmation. It submits once with the current CSRF token and refreshes the current URL through the existing document-navigation lifecycle; it does not blanket-intercept every POST.

`form-modal.js` exposes `SynapseMutations.refresh(url, message, { form, scroll })` for modal and specialized owners. Confirmed forms are locked and marked saved individually. Other drafts remain editable; if they prevent refreshing, a sticky warning offers a GET-only retry, never another POST. `canRefresh(form)` is a refresh-safety query, not a reason to block saving two independently edited forms. Feedback-owned requests send `X-Synapse-Feedback: toast` so participating controllers do not queue a duplicate success flash.

See [`docs/background-mutations.md`](../../../docs/background-mutations.md) for the screen/function map, intentional navigation exceptions, extension contract, and validation limitations.

## Authentication recovery

`auth-session.js` is loaded before `csrf.js` in the authenticated shell. The shared
`SynapseCsrf.fromResponse(response)` response hook also passes Fetch/XHR responses
to `SynapseAuth.handle`; new request owners must call it before reading a body.
An explicit 401 with `X-Synapse-Auth` and the canonical same-origin
`X-Synapse-Login` triggers one full-document `location.replace` to login. It does
not guess from error text, blanket-intercept fetch, store drafts/reasons in browser
storage, or replay a POST. A bare 401, permission 403, CSRF failure, or network
failure is not evidence of session expiry.

The server keeps a non-flash pending authentication notice until a real login
document is rendered. Background/HTMX/fragment requests and progressive navigation
(`X-Synapse-Navigation: 1`) return the auth contract instead of consuming the login
banner. Proven timeout says the session expired; invalid/revoked authentication
asks for sign-in again; missing session/cookie with no provable cause says only
that sign-in is required. Initial login and deliberate logout remain distinct.

Run `node --test tests/browser/auth-session.test.cjs` and
`php vendor/bin/phpunit tests/unit/AuthFailureContractTest.php --no-coverage`.

## Request-scoped loading

`loading.js` exposes `SynapseLoading.begin(target, { label, skeleton, button, disableButton })`, which returns an idempotent cleanup function. Call it when real work starts and clean up in `finally` after the response body is consumed and rendered. Each target owns its current operation; a stale cleanup cannot clear a newer indicator. Navigation and live-table owners manage their own abort/sequence rules. Background polling deliberately does not trigger page skeletons.

`form-loading.js` enhances only unclaimed native submissions, after confirmation/validation and AJAX handlers. It keeps the native submitter enabled so its name/value and overrides serialize correctly, prevents duplicate submissions, and restores controls on `pageshow`. AJAX forms must own cleanup on failure; successful redirecting writes stay busy until the destination loads. Use `data-loading="false"` on a native form or submitter to opt out. No global `fetch` patch or artificial progress/duration is used.

Run `node --test tests/browser/loading.test.cjs` for request lifecycle, races, failure cleanup, reduced motion, and real native POST coverage.

## Global navigation search

`global-search.js` opens a shared-style Bootstrap modal from the header or `Ctrl/Cmd + K`. It searches only server-rendered sidebar links and the permission-checked generic form links in `partials/search_actions.php`; it never fetches or indexes patient records, case content, or other personal data. Pages inherit sidebar role/workspace visibility. New action shortcuts must enforce their destination's permissions and use `PortalContext::urlFor()`.

Arrow keys select results, Enter opens them, and Escape closes search and restores focus. The search uses the same centered container, theme styling, backdrop, scroll locking, focus trap, and fade lifecycle as confirmation dialogs. It preserves modal-form markers and ordinary navigation behavior, and does not interrupt an existing dialog. Result activation waits for `hidden.bs.modal` so the backdrop and scroll lock are removed before handing off to a form modal. Sidebar links are read again on each open; shell identity changes continue to require full navigation.

Run `node --test tests/browser/global-search.test.cjs` and `php vendor/bin/phpunit --no-coverage tests/unit/SearchActionsViewTest.php` for interaction, responsive layout, and action-visibility coverage.

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
