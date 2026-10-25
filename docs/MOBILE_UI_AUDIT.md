# Mobile UI audit

Audit date: 2026-10-23.

## Scope and limits

The initial review below is an **audit, not a responsive redesign**. No application UI, business logic, database records, or tracked tests were changed during that review. The subsequent Phase 1 implementation is recorded separately below; the original measurements remain as pre-fix evidence.

- Source-reviewed **69 application page templates**, including directly addressable modal-backed pages, across every application view module.
- Also reviewed the shared shell, reusable partials/widgets, four HTML error templates, and the framework welcome template. CLI error templates are not mobile screens.
- Count is by view template, **not by URL, role, or workflow state**: create/edit and clinic/guidance routes often reuse a template. Conditional modes are noted below; not every role/state was browser-rendered.
- Primary target widths: **320, 390, and 768 CSS pixels**. Follow-up coverage should also include 360/375, 767/768, 991/992, desktop, and short landscape heights.
- Three high-confidence findings were reproduced in isolated Chromium fixtures with production assets; the other findings are **source-derived risks**, not visual passes/failures.
- Browser reproduction used synthetic records, no live controllers/models/database, local assets, blocked external resources and blocked mutations. No real clinical data appears in the evidence.
- iOS Safari, Android Chrome on real hardware, virtual-keyboard behavior, 200% text zoom, and a complete light/dark visual matrix remain unverified.

### Evidence and priority

- **B — browser-confirmed:** reproduced with measured descendant bounds and screenshots.
- **S — source risk:** structure/constraints are established; rendered impact still needs verification.
- **L — lower structural risk:** responsive safeguards exist; **not** a visual certification.
- **P1:** inaccessible controls, lost/clipped essential information, or high-risk clinical layout.
- **P2:** responsive density, touch ergonomics, breakpoint conflicts, and important coverage gaps.
- **P3:** lower-risk hardening and remaining verification.

A 44px touch target is used as a practical design benchmark. A smaller target is **not automatically a WCAG violation**; spacing, equivalent controls, exceptions, and actual hit areas matter.

## Executive findings

The system already has a useful mobile foundation: viewport metadata, an off-canvas sidebar, wrapping page actions, mobile table cards, responsive module grids, and scrollable form dialogs. A wholesale rewrite is not warranted.

The main gaps are **inside individual screens and shared widgets**, where nonwrapping rows, fixed subcomponent widths, narrow tablet columns, and small content actions bypass the shell's protections.

Recommended implementation order:

1. Fix the combined date/time widget, survey analytics rows, and carousel management rows/reorder controls.
2. Verify and fix clinical encounter, patient chart/directory, guidance note, and populated queue layouts.
3. Standardize content action groups, mobile touch sizing, filter breakpoints, and long-string handling.
4. Cover all dialog types, populated tables, calendars/boards, loading/error states, and keyboard/short-height behavior with production-view fixtures and real-device checks.

## Phase 1 implementation — completed 2026-10-23

The three confirmed blockers have been fixed; the screen matrix and measurements below describe the original audit baseline, not outstanding defects B1–B3.

- **B1:** `public/assets/css/datetimepicker.css` stacks combined date/time panes at widths up to 501px, wraps the footer, and caps popup height with vertical scrolling. Desktop side-by-side and time-only layouts are retained. Existing JavaScript positioning is unchanged.
- **B2:** `public/assets/css/core/synapse-widgets.css` places mobile labels/counts above a full-width chart track below 768px. Labels wrap fully rather than depending on hover disclosure. Survey data, percentages, and desktop chart layout are unchanged.
- **B3:** `app/Views/admin/carousel/index.php` uses a scoped phone layout that retains full title/subtitle, previews, badges and actions. Accessible Move up/Move down buttons use the existing reorder endpoint with the current CSRF token and duplicate-submission protection; drag reorder and delete confirmation remain available.

Regression validation used synthetic isolated fixtures, production styles/views where applicable, both themes, 320/390/768px and desktop, plus short-height picker scenarios. **84 browser tests passed** across these commands:

```sh
node --test tests/browser/datetimepicker.test.cjs
node --test tests/browser/survey-analytics-mobile.test.cjs
node --test tests/browser/carousel-mobile.test.cjs tests/browser/confirm.test.cjs tests/browser/csrf.test.cjs
```

Results: **27 + 16 + 41 passed**, zero failures. PHP syntax checks passed for the carousel view and both new PHP fixtures; scoped whitespace checks passed.

New regression files:

- `tests/browser/carousel-mobile.test.cjs`
- `tests/browser/survey-analytics-mobile.test.cjs`
- `tests/_support/render-carousel-mobile-fixture.php`
- `tests/_support/render-survey-analytics-fixture.php`

