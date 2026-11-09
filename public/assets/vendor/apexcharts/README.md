# ApexCharts dashboard runtime

The application already loaded this unmodified **ApexCharts 3.51.0** browser runtime.
It is allowlisted for clean-checkout dashboard tests and offline application use;
this change does not upgrade the dependency or alter its global API.

- License: MIT; attribution and conditions in `LICENSE`.
- Upstream tagged license: https://github.com/apexcharts/apexcharts.js/blob/v3.51.0/LICENSE
- Runtime SHA-256: `bd2d7c4b8cd11904c509ffcb262f5093f264cdb401171f66b2c5eed93dd6464d`.
- License SHA-256: `4db98d106f5a157d60b24d49a4852156542ef58e08f1034e8a3b6d63e53e2241`.

The runtime retains its upstream version/license header. Do not replace it with a
mock in acceptance tests. Dashboard text tables remain usable when scripts fail.
