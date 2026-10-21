# Guidance question bank

## Purpose and access

Open **Guidance Workstation → Question Bank** (`/guidance/question-bank`), or use the **Question bank** link on the survey listing. The library reuses the existing `guidance.surveys.manage` permission and requires a refreshed `superadmin`, `guidance_admin`, or `guidance_supervisor` identity. No role grants are seeded or broadened. Ordinary counsellors, Clinic/Facilities staff, students and general employees cannot browse the bank or its catalog API.

The bank is tenant-scoped and Guidance-owned. Existing canonical-host, CSRF and no-store policies apply. Main-portal users switch to their authorized Guidance workstation; the hostname does not grant bank access.

## Manage reusable questions

1. Choose **Add bank question**.
2. Enter the question text, category, intended audience, answer type, default required flag and applicable choices.
3. Optionally enter an author review note covering intended use, sensitivity or licensing. Do not include personal or clinical case information.
4. Save. This creates an active entry with revision 1.
5. Use **Revise** to create a new revision; previous revisions remain readable through **History**.
6. Use **Archive** to remove an entry from new selections. Archiving preserves revision history and all existing survey copies. There is no delete or restore action in this initial version.

Supported types match the survey builder: text (answers up to 2,000 characters), single choice, multiple choice, and rating 1–5. Labels are limited to 500 characters; choice questions require 2–10 distinct options of up to 200 characters. Each entry has one category (80 characters), an intended audience (`all`, `students`, `employees`) and an optional author note (1,000 characters).

Search matches question text or category; a separate category filter is exact. Audience filters for students/employees also include questions intended for both. Archived entries have their own filter. Management and picker results are paginated, 20 per page.

## Add questions to a survey

1. Create or edit a **draft** survey.
2. Choose **Add from question bank**.
3. Search/filter and review the questions, choices, audience labels and author notes. Check individual questions or **Select this page**, then choose **Add selected questions**. **Add copy** remains available for one-at-a-time insertion.
4. Selections retain their question/revision snapshots across searches and pages within the current editor. A selected count and available-slot count are shown. **Clear selection** clears all pages; unchecking **Select this page** clears only the visible page. At most 20 bank questions can be selected at once.
5. Adding a batch replaces the initial blank question when appropriate; otherwise copies are appended in selection order. If the entire batch would exceed the survey's 20-question limit, **nothing is added** and the selection is retained for correction. There is no silent truncation or partial insertion.
6. Review the draft and save normally. Custom questions and bank copies can be mixed. Selection is editor-local memory, not persisted across closing/reloading the survey editor.

Exact copies retain a bank ID and revision beside their independent text/type/choices/required snapshot. The server verifies the claimed source revision against same-tenant library history; client-supplied source IDs are not trusted.

Editing a copy's text, type, required setting or choices automatically detaches that question's source reference. It becomes a custom survey question rather than falsely claiming to be an unchanged bank revision. This never edits the library entry. Each copy has its own choices array.

The bank's intended audience is **author guidance**, not automatic targeting or an authorization rule. Authors must review the survey audience, student targeting and privacy implications separately. Author notes are not copied into respondents' questions.

## Snapshot and version guarantees

- Creating, revising or archiving a bank entry does not update any existing survey or response row.
- Saved questions contain the complete question snapshot, not a live pointer used to render their text later.
- Bank edits append revision rows and advance the current revision. Application endpoints never update or delete old revision rows.
- Archived sources cannot be added to a new survey as bank references. A saved draft may retain the archived copies it already contains, including while editing other draft fields; it cannot multiply those archived references. Authors may explicitly edit/detach a copy into a custom question.
- Publishing retains the existing immutable-survey rules. Bank lookup is not needed to validate a stored snapshot at publication. Response encryption, eligibility, one-response restrictions and response-reader permissions are unchanged.
- Stale bank edit/archive forms return HTTP 409. All bank changes use tenant-first transactions and append metadata-only audit events (`guidance.question_bank.created`, `.revised`, `.archived`). Audit failure rolls back both revision and entry changes.
- Database administrators can still alter bank revision rows using privileged SQL; the application-level append-only workflow is not a claim of database-enforced question-content immutability. Audit history retains its separate existing protections.