The existing `tests/browser/carousel.test.cjs` broader run exposed a separate pre-existing assertion: it rejects PHP tags in `_form_scripts.php`, which already contains a PHP expression. Neither file was modified to silence it. This is not a full-suite certification. Real-device/keyboard checks and Phases 2–4 remain outstanding.

## Browser-confirmed defects — pre-fix evidence

### B1 · P1 — Combined date/time picker hides time controls on phones

**Owner:** `public/assets/css/datetimepicker.css:40–86,217–229`; positioning in `public/assets/js/datetimepicker.js:721–735`.

**Affected:** announcement create/edit, survey create/edit, team-event create/edit. Date-only and time-only widgets should not be classified as having this defect.

The outer popup is viewport-capped, but the inside remains a horizontal row: 240px minimum date pane, 190px nonshrinking time pane, divider, gaps, and padding. Clamping the outer popup does not constrain its children.

| Viewport | Popup x / width / right | Time-pane x / width / right | Impact |
|---|---|---|---|
| 320×900 | 8 / 304 / 312 | 288 / 190 / 478 | Time pane extends 158px beyond viewport; minutes and AM/PM offscreen. |
| 390×900 | 8 / 374 / 382 | 288 / 190 / 478 | Time pane extends 88px beyond viewport; AM/PM offscreen and minutes partially offscreen. |

The document itself remains 320/390px wide. **Document-width-only tests miss this defect.**

**Adjustment:** stack or otherwise reflow date/time panes on phones; preserve all controls, selected-value feedback, and Apply/Clear actions. Test child bounds, not just popup bounds.

Evidence: [320px](../writable/audit-responsive-artifacts/datetime-320.png), [390px](../writable/audit-responsive-artifacts/datetime-390.png).

### B2 · P1 — Survey analytics bars collapse at 320px

**Owner:** `public/assets/css/core/synapse-widgets.css:492–551`; `app/Views/surveys/responses.php:191–243`.

Rows reserve 140px for the label and 80px for metadata, plus gaps, before the bar receives space. Nested card padding leaves only 213px at 320px.

| Viewport | Row width | Label width | Track width | Metadata width | Impact |
|---|---:|---:|---:|---:|---|
| 320×900 | 213 | 140 | **0** | 80 | All seven synthetic choice/rating tracks disappear; counts extend 29.5px beyond the row and are clipped. |
| 390×900 | 283 | 140 | 40.5 | 80 | Bars remain visible but highly compressed; this is **not** a reproduced overflow at 390px. |

Long option labels are ellipsized with `title` as the disclosure, which is not dependable for touch users.

**Adjustment:** place label and count above/beside a full-width bar on phones; allow full labels or explicit touch disclosure. Check low-percent and zero-result charts as well as typical percentages.

Evidence: [320px](../writable/audit-responsive-artifacts/analytics-320.png), [390px](../writable/audit-responsive-artifacts/analytics-390.png).

### B3 · P1 — Carousel row loses text and clips Delete at 320px

**Owner:** `app/Views/admin/carousel/index.php:30–89,100–114,131–152`.

A populated row combines a drag handle, two fixed thumbnails, text, and a nonshrinking action cluster without phone reflow.

At 320×900:

- Row: x=11.25, width=297.5, right=308.75.
- Title/subtitle column: **width=0px**.
- Action cluster: x=225.5, width=115.875, right=341.375.
- Delete button: x=305.25, width=36.125, right=341.375 — **21.375px beyond the viewport**.
- Document width remains 320px despite descendant clipping.

Reordering also uses HTML drag events without explicit move-up/down or another source-provided touch/keyboard alternative. That interaction limitation is source-confirmed, not a real-device drag test.

**Adjustment:** stack preview/content/actions on phones, preserve readable title/status, enlarge action hit areas, and add explicit reorder actions rather than relying exclusively on drag.

Evidence: [320px](../writable/audit-responsive-artifacts/carousel-320.png).

### Reproduction details

From the project root:

```sh
node writable/audit-responsive.cjs
```

Local reproduction files, not tracked application/test changes:

- `writable/audit-responsive.cjs`
- `writable/audit-responsive-render.php`
- `writable/audit-responsive-artifacts/measurements.json`
- Rendered HTML, screenshots, request logs, and error collection in the same artifacts directory.

Chromium 155.0.8059.12, DPR 1, 320×900 and 390×900. Analytics/carousel use the actual views and application layout; the date/time reproduction reuses the existing standalone widget fixture with production assets. External fonts were blocked, so text metrics use available local/fallback fonts. PHP/browser error collection was empty; the local server recorded GET only. Artifacts under `writable` may not be retained in version control.

## Shared layout and component review

