# 19 — Responsive web contracts

Test at minimum 320, 375, 768, 992, 1280, and 1440 CSS-pixel widths; portrait/landscape where meaningful; keyboard-only; light/dark; 200% zoom; and reduced motion. Functional assertions remain governed by their domain cases.

## UX-WEB-001 — Application shell has one accessible drawer breakpoint
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `884b9f0`
- Requirement: At widths below 992 px the sidebar is an overlay drawer with 44 px controls, body scroll lock, close/overlay behavior, and no main-content offset; at 992 px and above it is a persistent collapsible rail with synchronized header/main offsets.
- Role: `all_authenticated`
- Module: Responsive Web Contracts
- Use Cases: `UC-SH-06`
- Surface: Authenticated web shell on representative portal/workstation pages.
- Route/API: Any authorized page using `layouts/app`.
- Actor/grants: Representative personal, clinic, guidance, BMG, and admin users.
- Preconditions: Pages with long navigation and content; JS enabled and disabled fallback noted.

**Steps**
1. At 991 and 992 px open/close/collapse sidebar via pointer and keyboard; inspect header/main position, focus, overlay, body scrolling, and link reachability.
2. Navigate while drawer is open and use Escape/close/overlay as supported; resize across breakpoint repeatedly.
3. Repeat at 200% zoom and reduced motion.

- Expected: Exactly one correct drawer/rail mode exists; no hidden content, stuck overlay, horizontal page scroll, focus loss, or duplicate control; reduced motion removes transitions.
- Negative/non-disclosure: Drawer never reveals unauthorized links/content and background cannot be accidentally activated while modal drawer is open.
- Cleanup: Reset viewport/sidebar state and sign out.
- Source: `public/assets/css/core/layout.css:2493-2668`; `public/assets/css/core/layout.css:2730-2735`
- Evidence: Breakpoint screen recording, keyboard focus sequence, and overflow measurements.
- Verdict: `NOT_RUN`

## UX-WEB-002 — Header, title, breadcrumbs, actions, and dropdowns reflow safely
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `884b9f0`
- Requirement: On narrow screens the header hides nonessential context, preserves 44 px action targets, constrains dropdowns to viewport/dynamic height, stacks page title/actions, wraps long text, and applies safe-area padding without clipping.
- Role: `all_authenticated`
- Module: Responsive Web Contracts
- Use Cases: `UC-SH-06`
- Surface: Shared app header/page title/profile/notifications.
- Route/API: Representative pages with multiple page actions and long breadcrumb/name.
- Actor/grants: Authorized users with short/long names and varied roles.
- Preconditions: Synthetic long localized-looking titles/names; notification dropdown populated.

**Steps**
1. At 320/375 px open profile/notification dropdowns and inspect bounds, scrolling, layering, and content reachability.
2. Open pages with long title/breadcrumb and several actions; rotate/zoom and trigger validation/toast.
3. Use keyboard and screen-reader names through all header/page actions.

- Expected: Header/main remain within viewport; title/breadcrumb/actions wrap/stack, dropdown scrolls below header, targets are at least 44 px, and focus/labels are usable.
- Negative/non-disclosure: Truncation/wrapping does not expose hidden data, overlap sign-out, obscure alerts, or permit background clicks.
- Cleanup: Close overlays/reset viewport.
- Source: `public/assets/css/core/layout.css:2670-2728`; `app/Views/layouts/app.php:149-180`
- Evidence: Width/zoom screenshots, bounding-box/overflow report, and keyboard sequence.
- Verdict: `NOT_RUN`

## UX-WEB-003 — Dense tables become labelled mobile cards without losing controls
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `884b9f0`
- Requirement: Opted-in tables under 768 px must become readable row cards with assistive headings retained, `data-label` associations, wrapping cells, touch-sized actions, and visible neutral select-all controls; desktop retains semantic table layout.
- Role: `all_authenticated`
- Module: Responsive Web Contracts
- Use Cases: `UC-SH-06`
- Surface: Audit, announcements, appointments, inventories, guidance directories/forms, reports, and similar opted-in tables.
- Route/API: Representative GET pages and harmless selection controls.
- Actor/grants: Authorized readers/operators for sampled tables.
- Preconditions: Rows include long unbroken text, empty values, badges, forms, checkbox/select-all, and pagination.

**Steps**
1. At 767 and 768 px inspect visual layout, DOM/accessibility table semantics/headings, labels, row boundaries, long text, and actions.
2. Operate row actions and select-all via keyboard/touch; navigate pages/filters and resize without reloading.
3. Check tables not opted into card mode for deliberate bounded horizontal scrolling rather than page overflow.

