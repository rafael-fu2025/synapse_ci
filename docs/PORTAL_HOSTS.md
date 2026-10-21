# Workstation hosts and role-based navigation

## Three independent boundaries

1. Hostname selects the workspace. It never grants a role or permission.
2. Active database identity, role grants, tenant and record ownership authorize access.
3. Canonical navigation moves between workspaces; it does not copy a session or forward writes.

`Config\PortalSites` is the explicit hostname map. Keep deployment hosts in `App::$allowedHostnames` too. No `clinic.*` wildcard detection or `Host`-derived arbitrary redirect target is used. Ports, configured application base paths and `index.php` are preserved by the canonical URL helper.

## Supported hosts

| Workspace | Development | University |
| --- | --- | --- |
| Main/personal | synapse.localhost | synapse.foundationu.com |
| Clinic | clinic.localhost | clinic.foundationu.com |
| Guidance | guidance.localhost | guidance.foundationu.com |
| Facilities | bmg.localhost | bmg.foundationu.com |

`facilities.localhost` is an explicit BMG alias. Plain `localhost` retains the existing single-host development mode: `/clinic`, `/guidance`, and `/facilities` paths remain usable without switching origins. Unmapped hostnames do not acquire department context merely by beginning with a department name. Deployment web servers must independently reject unapproved Host headers and configure DNS/TLS for approved hosts; this feature is not a replacement for that infrastructure.

The expected local name is `clinic.localhost`, not `clinic.lolahost`. The development server must listen on the requested port; production hosts require appropriate web-server virtual hosts and certificates. No DNS, hosts-file, TLS, proxy, `.env`, or server settings are changed by this feature.

## Host compatibility

- Clinic: clinic_admin, clinic_staff, superadmin.
- Guidance: guidance_admin, guidance_supervisor, counsellor, superadmin.
- Facilities: bmg_admin, facilities_op, superadmin.
- Main: all supported roles. Workstation staff can use their personal portal; audit/report roles retain their existing guarded dashboards.

The sidebar intersects role membership with the dedicated host context. A Clinic host cannot make Clinic links visible to a student. A superadmin on Clinic sees Clinic workstation modules, not Guidance/Facilities modules. The header workspace switcher is shown only on dedicated hosts, where it changes which workstation modules the sidebar exposes. In plain localhost/path mode the sidebar already lists every role-authorized workstation, so the host switcher is deliberately hidden as redundant; personal, governance and appearance links remain in the same sidebar. Shared personal/governance/appearance destinations use main-host links and retain their existing permissions. In plain localhost/path mode all role-authorized workstation sections remain available, preserving the development fallback.

## Login and root landing

- Anonymous login is branded for the selected configured host.
- A compatible authenticated user opening login receives the same-origin sign-in landing. Opening the root retains the role's authorized workspace routing.
- Valid credentials submitted on an incompatible workstation host produce a 403 access notice with links to the correct workstation/personal portal. The newly established wrong-host authentication is cleared. Passwords or session credentials are never passed in a redirect URL.
- An already authenticated incompatible identity sees the access notice rather than bouncing through an unauthorized dashboard.
- Main-host workstation staff login finishes on the personal portal. The user can then choose the canonical workstation using the banner/header links. The form response never redirects across origins: Chromium would otherwise block it under `form-action 'self'`. Audit/report/superadmin retain their main dashboards; students/employees use the personal portal. Department-host sign-in and the Facilities alias stay on their current origin, as does plain-localhost/path mode. CSP and host-only cookie scope are not widened.

## Wrong-workspace requests

`PortalHostFilter` is registered after authentication/CSRF and before the legacy module boundary on protected application routes. Existing module/domain authorization remains in force.

- A normal GET to another workspace redirects only when the actor is authorized for the target workspace. Query values are safely encoded.
- Cross-workspace POST/PUT/PATCH/DELETE and AJAX/HTMX requests return 403 with a configured `portal_url`; there is no redirect or mutation replay. A failed CSRF check may reject a mutation earlier.
- A role unauthorized for the target department receives 403, not a redirect suggesting access.
- Shared notification and lookup endpoints remain guarded by their existing ownership/service rules. Device API authentication is unchanged.
- Authentication endpoints, including logout, continue to operate locally on the current host.

## Sessions and navigation

The session-cookie domain remains empty (host-only). Do not set a shared `.localhost` or university-wide cookie domain to simulate SSO. Cookies are scoped by hostname, not port.

Signing into one host does not sign into another; an authorized workspace switch can require a second sign-in. Logout ends the current host's session, not every host's session. This is not SSO or global logout. Password/auth-version revocation still affects the corresponding account's sessions through existing database checks.

The progressive single-page feel remains inside a workspace. Switching workspace hosts is an ordinary cross-origin page load. The explicit switcher opts out of progressive navigation; cross-origin links elsewhere retain the browser's normal behavior.

## Validation

```sh
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/feature/PortalHostPolicyTest.php
node --test tests/browser/portal-hosts.test.cjs
node --test --test-name-pattern="sidebar" tests/browser/modal-forms.test.cjs
```

The PHP tests use private in-memory SQLite, actual identity refresh, the role/host matrix, direct controller checks, and real route/filter dispatch. They cover wrong-host login cleanup, rejected cross-host mutations, AJAX handling, canonical query/base-path/index/port preservation, the Facilities alias and invalid destination strings.

Browser tests use production shell templates/assets on a disposable loopback fixture server. They cover all 12 roles on all four mapped development hosts (48 combinations), switcher links and browser host-only cookie behavior. The fixture sign-in cookie is intentionally test-only; it does not claim to exercise live CodeIgniter session storage. Live account/subdomain verification requires a running application server, DNS resolution and deployment configuration.