| Component | Existing protection | Remaining work |
|---|---|---|
| App shell | `app/Views/layouts/app.php:4–6,82–108`; `public/assets/css/core/layout.css:2472–2580`: fluid main, off-canvas sidebar below 992, page actions wrap and stack below 768. | Preserve this behavior; do not assume it fixes content-level flex groups. Test long names, role variants, active sidebar and long breadcrumbs. |
| Header / account / notifications / workstation switching | Header hides wordmark/context on phones; header actions have 44px minima. Dropdowns use viewport-capped fixed positioning and bounded vertical scrolling (`core/layout.css:2524–2559`). | Open each menu at 320 and short heights, with maximum notification content and all permitted workstations. Notification Mark all read/Retry remain small content controls (`app/Views/partials/notifications.php:18–31`). |
| Titles/actions | `.page-actions` wraps; phone buttons allow wrapping text and have 44px minimum height. | Do not apply broad full-width overrides to every button. Introduce consistent content action-group patterns for toolbars, card actions and destructive actions. |
| Table conversion | `public/assets/js/responsive-tables.js:5–42` enhances eligible simple `.table` headers and observes replacements. `responsive-tables.css:2–85` supplies labeled cards below 767.98px. | Test populated actions, badges, long values, sorting/select-all, empty/error results, and live replacements. Nested nonwrapping button groups can overflow even when cells wrap. |
| Table-card density | Label gutter reserves 6.65rem on phones. | At 320, consider stacked label/value cells or action rows spanning the card width. Do not remove clinically meaningful labels to make content fit. |
| 768px boundary | Mobile cards end just as many `col-md-*` form splits begin. | Explicitly test 767/768. Several action pairs become too narrow inside `col-md-2`/`col-md-3`. Prefer wrapping or later column splits. |
| Form dialogs | `app/Views/layouts/modal_form.php:14–29`: centered, scrollable body; actions generally wrap. Direct and fragment routes share the dialog. | Verify full content and save/cancel reachability with keyboard open and short heights. Consider mobile fullscreen for long builders/workflows based on measured need, not every modal indiscriminately. |
| Date-only / time-only widgets | Date popup capped to viewport minus 16px; position clamped. Time-only is a separate narrower mode. | Navigation controls and wheel rows are small; verify open widgets in modal edges, scrolling, zoom and keyboard conditions. B1 applies to combined date/time mode. |
| Custom selects | Labels ellipsize, menus scroll, Popper uses viewport collision handling. | Long medicine, provider, case and audience options need full-text readability; verify menu descendants and touch scrolling, not just closed control width. |
| Touch targets | Base `.btn` minimum 38px, `.btn-sm` 32px; shell/page actions selectively enlarged. | Make high-frequency content actions and icon-only controls comfortably tappable without indiscriminately enlarging dense data displays. Calendar day-add is 26×26px; date arrows roughly 30×32px. |
| Long text | Mobile table cells and selected module areas use explicit word breaking. | Names, allergies, badges, note URLs, permission identifiers, questions/options and file names need consistent handling. Avoid truncating critical clinical text without accessible disclosure. |
| Loading/error states | Navigation feedback is viewport-width capped; draft and modal errors are displayed inline. | Verify status/error messages do not push primary actions out of reach. No wrapping/clipping workaround should weaken draft saving, explicit dispensing consent, or destructive confirmation. |

## Screen-by-screen inventory

Unless marked **B**, entries below are **source assessments**. Priority reflects the highest relevant mobile risk, not a demonstrated defect on every width. All rows were reviewed for 320/390/768 behavior; width-specific concerns are called out. A lower-risk row still requires rendered verification.

### Clinic — 5 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/clinic/dashboard.php` | S · P2 | Stacked/two-up KPI grid and mobile-card recent table already exist. At 320/390 inspect nonwrapping recent-table heading/link and three-column chart summaries; test large counts. Anchors: 99–118,124–162; `pages/dashboard.css:231–257`. |
| `app/Views/clinic/encounters/index.php` | S · P2 | Header wraps but status/date filter form does not. Complaint span independently truncates at 220px inside a smaller mobile-card value area. Wrap filters and make complaint disclosure usable; retain local scrolling at 768. Anchors: 11–34,74–77. |
| `app/Views/clinic/encounters/stepper.php` | S · P1 | Long identity/status and health-flag rows do not wrap internally; allergy badge is nowrap. Four tabs wrap into a potentially tall navigation. Discard/Continue medicine footer lacks wrap. At 768 inspect four-up vitals and BMI density. Stack/wrap identity and actions; preserve draft status and explicit dispensing. Anchors: 30–69,88–112,119–203,366–378. |
| `app/Views/clinic/patients/index.php` | S · P1 | Filters stack; mobile-card Actions still contains a nonwrapping Chart + Start/Resume `.btn-group`, particularly risky at 320. Stack or wrap real actions within available card width. At 768 inspect narrow filter-action column. `_results.php:64–79`; index:21–46. |
| `app/Views/clinic/patients/chart.php` | S · P1 | Inner avatar/identity, emergency-contact and history heading/action rows do not wrap; allergy badge is nowrap. Use readable wrapping and stacked mobile actions; verify all tabs and long notes/contacts, not only default tab. Anchors: 50–88,130–140,150–169,240–335. |

