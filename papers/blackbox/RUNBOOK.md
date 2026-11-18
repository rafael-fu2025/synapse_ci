# Black-box execution runbook

## Safety boundary

Run the current catalog only against a new disposable development database created for the run. Never point the application, CLI, browser, mobile app, Workerman, worker, firmware simulator, MIS client, AI provider, push delivery, or cleanup command at an operational/staging database containing real people or live services.

Prohibited:

- using an operational database, copied production database, live MIS directory, live AI account, real patient/student/counseling data, real push audience, or installed BMG motor as a convenience;
- running historical scripts from `writable/`, unreviewed one-off SQL/PHP/Python/browser scripts, unsafe “repair” scripts, or destructive commands against any non-disposable environment;
- pasting passwords, API/device/push tokens, cookies, encryption/signing keys, raw provider payloads, or personal/clinical data into commands, logs, screenshots, issues, commits, or run summaries;
- treating an automated test, code read, old tracker result, simulated acknowledgement, or incomplete campaign as a manual PASS.

If the environment cannot prove it is disposable, stop and record BLOCKED.

## 1. Pin and identify the run

1. Check out target application commit `884b9f0d647b9b7deb0085ac6af8d4520a269c53` in an isolated worktree.
2. Record repository status, exact commit, suite version `2026.11-draft.1`, operating system, PHP/Node/Java/Android/browser/app versions, tester/timezone, and configuration **names/status only** in the private manifest. Never record secret values.
3. Choose a unique run ID and private writable root according to `EVIDENCE.md`.
4. Copy no historical raw evidence into the run. Consult the frozen archive only for historical context.

## 2. Provision a disposable development database

1. Create a new empty database/schema with a run-specific name; grant a run-specific local database principal only that database.
2. Configure development environment and dedicated encryption/session/test keys generated for this run. Keep `.env` and values private/out of evidence.
3. Run application migrations normally against the empty DB. Do not use migration-upgrade/backlog commands against an existing database as a shortcut.
4. Seed only catalog/reference data required by the application and reviewed for the target commit. Record seeder/migration class names and resulting schema version/counts, not secret/generated values.
5. Take a baseline schema/data manifest or disposable snapshot for reset. Do not take or restore a production dump.

### Migration and seeding boundary

- Migrations establish schema only. A successful migration does not establish domain fixtures, roles policy, feature approval, or PASS.
- Seeders may replace permission mappings or install catalogs; review their behavior before running. Run them only on the disposable DB.
- The 15 synthetic account command creates identities/profiles only. It does **not** create the domain state needed by cases: appointments/availability/queues/encounters/inventory, Guidance clients/cases/sessions/intake/safety/governance/forms/episodes/call slips/MI/evaluations, referrals, Teams tasks/calendar/chat, BMG units/batches/devices, AI snapshots/jobs/drafts, notifications/audiences, audit-volume/tamper clones, or responsive long-content fixtures. Build those explicitly in the disposable DB through reviewed seed fixtures or normal UI/API operations.
- Never infer missing domain setup from a blank page; record BLOCKED until the stated precondition is built.

## 3. Create the 15 fixture accounts

Use the development-only `synapse:test-accounts` command in its read-only preflight first. Explicit creation is permitted only after the selected tenant is confirmed disposable. Supply/reset passwords through the documented private mechanism (piped standard input where required), never as a command argument or artifact.

Expected synthetic roles/accounts are:

1. superadmin;
2. clinic admin;
3. clinic staff A;
4. clinic staff B;
5. Guidance admin;
6. Guidance supervisor;
7. counselor A;
8. counselor B;
9. BMG admin;
10. facilities operator A;
11. facilities operator B;
12. audit reader;
13. report viewer;
14. student;
15. employee.

The matrix intentionally lacks some useful variants (for example clinic physician, clinic intern, extra students/employees, inactive/deleted/unlinked users, multiple tenants, and distinct authority signers). Create additional **synthetic** accounts only when a case requires them, document their role/purpose in the private manifest, and do not resolve CD-001/CD-002 by silently changing the approved fixture matrix.

## 4. Build domain fixtures and deterministic time

1. Use at least two disposable tenants for isolation cases and overlapping numeric IDs/canary values that are synthetic and visibly different.
2. Use `Asia/Manila` as the business-time reference. Record both RFC3339 timestamp and timezone in the manifest. Freeze/inject the supported scheduling/AI clocks where available; otherwise choose dates relative to the run and document exact Manila boundaries.
3. Build each case’s preconditions before execution and record only fixture aliases/IDs—not personal-looking data. Use reserved `.invalid` addresses and obvious `SYNAPSE-TEST-*` identifiers.
4. For race/idempotency cases, use reviewed test harnesses or two interactive sessions. Never improvise a script in an untrusted/historical directory.
5. Take reset points between destructive groups (audit tamper clone, role/session changes, token replay, AI retention, BMG archival).

## 5. Dedicated hosts and processes