- Expected: Mobile cards preserve field identity/order/action scope and 40–44 px controls; desktop table remains intact; only intended container scrolls horizontally.
- Negative/non-disclosure: Hidden headings remain available to assistive technology; responsive transform does not reveal hidden columns/records or submit wrong row.
- Cleanup: Undo harmless selections/mutations.
- Source: `public/assets/css/responsive-tables.css:1-140`; `app/Views/admin/audit.php:44-102`
- Evidence: 767/768 visual/accessibility captures and row-action identity trace.
- Verdict: `NOT_RUN`

## UX-WEB-004 — Guidance case and operations workspaces stack without losing state
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: Guidance case identity/actions/session/status/history/follow-up/tabs, operations filters/cards, MI/evaluation/governance forms must wrap/stack at mobile widths with readable badges, full-width critical controls, ≥40 px inputs/buttons, and no state/permission change caused by layout.
- Role: `counsellor`
- Module: Responsive Web Contracts
- Use Cases: `UC-GD-05`
- Surface: Guidance dashboard, case, intake, episode, clients, forms, assessments, evaluations, governance.
- Route/API: Relevant guidance web routes.
- Actor/grants: Counselor, supervisor/admin, and restricted practitioner variants.
- Preconditions: Long names/text, multiple tabs/actions/statuses, validation errors; domain fixtures disposable.

**Steps**
1. Traverse representative pages at 320/375/767/768 px, open tabs/details/modals, fill forms, and inspect wrapping/scroll/focus/error association.
2. Resize while tabs/forms/drafts are active and compare selected state/values/permissions.
3. Repeat light/dark, keyboard-only, and 200% zoom.

- Expected: Content/actions remain readable/reachable; status rows stack, tabs wrap, forms/buttons meet target sizes, state/authorization remains unchanged, and no page-level horizontal overflow occurs.
- Negative/non-disclosure: Responsive layout never reveals restricted clinical/cosign/safety content or places destructive action under unrelated control.
- Cleanup: Discard drafts/undo synthetic mutations.
- Source: `public/assets/css/pages/guidance-case.css:1-54`; `public/assets/css/pages/guidance-case.css:57-165`; `public/assets/css/pages/guidance-case.css:167-301`
- Evidence: Page/role/viewport matrix and keyboard/state-preservation recording.
- Verdict: `NOT_RUN`