Closed encounter display (`clinic/encounters/_closed.php`) is a state of the stepper, not an additional template count. Include it in production-view mobile tests along with reversed dispensations and long medicine instructions.

### Guidance — 7 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/guidance/dashboard.php` | S · P2 | Same narrow recent-heading/action and three-column chart-summary concerns as clinic dashboard; tables already convert to cards. Anchors: 86–105,111–157. |
| `app/Views/guidance/cases/index.php` | S · P2 | Two-filter inner form and heading badge do not wrap; eight-column table becomes cards below 768. Verify filter/title pressure at 320 and pagination wrapping. Anchors:16–40,117–129. |
| `app/Views/guidance/cases/create.php` | S · P2 | Modal stacks fields, but search-result/selected-client identity and Select/Change rows do not wrap. Test longest names, 180px results scroll, errors and the 8/4 split at 768. Anchors:22–51,58–97. |
| `app/Views/guidance/cases/view.php` | S · P2 | Dedicated `pages/guidance-case.css:10–54` already stacks mobile editor/status/history/follow-up and wraps identities/actions. Preserve it; test tall tabs, badges, every session state, and exact 768 boundary. Anchors:29–134,176–188,244–300. |
| `app/Views/guidance/sessions/start.php` | L · P3 | Single-column select and wrapping actions. Test long options, direct/fragment dialog and short-height save reachability. Anchors:3–18. |
| `app/Views/guidance/sessions/read.php` | S · P1 | Inner case/title/type and note version/date rows do not wrap; note uses `pre-wrap` without explicit long-token breaking. Wrap metadata and pasted URLs/tokens while preserving note formatting. Anchors:6–21,33–49. |
| `app/Views/guidance/sessions/history.php` | S · P1 | Padded version cards and `pre-wrap` note body need long-token breaking; stress many versions and narrow dialog scrolling. Anchors:9–17. |

### Personal portal — 4 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/portal/index.php` | S · P2 | Profile's 360px minimum is explicitly removed below 381px: not a blanket fixed-width bug. Name/details including allergies ellipsize; provide touch-usable full critical information. Populated appointment Details/Cancel group does not wrap. Inspect all profile/appointment/record states. Anchors:103–191,203–264,309–325; `core/synapse-widgets.css:195–219,288–340`. |
| `app/Views/portal/appointment_detail.php` | S · P2 | Definition list stacks below `sm`; long reason/provider tokens need breaking, including 4/8 columns at 768. Anchors:63–100. |
| `app/Views/portal/settings.php` | S · P2 | Fixed 96px avatar beside nonwrapping explanatory row consumes phone width. Stack at narrow widths; test upload filenames, preview/errors, remove action and file-picker behavior. Identity fields already break strings. Anchors:7–24,32–44. |
| `app/Views/portal/access_notice.php` | L · P3 | Viewport metadata, max-width panel and wrapping links exist. Check long destination labels and touch targets; it does not inherit app-shell sizing. Anchors:2–3. |

### Appointments — 4 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/appointments/index.php` | S · P2 | Both tables convert to cards. Pending header/description and adjacent small confirm/cancel/check-in/no-show actions need narrow testing; prioritize separation of destructive actions. Native tables scroll at 768. Anchors:13–28,35–66. |
| `app/Views/appointments/create.php` | S · P2 | Staff search/selected-person rows do not wrap; result container has duplicate `style` attributes, making intended height/overflow cap unreliable. Combine declarations and test long identities/large results. Personal mode and date/time split at 768 need separate checks. Anchors:24–52,69–78. |
| `app/Views/appointments/confirm.php` | S · P2 | Slot buttons wrap but use small targets; test many slots, unavailable/error states and long provider options. Anchors:11–25,35–49. |
| `app/Views/appointments/availability.php` | S · P2 | Responsive workspace and mobile-card tables already exist; table min-widths are overridden on phones. Enlarge roster selection hit areas, inspect third-width time fields at 768 and weekly/enrollment/exception states. Anchors:25–115; `pages/availability-workspace.css:7–32`. |

`appointments/exception_form.php`, `_availability_context.php` and `partials/schedule_time.php` are reused pieces, not additional full-page templates. Include their open/error states in dialog/widget checks.

### Queue — 2 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/queue/index.php` | S · P1 | Phone columns stack; 768 uses two columns. Walk-in triage radios and identity/result rows do not wrap. Two-up KPI cards with 40px icons, populated ticket headers/actions and long identities require 320 checks. Columns retain nested scrolling, 280px minimum/700px maximum; verify discoverability and scroll comfort. Anchors:35–83,105–147; `board_partial.php:28–63,128–226`; `queue.css:7–19,40`. |
| `app/Views/queue/error.php` | L · P3 | Simple alert and return action; wrap long errors and test comfortable action size. Anchors:3–4. |