Use dedicated loopback/test hosts matching the application’s host-boundary configuration for:

- main personal portal;
- clinic workstation;
- Guidance workstation;
- BMG/facilities workstation;
- any separate API/mobile base URL;
- a second isolated host/server profile for connection-isolation cases.

Map only to loopback/test addresses. Confirm cookies, origin allowlists, CORS/CSP, WebSocket URL and redirects do not point to operational hosts. Use separate browser profiles/containers for actors and never reuse a historical profile or cookies.

Start one isolated Workerman realtime process plus its internal publisher listener using generated test signing/publish keys and exact test origins. Record ports/PIDs/status without keys. Keep worker count at the supported single-process model. Verify polling behavior before and after stopping/restarting it; stop it after the run.

Run AI dispatch or workflow-notification workers only for cases that require them and only after gates below are satisfied. A web server alone does not imply a worker is scheduled.

## 6. Gate external and hazardous campaigns

These campaigns are opt-in. Leave their flags disabled unless every listed condition is documented in the private run manifest.

### MIS

Default to the fake MIS client with deterministic success/failure payloads. A live MIS campaign requires data-owner approval, approved university network, dedicated test directory/tenant, no real personal payload in evidence, bounded operation, and rollback plan. If unavailable, mark AD-005 BLOCKED; never substitute operational synchronization.

### AI

Default to a local synthetic provider that records only call count/hash-safe metadata. Before any dispatch: approved test tenant/unit/revision/governance flags, encrypted store ready, test key, request/retention budgets, eligible synthetic aggregates, worker explicitly controlled, and cleanup/purge plan. Never send patient charts, notes, small cells, credentials, or real data. Ambiguous outcomes are not replayed.

### BMG hardware / USB

Bookkeeping cases run with hardware disabled. Hardware cases require a simulator or physically isolated approved bench, written safety owner approval, `facilities.hardware.manage`, explicit hardware/build/flash flags only for the campaign, current reviewed firmware/toolchain, no installed production motor/load, lockout/isolation confirmation, one-time test device credential, and observer/stop procedure. Device acknowledgement/run reports remain claims. Never run unsafe live scripts or flash an operational controller.

### Push and mobile devices

Use emulator/test device and a dedicated test Firebase/APNs/deployment project/audience. Scoped LAN push requires capability handshake, unique test deployment/installation/registration and test API family. Do not register a personal/production phone token; do not capture raw token. Revoke and clear native/keychain scope after each account/server test.

### Guidance mobile API

There is no native UI in the target app. Run GD-API cases only after CD-006 and applicable self-referral/MI gate decisions authorize a direct API campaign. Do not claim native parity from API-only execution.

### Rollout flags

Record each flag/owner/gate as enabled/disabled (never secret value), approval, scope and rollback. Unapproved/default-disabled behavior remains CONDITIONAL/BLOCKED; do not turn flags on merely to make a case pass.

## 7. Execute and record

1. Start each case at `NOT_RUN`; copy its ID/title/lifecycle/source commit into the private manifest.
2. Verify actor, host, grants, fixture alias, clock, and stated preconditions.
3. Perform numbered observable steps exactly. Record HTTP/status/UI/database observations through the approved evidence layout.
4. Exercise Expected and Negative/non-disclosure assertions. A successful happy path cannot PASS if isolation, redaction, or failure behavior was not checked.
5. Run Cleanup immediately. If cleanup fails, stop related cases and remediate only within the disposable environment.
6. Set the run result in the private manifest/summary: PASS, FAIL, BLOCKED, NOT_RUN, or NOT_IN_RUN. Use NOT_IN_RUN only when the approved campaign scope excludes an otherwise catalogued conditional case; use BLOCKED when execution was attempted but an evidenced prerequisite was unavailable or unsafe. Do not edit canonical case verdicts by hand as a substitute for an execution ingestion process.
7. Automated tests may be run as corroboration after the black-box observation; record exact command/result, but do not promote verdict from tests alone.

## 8. Cleanup and retirement

1. Disable AI/BMG/push/realtime/refresh/Guidance rollout flags and stop all web, Workerman, AI, workflow, mock-provider, simulator, tunnel, browser-driver, and mobile-debug processes.
2. Revoke/delete synthetic API refresh families, push activations, BMG device credentials, provider/MIS test credentials, sessions/cookies, private firmware builds and downloaded exports.
3. Clear emulator/device app data/keychain/notification permission as appropriate; remove host/profile mappings that are run-specific.
4. Verify no pending AI jobs, BMG commands, background writes, or active test workers remain.
5. Export only the sanitized summary/manifest subset allowed by `EVIDENCE.md`.
6. Drop/retire the entire disposable database and its principal. Prefer whole-DB destruction over trying to clean individual clinical/counseling records.
7. Delete raw evidence when its approved retention expires. Confirm no raw evidence, secrets, personal data, dumps, browser profiles, or writable artifacts were added to Git.

If cleanup or database retirement cannot be proven, the run is incomplete and must not support certification.
