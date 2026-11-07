# Bootstrap distribution

These are the unmodified Bootstrap **5.3.3** runtime files already used by the
application views and isolated browser fixtures. They are tracked explicitly so
a clean checkout does not depend on ignored local vendor assets. No network
fetch or CDN fallback is required at runtime or during browser tests.

Upstream: <https://github.com/twbs/bootstrap/tree/v5.3.3>

| File | SHA-256 |
| --- | --- |
| `js/bootstrap.bundle.min.js` | `0833b2e9c3a26c258476c46266e6877fc75218625162e0460be9a3a098a61c6c` |
| `css/bootstrap.min.css` | `3c8f27e6009ccfd710a905e6dcf12d0ee3c6f2ac7da05b0572d3e0d12e736fc8` |

Both files were byte-compared with the tagged upstream distributions. Keep
version changes deliberate and re-run the real-view browser regressions.

Bootstrap is MIT licensed; see `LICENSE`. The JavaScript bundle includes Popper
2.11.8, also MIT licensed; see `LICENSE-POPPER` and
<https://github.com/floating-ui/floating-ui/blob/v2.11.8/LICENSE.md>.