Include clinic/guidance modes, all populated ticket statuses, walk-in modal, no-show confirmation, active history tabs and realtime replacements. Empty-board geometry is insufficient.

### Referrals — 4 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/referrals/index.php` | S · P2 | Folder/row actions wrap and table supports cards; Search/Reset share nonwrapping `col-md-3` at 768. Wrap or postpone this split; test eight-column table scrolling and shared-summary expansion. Anchors:10–27; `_results.php:23–43`. |
| `app/Views/referrals/create.php` | S · P2 | Search/selection rows do not wrap and duplicate-style results cap needs repair. Test longest identities, reason/priority 7/5 split at 768 and direct/fragment dialogs. Anchors:20–57. |
| `app/Views/referrals/handoff.php` | S · P2 | Stacked fields/wrapping actions exist. Long existing-case code/title options and identity need full-text readability; test both clinic and guidance modes. Anchors:4–41. |
| `app/Views/referrals/workstation.php` | L · P3 | Two links already wrap; verify long labels and ordinary content button touch size. Anchors:3–9. |

### Facilities — 5 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/facilities/index.php` | S · P2 | One-column phone/two-column 768 grid, wrapping facts and responsive BMG text rules exist. Check Current/Archived internal nav, long drum identities/timestamps and all editor/archive dialogs. Anchors:15–68; `bmg.css:2–24,45–56`. |
| `app/Views/facilities/dashboard.php` | S · P2 | KPI/analytics stack; tables support cards. Unlike workspace pages, dashboard does not inherit every `.bmg-workspace` protection. Inspect nonwrapping status heading/link and three chart-summary columns with large counts at 320. Anchors:114–117; `pages/dashboard.css:231–257`. |
| `app/Views/facilities/unit_detail.php` | S · P2 | Main/aside/commands reflow. Start-time + Remove row stays horizontal; gear remains 96px beside identity. Test long names, small schedule editing width, history cards and read/write roles. Anchors:26–165, particularly57–64; dynamic rows in `public/assets/js/bmg.js:139–150`. |
| `app/Views/facilities/device_token.php` | L · P3 | Max-width panel and fluid readonly token textarea. Inspect inner title row and selection/copy ergonomics using dummy credentials only. Anchors:6–17. |
| `app/Views/facilities/usb.php` | S · P2 | Responsive workflow/cards, wrapping tools and bounded log exist. Test enabled/error/advanced-editor states and long release labels. UI explicitly requires supported **desktop Web Serial**; fitting on a phone does not imply phone commissioning support. Anchors:21–227, especially53; `bmg-usb.css:126–135,193–238`. |

Facilities partials to include: `_archive_modal.php`, `_drum_card.php`, `_gear.php`, `_history.php`, `_operation_modals.php`, `_setup_steps.php`, `_unit_modal.php`. Several dialogs use outer-modal scrolling rather than a scrollable-body layout; verify short-height actions and nested confirmations.

### Inventory — 8 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/inventory/index.php` | S · P2 | Phone filters stack and actions wrap; eligible result table becomes cards. Check actual badges/actions in narrow label gutter, populated/live/error states, and seven-column table at 768. Anchors:10–54; `_results.php:15–70`. |
| `app/Views/inventory/show.php` | S · P2 | Definition list/stock tiles reflow; three history tables become cards. Test long lot/medicine/unit text, small batch actions, ledger pagination and vertical information density. Action/footer wrapping already exists. Anchors:14–159. |
| `app/Views/inventory/unavailable.php` | L · P3 | Simple card and return action; stress long setup/error message. Anchors:3–9. |
| `app/Views/inventory/catalog.php` | S · P2 | Modal fields stack then pair at 768. Test longest labels, frozen-field notices, validation, open custom select, direct/fragment navigation and keyboard reachability. Anchors:19–46. |
| `app/Views/inventory/receive.php` | S · P2 | Fields stack; date-only expiry picker has width caps. Test open picker near modal edges, long stock units and invalid values. Anchors:7–36. |
| `app/Views/inventory/adjust.php` | S · P2 | Fields stack and footer wraps. Test destructive-action clarity, unavailable stock state, long lot identity and reason menu. Anchors:7–35. |
| `app/Views/inventory/status.php` | S · P2 | Single-column form. Verify full quarantine/release options, reason validation and long dialog scrolling. Anchors:4–23. |
| `app/Views/inventory/reverse.php` | S · P2 | Highest dialog-density risk: review mode, reason, acknowledgement and final action must stay understandable/reachable. Definition list stacks; no proven fixed-width defect. Test keyboard/short heights and all correction modes. Anchors:4–38. |

