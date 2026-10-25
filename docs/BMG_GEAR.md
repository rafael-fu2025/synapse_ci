# Local decorative BMG gear

## Safety contract

This is an **illustration, not a motor indicator or a batch progress gauge**.
Humus has no measured motor state, RPM, or batch-phase progress for this graphic.
Every instance has exactly this immutable visual:

```js
{
  mode: 'idle',
  speed: 0,
  progress: null,
  label: 'Illustration only — motor state and RPM are not measured.'
}
```

The loader sets that label as the wrapper's `title` and keeps the wrapper
`aria-hidden="true"`. Keep the card's actual facts and accessible name outside
this decorative wrapper. No rotation, hover boost, progress arc, state updates,
status/binding inference, polling, application endpoints, or hardware requests
are implemented. An active batch does not change the graphic. Reduced-motion
and ordinary users both get a still model.

## Integrated pages and reusable pieces

The drum overview and detail pages use NiceAdmin cards, badges, alerts, form
controls and navigation. `public/assets/css/bmg.css` now owns only page layout,
small text/layout helpers, and the illustration's fallback behavior rather than
a separate card/control theme.

- `app/Views/facilities/_drum_card.php` adapts Zynapse's gear → identity → facts →
  footer layout to the current saved unit, controller and schedule records.
- `app/Views/facilities/_gear.php` is the shared decorative graphic.
- `app/Views/facilities/_setup_steps.php` provides the detail-page setup checklist:
  configure drum, register controller, optionally commission USB safely, review
  schedule, deliberately enable only when authorized and physically verified.

Both overview and detail already load the classic loader using the application's
asset base URL. No React build or original Zynapse checkout is needed at runtime.
The setup checklist never changes permissions, feature gates, schedule intent or
motor operation. Batch progress and live RPM are not fabricated from saved data.

To reuse the graphic on another page, load:

```html
<script src="BASE_URL/assets/js/bmg-gear.js" defer></script>
```

Do not load the renderer directly. The loader imports its local sibling
`bmg-gear-renderer.js` only when a matching wrapper exists. It resolves that URL
from `document.currentScript.src`, so subdirectory deployments work without
hardcoding the site root. The default module is self-contained: no CDN, React,
font, texture, model-file or further JavaScript requests.

Use this markup (substitute the application's escaped asset base URL and an
integer seed; retain the existing fallback artwork):

```html
<div data-bmg-gear-host>
  <div class="bmg-gear" data-bmg-gear data-gear-seed="11" aria-hidden="true"
       title="Illustration only — motor state and RPM are not measured.">
    <img class="bmg-drum-art-light" src="BASE_URL/drum-maroon.png" alt="">
    <img class="bmg-drum-art-dark" src="BASE_URL/drum-whte.png" alt="">
    <canvas data-bmg-gear-canvas width="192" height="192" hidden></canvas>
  </div>
</div>
```

Give the wrapper a fixed/nonzero square CSS size, including before canvas
readiness. The renderer observes the **wrapper**, not the initially hidden
canvas. Each wrapper must contain its own canvas. Canvas backing resolution is
192×192; CSS may scale it to the card's existing artwork size. The seed changes
only the initial static angle, never speed or state.

Keep existing theme rules for the light/dark artwork. Required ready-state CSS
(or equivalent selectors scoped to the BMG stylesheet):

```css
.bmg-gear canvas { width: 100%; height: 100%; }
.bmg-gear canvas[hidden] { display: none; }
.bmg-gear[data-gear-ready] .bmg-drum-art-light,
.bmg-gear[data-gear-ready] .bmg-drum-art-dark { display: none; }
```

`data-gear-ready` is set on **`[data-bmg-gear]`**, only after its first successful
frame is copied. Until then, leave the correctly themed image visible. On load,
WebGL or context-loss failure, the loader removes that attribute and hides the
canvas, restoring the existing themed images. Do not hide fallback images based
merely on JS availability or script load. Include a canvas `[hidden]` rule so
other `display` declarations cannot accidentally reveal an empty canvas.

If custom routing requires it, put `data-renderer-url` on the script or a wrapper.
Wrapper configuration takes precedence, then script configuration, then the
sibling URL. Override URLs must be same-origin HTTP(S), without credentials.
Avoid per-card cache-busting URLs: imports are cached per resolved URL, including
failures, to prevent retries. The normal sibling URL is strongly preferred.

## Lifecycle and API

```js
window.SynapseBmgGear.init(root = document);
window.SynapseBmgGear.destroy(root = document);
```

Both methods are idempotent. `init` includes `root` itself if it is a wrapper,
plus matching descendants. It also prunes disconnected instances. The loader:

- initializes on DOM readiness and `synapse:page-load` (`event.detail.root`);
- destroys the old subtree on `synapse:before-swap` (`event.detail.root`);
- destroys everything on `pagehide` and reinitializes on `pageshow`, including
  BFCache restores;
- cancels pending lazy attachments if their subtree leaves before import finishes;
- observes direct DOM removals to release instances even outside SPA navigation;
- avoids duplicate listeners when the classic script is evaluated again.

For direct DOM insertions outside the normal page lifecycle, call `init(newRoot)`.
Removal is automatic; calling `destroy(root)` before removal is preferable.
The loader's removal observer disconnects when the last instance is removed.
A failed import is not automatically retried; reload after fixing deployment.

The renderer module exports:

```js
import { attachGear, ILLUSTRATION } from './bmg-gear-renderer.js';
const handle = attachGear(canvas, { seed: 11, onReady() {}, onFail() {} });
// handle === null: keep fallback art (2D or WebGL unavailable).
handle?.visual;    // the immutable ILLUSTRATION above
handle?.dispose(); // idempotent
```

There is intentionally **no `update`, `setBoost`, speed, progress or running-state
API**. `attachGear` is normally used only by the loader. Do not attach a second
handle to a canvas/wrapper already owned by the loader.

One shared 192×192 offscreen `WebGLRenderer` serves all instances. Each card owns
a regular 2D canvas; its frame is copied synchronously with `drawImage`. The
renderer schedules a single frame only for dirty visible instances, not a
recurring loop. IntersectionObserver skips offscreen/zero-size wrappers;
document visibility cancels pending rendering while the page is hidden and
resumes dirty rendering when it is shown. Theme changes mark frames dirty;
offscreen instances repaint on reentry. Explicit `html[data-theme="light|dark"]`
takes precedence, with legacy `html.dark`, `body.dark`, `body.dark-mode` and
`body[data-theme="dark"]` supported when no explicit root theme exists.

Last-instance disposal releases geometries, material, PMREM target, renderer,
WebGL context, RAF, intersection/theme observers and document visibility/context
listeners. Context loss restores every fallback and releases the shared state.
The loader retains no active rendering resources after destruction.

## Actual source reuse and assets

This is the procedural Three.js gear from the original Zynapse drum card, not
a replacement SVG or a GLB approximation. The exact tooth-outline and
`circle`/`buildGear` functions were extracted from the original TypeScript:

- `frontend/src/lib/gearModel.ts` — 14 trapezoidal teeth, root radius 0.8,
  tip radius 1;
- `frontend/src/components/facilities/gear/gearRenderer.ts` — beveled extruded
  plate, central bore, six lightening holes, raised annular hub, shared rendering;
- `frontend/src/components/facilities/GearGauge.tsx` and `DrumCard.tsx` were read
  for integration/lifecycle provenance, not shipped as React dependencies.

The port retains the original geometry dimensions, camera, tilt, studio
RoomEnvironment/PMREM, directional light, metallic material and idle palettes.
It intentionally omits the original batch-derived status model, angular easing,
hover boost, progress bezel and React component.

| Asset | Purpose |
| --- | --- |
| `public/assets/js/bmg-gear.js` | Small classic-script lazy loader and lifecycle adapter |
| `public/assets/js/bmg-gear-renderer.js` | Self-contained minified ESM bundle, about 575 KB uncompressed |
| `public/assets/vendor/bmg-gear/renderer-source.js` | Editable static shared renderer source |
| `public/assets/vendor/bmg-gear/gear-geometry.ts` | Extracted original procedural geometry |
| `public/assets/vendor/bmg-gear/build.cjs` | Reproducible local esbuild build with exact version checks |
| `public/assets/vendor/bmg-gear/THREE-LICENSE.txt` | Three.js MIT license copied from installed dependency |
| `public/assets/vendor/bmg-gear/PROVENANCE.json` | Original source SHA-256s, versions, permission caveat and output hash |

Three.js is pinned to **0.186.1**; build-time esbuild is pinned to **0.21.5**.
The bundle includes RoomEnvironment and the used Three.js runtime, with inline
legal comments and a license pointer. No npm runtime dependency installation is required in humus.
The original source checkout was only read and was not edited.

No project LICENSE was found at the Zynapse root or frontend root. Reuse was
explicitly requested by the user, but that is not a blanket third-party license:
confirm source-project redistribution permission before external distribution.
Do not apply Three.js's MIT license to the application-specific geometry code.

### Rebuild

Supply a directory containing the pinned `node_modules/three` and
`node_modules/esbuild`. This command uses the existing local source dependencies:

```sh
node public/assets/vendor/bmg-gear/build.cjs C:/Users/udtoh_lmtzs7k/zynapse/frontend
```

It only writes the humus bundle and its provenance hash. No source checkout is
required at runtime. Another local dependency directory with the exact pinned
versions can be used instead. Commit source, license, provenance and rebuilt
bundle together. Serve `.js` files with a JavaScript MIME type.

### Required parent `.gitignore` allowlist

The existing vendor rules ignore this new directory. This task deliberately
**does not edit `.gitignore`**. The parent must add these exact exceptions after
the existing `/public/assets/vendor/*` rule (and its existing exceptions):

```gitignore
!/public/assets/vendor/bmg-gear/
!/public/assets/vendor/bmg-gear/**
```

Without these exceptions the renderer bundle may be tracked but the editable
source, license and provenance will be omitted.

## Browser validation

Run with the repository's local Playwright tooling and installed Chromium:

```sh
node --test tests/browser/bmg-gear.test.cjs
```

Tests use an isolated, bounded local HTTP fixture matching the parent markup and
asset subdirectory deployment; no PHP application, hardware or application API
is started. Chromium uses SwiftShader for real headless WebGL. The real bundled
geometry must paint nontransparent pixels; WebGL tests fail rather than silently
passing if the configured browser cannot render it. Separate tests force WebGL
and module failures. Tests cover one context across multiple canvases, static
pixels under hover/status/progress input, reduced motion, themes, offscreen
pause, disposal/observer cleanup, context loss, SPA remounts, pagehide/pageshow,
async cancellation, image fallbacks, and absence of request/retry loops.

The fixture intentionally supplies parent CSS; it does not prove the parent PHP
views or stylesheet have been integrated. Verify index/detail markup and script
registration in the parent change before shipping.
