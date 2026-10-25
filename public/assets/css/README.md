# Stylesheet organization

`style.css` is the stable stylesheet URL used by the root-level HTML routes. It imports modules in the same order as the former stylesheet so the first organization pass does not intentionally change visual precedence.

The `core/` directory contains tokens, base rules, layout rules, and reusable component rules. The `pages/` directory contains page-family rules. `dashboard.css` currently holds the dashboard and remaining page-specific tail of the former monolith; additional page-family extraction should be incremental and validated visually.

## Responsive application shell

- `body.app-shell` opts the shared PHP layout into the application geometry in `core/layout.css`; keep layout rules out of inline view styles.
- The application sidebar is a drawer below 992px and a collapsible rail at 992px and above. `core/main.js` uses the same breakpoint and synchronizes visibility, keyboard focus, and `aria-expanded`.
- Mobile page actions wrap below the title with 44px touch targets. Header menus stay inside the viewport and scroll independently on short screens.
- Run `node --test tests/browser/mobile-layout.test.cjs` for the light/dark 320–1440px shell matrix, drawer dismissal, and long-title/action overflow checks.

## Theme contrast contract

- `core/tokens.css` owns the neutral dark palette for both `html[data-theme="dark"]` and the legacy `body.dark-mode` hook. Do not redeclare a separate blue palette in layout or page styles.
- `core/synapse-theme.css` owns filled action variants, readable table tags, breadcrumb separators, autofill colors, and checkbox/radio states. Outline and link-style `.btn` classes intentionally render as filled actions. Primary actions and both Sign Out buttons use solid `#800000` with white text; secondary and destructive actions retain distinct semantic fills. Disabled actions stay recognizable and noninteractive.
- Maroon-filled buttons and tabs always use white text and icons, including nested text utilities. Accent icons use `--icon-accent-color` / `--icon-hover-color`: maroon on light surfaces, white on neutral dark surfaces. Keep these separate from `--fu-brand` so correcting icon contrast never changes a maroon fill. Semantic warning/success/error icons retain their status colors.
- `core/synapse-widgets.css` applies the same fill policy to picker toolbar actions. Calendar days, time-wheel options, dropdown options, and navigation links keep their selection/navigation semantics.
- Enabled normal-sized text must meet 4.5:1 contrast; control boundaries, selected indicators, and breadcrumb separators must meet 3:1. Keep visible keyboard focus and do not use color alone for selection.
- Run `node --test --test-concurrency=1 tests/browser/theme-contrast.test.cjs` for the light/dark desktop/mobile matrix, interactive states, actual layout logout buttons, dynamic theme switching, and Chromium autofill. Fixture screenshots are saved under `writable/theme-contrast-preview/`.

Do not add new global page-specific selectors to `core/`. Add them to the appropriate page-family module and load that module only when the route requires it after its dependencies are verified.