### Teams — 7 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/teams/index.php` | S · P2 | Phone board stacks below576; 768 board intentionally scrolls four columns of at least250px. List table supports cards. Filter Apply/Reset share nonwrapping `col-md-2` at768; wrap/defer split. Test long list values and deliberate board-scroll discovery. Anchors:11–72, particularly32–35; `teams.css:6,33–35`. |
| `app/Views/teams/form.php` | S · P2 | Metadata stacks; checklist row keeps checkbox, text and Remove inline. Test usable input width at320, icon targets, long assignee choices and date-only popup. Anchors:9–33. |
| `app/Views/teams/show.php` | S · P2 | Definition list stacks; Move group wraps, comments/descriptions break. Verify 160px-minimum move select within padded dialog at320 and long member/activity strings. Anchors:10–31; `teams.css:29`. |
| `app/Views/teams/chat.php` | S · P2 | Channels precede log/composer through768; text wraps and composer tools wrap. Verify long page plus nested log scrolling, scroll-to-bottom behavior and composer with virtual keyboard. Anchors:8–37; `teams.css:38–61`. |
| `app/Views/teams/calendar.php` | S · P2 | Month/week deliberately retain700px grid in labeled scroll region; Agenda is available but not automatically selected on phones. Check 26px day-add buttons, view-toggle subgroup, long event labels and empty/populated states. Do not convert calendar geometry to generic cards. Anchors:24–60; `teams.css:78`. |
| `app/Views/teams/event_form.php` | **B1 · P1** | Combined date/time widget defect. Also test participant list's220px nested scroll area and long names with keyboard. Anchors:4–19; `teams.css:91`. |
| `app/Views/teams/event_show.php` | S · P2 | Definition list stacks and actions wrap. Test long participant/organizer strings, cancellation confirmation and short-height direct/fragment dialogs. Anchors:3–18. |

### Administration — 7 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/admin/audit.php` | S · P2 | Phone filters stack, 768 two-up; table supports cards/scrolling. Check nested badges, long identifiers/hashes, actual pager and in-content action sizes. Anchors:41–75,89–180. |
| `app/Views/admin/carousel/index.php` | **B3 · P1** | Reflow row to retain title/status/actions at320 and add explicit touch/keyboard reorder controls. Anchors:30–152. |
| `app/Views/admin/carousel/create.php` | S · P2 | Shared modal form has stacked fields and wrapping actions. Test long fit-mode options, upload/error states, keyboard and short heights. `_form.php:19–97`; entry:1–5. |
| `app/Views/admin/carousel/edit.php` | S · P2 | Same as create plus current images/removal state. File names already break in scripts; test long names and all image combinations. `_form_scripts.php:68–88`; entry:1–5. |
| `app/Views/admin/employee_access.php` | S · P2 | Phone filters stack; 768 uses6/2/4 split. Test narrow per-page select, long departments/names and live-result cards. Anchors:11–28; `_employee_access_results.php:6–14`. |
| `app/Views/admin/employee_access_form.php` | S · P2 | Single-column modal. Employee identity/department and permission codes need long-token breaking and short-height review. Anchors:4–24. |
| `app/Views/admin/mis_sync.php` | S · P2 | Phone table can become cards, but native table has no responsive wrapper at768. Nonwrapping progress header and long action/error states need inspection. Anchors:12–42. |

### Authentication — 1 template

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/auth/login.php` | S · P2 | Carousel is hidden at320/390/768; capped380px form reflows. Check logo bounds, errors, autofill/password managers, autofocus/keyboard, short landscape and touch sizing of password/theme toggles. Theme button42px; password toggle smaller. Anchors:104–283; `layouts/auth.php:326–341`. |

### General dashboard — 1 template

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/dashboard/index.php` | S · P2 | KPI/chart columns reflow and recent audit supports cards. Check nonwrapping recent header, three-column summaries, long welcome text and hidden-overflow card clipping. Anchors:42–55,109–215; `pages/dashboard.css:15–33,231–257`. |

### Announcements — 4 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/announcements/index.php` | S · P2 | Tables become cards on phones and actions wrap. Read-history table lacks768 scroll wrapper; own pagination lacks explicit wrapping. Inspect expanded previews, long badges and history. Anchors:8–30; `_pagination.php:5–9`. |
| `app/Views/announcements/form.php` | **B1 · P1** | Combined date/time defect. Targeting choices wrap and fields stack; test paired768 dates, long audience choices, validation and keyboard. Anchors:8–47. |
| `app/Views/announcements/portal.php` | S · P2 | Phone filters/tabs/footer reflow; titles/excerpts break. At768 nonwrapping Search/Reset pair shares `col-md-3`. Wrap/defer split; retain full-width phone CTA. Anchors:16–53. |
| `app/Views/announcements/show.php` | L · P3 | Reading width bounded, title/body wrap and footer wraps. Verify long content/links, read states and touch actions. Anchors:5–18. |