## UX-WEB-005 — Appointment and availability workspaces preserve complete form operation
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e5b7eba`, `884b9f0`
- Requirement: Booking/review modals and availability section navigation, filters, staff/windows tables, exceptions, date/time controls, and footers must remain operable on small screens; wide tables scroll in their wrapper, narrow controls stack, and date/time semantics remain Manila-local.
- Role: `workstation_member`
- Module: Responsive Web Contracts
- Use Cases: `UC-CL-08`, `UC-GD-11`
- Surface: Personal/staff appointments and staff availability.
- Route/API: `/portal/appointments/{unit}/create`; `/{unit}/appointments`; `/{unit}/appointments/availability`.
- Actor/grants: Personal user and authorized scheduling staff.
- Preconditions: Providers/windows/exceptions and validation conflicts; viewport/keyboard variants.

**Steps**
1. Complete booking and availability create/edit/filter flows at mobile widths with keyboard open; inspect modal/body/footer scroll and action reachability.
2. Exercise staff/windows tables and exception rows at 575/576/767/768, rotate, and resize with form values present.
3. Trigger validation/conflict and verify message/control focus remains visible.

- Expected: No control is obscured/clipped; section links/actions become full-width where defined; tables scroll locally; form values/state and Manila date/time meanings persist.
- Negative/non-disclosure: Layout does not switch personal/staff actor, expose unavailable providers/other records, or double-submit.
- Cleanup: Cancel/remove synthetic appointments/windows/exceptions.
- Source: `public/assets/css/pages/availability-workspace.css:1-42`; `app/Views/appointments/availability.php:1-122`
- Evidence: End-to-end mobile recording, overflow measurements, and validation focus captures.
- Verdict: `NOT_RUN`

## UX-WEB-006 — Teams board, chat, and calendar adapt without semantic drift
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `e5b7eba`, `a06b70b`, `884b9f0`
- Requirement: Team board becomes one column below 576 px; chat/calendar sidebars collapse below 1200 px; composer, messages, picker/dialog, calendar toolbar, and intentional calendar grid scroll remain reachable and scoped to the same unit/draft/version.
- Role: `workstation_member`
- Module: Responsive Web Contracts
- Use Cases: `UC-TW-01`, `UC-TW-02`, `UC-TW-03`
- Surface: Clinic/guidance/facilities Teams web.
- Route/API: `/{unit}/teams`, `/chat`, `/calendar`.
- Actor/grants: Unit member and manager variants.
- Preconditions: Four task columns, long messages/replies/mentions/tasks, populated calendar, unsaved composer/form.

**Steps**
1. At breakpoint boundaries traverse board/status actions, chat log/composer/pickers/replies, and calendar filters/grid/event forms.
2. Resize/rotate/zoom while composer/event/task draft is nonempty; inspect scroll anchors, selected unit, IDs, nonce/version, and action target.
3. Test keyboard, reduced motion, dark mode, and long unbroken text.

- Expected: Board stacks, chat/calendar rails collapse, intended inner regions scroll, composer/actions stay visible, draft/scope/version remain correct, and no page-level overflow or wrong-record submit occurs.
- Negative/non-disclosure: Responsive changes cannot switch unit, reveal foreign content, lose discard protection, or render raw HTML.
- Cleanup: Discard drafts and remove synthetic Team rows.
- Source: `public/assets/css/teams.css:1-104`; `public/assets/css/teams.css:106-168`; `public/assets/css/teams.css:191-237`
- Evidence: Breakpoint recordings, draft/scope assertions, and overflow report.
- Verdict: `NOT_RUN`

## UX-WEB-007 — Personal portal shell avoids stale-page flash and preserves navigation
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `884b9f0`
- Requirement: Direct/reloaded personal subroutes must pre-paint suppress legacy subview only for eligible same-origin portal shell hydration, use no-store navigation prefetch, reveal/fallback within timeout, preserve browser history/focus/scroll, and never suppress unauthorized or PDF/full-document routes.
- Role: `all_authenticated`
- Module: Responsive Web Contracts
- Use Cases: `UC-SH-06`
- Surface: Portal home, visits, settings, announcements, surveys, referrals, MI, evaluations, call slips.
- Route/API: Portal GET routes and navigation fetches.
- Actor/grants: Linked user with portal records/appointments grants; unlinked/revoked variants.
- Preconditions: Direct deep links, slow/offline responses, back/forward cache, distinct canary content.

**Steps**
1. Direct-load/reload each eligible subroute and record first paint through hydrated shell; navigate back/forward and preserve scroll/focus.
2. Throttle/fail prefetch and wait beyond fallback; test PDF and unauthorized/unlinked variants.
3. Change account/session/navigation scope during fetch and inspect incoming document handling.

- Expected: No legacy shell flash for eligible routes; content becomes visible or safe full-page fallback within bound; history/presentation works; auth/scope changes do not swap private HTML.
- Negative/non-disclosure: Suppression never leaves a blank trapped page, leaks previous account content, or intercepts PDF/external/unauthorized navigation.
- Cleanup: Clear navigation/cache/session fixtures.
- Source: `app/Views/layouts/app.php:80-137`; `app/Views/layouts/app.php:141-180`; `public/assets/js/navigation.js:1-659`
- Evidence: First-paint recordings, network/fallback timeline, and history/focus/scroll checks.
- Verdict: `NOT_RUN`

## UX-WEB-008 — Responsive behavior preserves accessibility, theme, and security boundaries
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e5b7eba`, `884b9f0`
- Requirement: Across sampled current pages and breakpoints, zoom/reflow, keyboard order, visible focus, labels/status announcements, contrast in light/dark, reduced motion, modal focus/scroll lock, and safe overflow must meet the same authorization/security contract as desktop.
- Role: `all_authenticated`
- Module: Responsive Web Contracts
- Use Cases: `UC-SH-06`
- Surface: Cross-cutting web shell and all major module workspaces.
- Route/API: Representative pages from authentication, portal, clinic, guidance, BMG, admin, AI, Teams.
- Actor/grants: Least-privilege representative roles.
- Preconditions: Accessibility tooling plus manual keyboard/screen-reader checks; synthetic long/error/loading content.

**Steps**
1. Run keyboard, 200% zoom, reflow, light/dark contrast, reduced-motion, and automated accessibility scans at desktop/mobile sizes.
2. Manually test menus, tabs, forms, tables/cards, dialogs/offcanvas, toasts/live regions, loading/error/empty states and destructive confirmations.
3. At each responsive state directly test one denied route/action and inspect DOM/network for hidden restricted content.

- Expected: No critical/serious accessibility issue in owned behavior; all functionality reachable with meaningful names/focus/status; layout/theme/motion changes preserve authorization and safe confirmation.
- Negative/non-disclosure: CSS hiding is never the sole access control; restricted data/actions are not present for denied roles; focus/backdrops never enable unintended background mutation.
- Cleanup: Close dialogs, undo synthetic mutations, sign out, retain sanitized report only.
- Source: `app/Views/layouts/app.php:184-239`; `public/assets/css/core/layout.css:2558-2728`; `public/assets/css/responsive-tables.css:14-140`
- Evidence: Accessibility report summary, manual checklist, screenshots, and denied-route DOM/network result.
- Verdict: `NOT_RUN`