## Clinical and reporting boundaries

An active library entry is selectable, not a certificate of clinical approval. The library does not validate screening instruments, provide diagnostic scoring, infer mental-health outcomes, or override instrument licensing/administration requirements. Sensitive questions need appropriate human review before reuse.

This phase does not include proposal/approval chains, multiple tags per entry, bulk import, automatic scoring, cross-workstation banks, or automatic cross-survey analytics. Results are not combined across revisions. Even identical questions do not alone establish comparability between different respondent populations or collection methods.

## Schema and installation

Migration: `2026-10-19-000006_CreateSurveyQuestionBank`.

New tables only:

- `survey_question_bank`: tenant, status, current revision and optimistic version.
- `survey_question_bank_revisions`: question snapshot, category, intended audience, author note and revision author/time.

Existing survey JSON, response tables, people, credentials and role grants are not rewritten. The forward-only migration refuses destructive rollback; archive instead of dropping history.

The targeted local installer defaults to read-only and is restricted to development MySQL `humus_db` on loopback:

```sh
php spark synapse:question-bank-install
php spark synapse:question-bank-install --apply
```

It uses a locked migration runner filtered to this one additive migration, never a downgrade or unrelated pending migration. The local installation was applied successfully on 2026-10-19. The pre-existing pending Guidance-numbering migration was left untouched. If DDL only partially succeeds, inspect state before retrying; do not drop populated history to force a successful reinstall.

Other deployments should review pending migrations and use their controlled migration process. Existing audit infrastructure must be installed and healthy. The bank and survey drafts require no new secret; publication/submission still require the existing private survey-encryption configuration. No private `.env` or keys were inspected or changed.

## Validation

```sh
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/feature/QuestionBankHttpTest.php tests/unit/SurveyQuestionTest.php tests/feature/SidebarRoleVisibilityTest.php tests/feature/PortalHostPolicyTest.php tests/feature/BackendRoleAuthorizationTest.php tests/feature/EmployeeAccessHttpTest.php tests/unit/AuditHashTest.php
node --test tests/browser/engagement.test.cjs tests/browser/survey-builder-state.test.cjs
```

Results: **246 PHP tests / 2,100 assertions**, **31 fixture browser tests** and **8 component-state tests** passed. The HTTP/database tests use private in-memory SQLite; browser fixtures use actual production templates/assets with synthetic HTTP responses. Coverage includes role/tenant restrictions, stale edits, CSRF, validation, audit rollback, snapshots in draft/published fixtures, preservation of existing response bytes, archived retention, forged source references, Unicode/literal rendering, and copy detachment. Browser coverage includes all pre-existing engagement flows plus the picker in light/dark mobile layouts, pagination/search selection retention, clean multi-question submission payloads, partial-page selection state, all-or-nothing capacity enforcement and retry after catalog failure. Component tests additionally verify the exact 20-question boundary, selection limits, clear/deselect behavior, independent choice arrays and source revisions. The multi-select change requires no schema, permission or stored-data updates.

Optional real local application test:

```sh
SYNAPSE_LIVE_QUESTION_BANK_TEST=1 node --test tests/browser/question-bank-live.test.cjs
```

**Passed against local MySQL and `guidance.localhost:8080`** using `test.guidance.admin`. It creates one unique, clearly marked QA bank entry, copies it into an unsaved survey, revises the source, confirms revision independence/custom detachment, archives the entry, and checks retained history. Cleanup archives the synthetic entry and intentionally retains revisions/audit events. No survey is saved or published, no response is collected and no notifications are sent. Without explicit opt-in the test is skipped.

Actual campus MIS, live survey publication/encryption, large-bank performance, and a full MySQL concurrency suite were not exercised by this phase. Existing protections for those workflows remain in place; fixture tests are not proof of deployment-wide readiness.