### Reports — 1 template

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/reports/export.php` | S · P2 | Filters stack, dates/download cards pair at768, download buttons full-width. Check nonwrapping icon/title rows at320, date popup, download-panel errors/progress/cancel and keyboard. Anchors:16–138. |

### Question bank — 3 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/question_bank/index.php` | S · P2 | Table supports cards; row actions wrap. At768 Search/Clear share nonwrapping `col-md-2`; own pager also lacks wrap. Wrap/defer split and test populated long questions. Anchors:10–35. |
| `app/Views/question_bank/form.php` | S · P2 | Modal fields stack; option input shares width with Remove option. Test usable320 input width, answer-type menu, add/remove rows, errors and direct/fragment dialog. Anchors:7–16. |
| `app/Views/question_bank/show.php` | S · P2 | Revision rows wrap; heading/category/options need long-token breaking. Review-note text already protected. Anchors:3–10; `_preview.php:2–5`. |

### Surveys — 6 templates

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/surveys/index.php` | S · P2 | Cards/scrolling and wrapping row actions/pager exist. Stress nested targeting badges, long names/statuses and action hit areas. Anchors:9–23. |
| `app/Views/surveys/form.php` | **B1 · P1**, S · P2 | Combined date/time defect; builder bank filters split too early at768 inside a dialog. Bank pager and option groups need wrapping; long bank labels, many questions and save reachability need short-height testing. Anchors:14–94. |
| `app/Views/surveys/portal_index.php` | S · P2 | Phone filters/tabs wrap; cards one-up then two-up at768; titles/descriptions break and CTA is full-width. Check date/status density at the breakpoint and pagination targets. Anchors:14–50. |
| `app/Views/surveys/take.php` | S · P2 | Full-width fields and clickable choice labels exist. Survey title, legends and long option tokens need wrapping; test many questions, validation, confirmation and keyboard return-to-field. Anchors:4–27. |
| `app/Views/surveys/responses.php` | **B2 · P1** | Reflow fixed label/meta analytics rows; provide touch-readable full labels. Test all chart types, submissions table cards, tabs and long samples. Anchors:25–61,121–138,168–322. |
| `app/Views/surveys/response.php` | S · P2 | Answer values break; survey/respondent/question headings do not have equivalent protection. Test long values and reading structure. Anchors:4–8. |

Application total: **5 + 7 + 4 + 4 + 2 + 4 + 5 + 8 + 7 + 7 + 1 + 1 + 4 + 1 + 3 + 6 = 69 templates**.

### Error and framework templates — separate from application count

| Template | Assessment | Recommended adjustment / check |
|---|---|---|
| `app/Views/errors/html/error_400.php` | S · P2 | No viewport metadata in head; standalone layout bypasses shared mobile styles. Add viewport and review message wrapping, spacing, and recovery action. Anchors:1–7,33–53,71–83. |
| `app/Views/errors/html/error_404.php` | S · P2 | Same missing viewport metadata and standalone layout concern; verify useful mobile recovery rather than only displaying404. Anchors:1–7,33–53,71–84. |
| `app/Views/errors/html/production.php` | S · P2 | No viewport metadata; imports debug CSS with5rem headline. Check readable phone presentation and recovery action without disclosing diagnostics. Anchors:1–23; `debug.css:20–39`. |
| `app/Views/errors/html/error_exception.php` | S · P3 | Development diagnostic screen lacks viewport metadata. Long traces/source are intentionally scrollable. Lower product priority; keep production diagnostics policy unchanged. Anchors:7–21; `debug.css:78–86`. |
| `app/Views/welcome_message.php` | L · P3 / scope check | Framework welcome page has viewport metadata and its own responsive styling. No reference in configured routes was found during the audit; not treated as an active product screen. Confirm reachability before spending redesign effort. |

## Existing coverage and gaps

Existing test source already covers many mobile components. It does **not** certify every application screen.

| Suite / area | Existing coverage | Gap |
|---|---|---|
| `tests/browser/mobile-layout.test.cjs` | Real BMG shell;320/390/768/991/992/1440; light/dark; synthetic long titles/actions and generic table cards. | Not a69-screen production-content matrix; injected table does not test real nested actions/badges. |
| `tests/browser/bmg-layout.test.cjs` | Overview/detail/USB/token at390/1440, both themes. | Facilities dashboard,320/768 module sweep and every dialog state absent. |
| `tests/browser/encounter-stepper.test.cjs` | Draft save, validation, completion, pending medicine, CSRF and exit behavior. | Minimal synthetic markup, not production stepper mobile styling. |
| `tests/browser/inventory.test.cjs` | Actual inventory/dialog/stepper functionality and themes. | Primarily1280px; no populated320/390/768 module matrix. |
| Guidance workspace/new-case | Workspace at375/1440; new-case behavior generally desktop. |320/390/768, populated registry, note-reader/history long-token cases. |
| Queue layout/walk-in/realtime |390px empty-board geometry; desktop walk-in/realtime behavior. | Populated ticket actions, real toolbar/walk-in modal and long patient identities at320. |
| Availability/modal forms |375/390 exception coverage; desktop appointment/referral forms. | Every direct/fragment form and all768 transitions/short heights. |
| Patient live table/referrals | Functional desktop filtering/actions. | Actual mobile-card action geometry and expanded states. |
| Teams/calendar/chat | Some390 direct dialogs/calendars and360/390 chat long-content cases. |320/768 sweep; full date/time popup children, touch reorder, keyboard/composer and participant nested scrolling. |
| Engagement |375 announcement/survey flows, themes and overflow checks. | Date/time fields left empty in builder cases; analytics descendant collapse missed by parent-width checks. |
| Carousel management | Desktop/mock-manager functionality. | Actual populated production row phone bounds and touch/keyboard reorder. |
| Employee access/question bank | Desktop/live functionality. | Mobile populated forms/results and768 action-column conflict. Live mutation suites were not run for this audit. |
| Reports/downloads |390 report content and320 download panel checks. | Full production-shell matrix and keyboard states. |
| Login/profile/widgets |390 login/theme/profile;375/390 date/dropdown/time-only bounds. |320/768 complete login/profile, real keyboards, combined date/time child bounds. |

### Validation performed during this audit

1. **Passed:** isolated browser reproduction via `node writable/audit-responsive.cjs`, confirming B1–B3 as detailed above. This is targeted evidence, not a regression-suite pass.
2. Attempted:

   ```sh
   node --test tests/browser/mobile-layout.test.cjs tests/browser/bmg-layout.test.cjs
   ```

   **Timed out after120 seconds.** Multiple individual BMG layout cases reported success before termination, but there was no final combined summary. **Do not treat these suites as passed.** No longer rerun was attempted. A longer bounded run can be scheduled separately.
3. Source-reviewed the application inventory, shared cascade, widget behavior and test coverage. No authenticated live-page audit, real-device test or complete cross-browser visual pass was performed.

## Adjustment plan

### Phase1 — Confirmed blockers

- B1: responsive combined date/time layout with reachable hour/minute/AM-PM controls and actions.
- B2: stacked/flexible survey chart rows with full label disclosure.
- B3: mobile carousel row layout and explicit reorder alternatives.
- Add production-style fixture regressions at320/390/768; assert critical descendant bounds, visible text, nonzero chart-track widths and tappable controls.

### Phase2 — Clinical and patient workflows

- Encounter identity/allergies, compact step navigation, mobile vitals/BMI, draft status and medicine footer.
- Patient chart metadata/history and directory card actions.
- Queue walk-in triage/identities, populated card actions, KPI layout and nested scrolling.
- Guidance note metadata and long-token wrapping.
- Populated personal portal with critical allergy/health information not hidden behind hover-only disclosure.
- Preserve all save-before-navigation safeguards and inventory confirmation/idempotency behavior.

### Phase3 — Shared consistency

- Reusable wrapping/stacking content-action pattern rather than scattered button sizing patches.
- Comfortable touch targets for frequent/icon-only actions, roster/slot selection and picker controls.
- Filter columns that do not become narrow action traps at768.
- Long-string/badge handling and card-table action layout at320.
- Responsive scroll containment for tablet native tables that currently lack wrappers.
- Correct duplicate-style result containers in appointment/referral search.
- Standalone error-page viewport/readability/recovery.

### Phase4 — Dense screens and device verification

- Survey/question-bank builders, inventory correction, all modal forms and validation states.
- Calendar/kanban deliberate local scrolling and accessible alternatives; do not force complex calendars into generic cards.
- Chat keyboard/composer and scroll-to-bottom states.
- Real iOS Safari and Android keyboard, safe areas, orientation and text enlargement.

## Completion criteria for the adjustment work

For every application template and meaningful conditional mode:

- No unintended document overflow **or clipped essential descendants** at320/390/768.
- All critical text remains readable or has an explicit touch/keyboard disclosure; no hover-only dependency for essential information.
- Primary, secondary and destructive actions are reachable, labeled and sensibly separated.
- Forms preserve input on validation/network failures; focused invalid fields can be found and corrected.
- Dialogs, custom selects and pickers stay usable with short height/keyboard; all controls can be reached.
- Mobile table cards retain labels/actions; tablet scroll regions are discoverable and contain overflow locally.
- Long populated content, empty/loading/error/disabled states, live replacements and permission variants are checked.
- Light/dark,767/768 and991/992 boundaries, desktop regression, keyboard access and real-device spot checks are included.

**Bottom line:** consider mobile UI for every screen, but fix by shared component and risk priority rather than making every screen identical. The audit establishes the inventory and first confirmed blockers; it does not claim that every screen has been visually tested or adjusted.
