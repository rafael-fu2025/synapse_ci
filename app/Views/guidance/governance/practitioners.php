<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-practitioners.css'), 'attr') ?>">
<?= $this->endSection() ?>

<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <a href="<?= esc(site_url('/guidance/governance/gates'), 'attr') ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-shield-check" aria-hidden="true"></i> Activation Gates
  </a>
  <a href="<?= esc(site_url('/guidance/cases'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-folder2-open" aria-hidden="true"></i> Counseling Cases
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$profiles               = $profiles ?? [];
$supervisions           = $supervisions ?? [];
$guidanceUsers          = $guidanceUsers ?? [];
$tiers                  = $tiers ?? ['counselor_associate', 'licensed_counselor', 'guidance_supervisor', 'qualified_assessor'];
$availableScopes        = $availableScopes ?? [];
$canManagePractitioners = !empty($canManagePractitioners);
$canReassign            = !empty($canReassign);
$hasManagement         = $canManagePractitioners || $canReassign;
$today                 = strtotime(date('Y-m-d'));
$profilesPagination = $profilesPagination ?? \App\Libraries\TablePage::forList([])->metadata(count($profiles), count($profiles));
$supervisionPagination = $supervisionPagination ?? \App\Libraries\TablePage::forList([])->metadata(count($supervisions), count($supervisions));
$perPage = (int) $profilesPagination['per_page'];
$profilesCount = (int) $profilesPagination['total'];
$supervisionCount = (int) $supervisionPagination['total'];
$paginationQuery = ['profiles_page' => (int) $profilesPagination['page'], 'supervision_page' => (int) $supervisionPagination['page']];

$tierLabel = static fn (string $tier): string => match ($tier) {
    'counselor_associate' => 'Counselor Associate',
    'licensed_counselor'  => 'Licensed Counselor',
    'guidance_supervisor' => 'Guidance Supervisor',
    'qualified_assessor'  => 'Qualified Assessor',
    default               => ucwords(str_replace('_', ' ', $tier)),
};

$standingColor = static fn (string $standing): string => match ($standing) {
    'active'    => 'ce-status-emerald',
    'suspended' => 'ce-status-crimson',
    'lapsed'    => 'ce-status-amber',
    default     => 'ce-status-slate',
};
?>

<section class="guidance-ui guidance-practitioners-workspace ce-workspace gpr-workspace"
         aria-label="Practitioner credentials and supervision workspace"
         data-guidance-registry="practitioners"
         data-live-filters
         data-filter-key="guidance-practitioners"
         data-filter-url="<?= esc(site_url('guidance/governance/practitioners'), 'attr') ?>"
         data-filter-page-params="profiles_page supervision_page"
         data-refresh-owner="guidance.governance.practitioners"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div class="ce-registry-toolbar ce-registry-context-bar">
    <div class="ce-registry-context">
      <i class="bi bi-shield-lock" aria-hidden="true"></i>
      <span><strong>Credential governance</strong> — Clinical eligibility depends on profile activity, professional standing, current credentials, service scopes, and permissions. Counselor associates require supervisor co-sign to finalize clinical records.</span>
    </div>
    <form method="get" action="<?= esc(site_url('guidance/governance/practitioners'), 'attr') ?>" data-filter-form class="ce-registry-page-size">
      <input type="hidden" name="profiles_page" value="<?= (int) $profilesPagination['page'] ?>">
      <input type="hidden" name="supervision_page" value="<?= (int) $supervisionPagination['page'] ?>">
      <label for="practitionersPerPage" class="form-label mb-0">Rows per page</label>
      <select id="practitionersPerPage" name="per_page" class="form-select form-select-sm w-auto" onchange="this.form.elements.profiles_page.value='1';this.form.elements.supervision_page.value='1'">
        <?php foreach ([20, 25, 50, 100] as $size): ?>
          <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-outline-secondary btn-sm">Apply</button>
    </form>
  </div>
  <p class="visually-hidden" data-filter-status role="status" aria-live="polite">Showing <?= $profilesCount ?> practitioner profiles and <?= $supervisionCount ?> active supervision assignments.</p>
  <div class="alert ce-notice ce-notice-crimson" data-banner-dismiss="off" data-filter-error role="alert" hidden>
    <div class="ce-notice-body">
      <i class="bi bi-exclamation-octagon ce-notice-icon" aria-hidden="true"></i>
      <span data-filter-error-text></span>
    </div>
    <button type="button" class="btn btn-outline-danger btn-sm flex-shrink-0" data-filter-retry>Retry</button>
  </div>
  <div class="gpr-workspace-content" data-filter-region="workspace" data-refresh-region="workspace" data-refresh-heading tabindex="-1">

    <?php if (!$hasManagement): ?>
      <div class="gpr-readonly-note">
        <i class="bi bi-eye" aria-hidden="true"></i>
        <span>Read-only view. Credential updates and supervision assignments require the corresponding governance permissions.</span>
      </div>
    <?php endif; ?>

    <div class="gpr-grid <?= $hasManagement ? 'gpr-grid-managed' : '' ?>">
      <div class="gpr-ledgers">

        <!-- Practitioner Credentials Ledger -->
        <div class="ce-card gpr-ledger" aria-labelledby="practitioner-ledger-heading">
          <div class="ce-card-header">
            <div class="gpr-heading">
              <h2 class="ce-card-title" id="practitioner-ledger-heading"><i class="bi bi-person-badge" aria-hidden="true"></i> Registered Practitioner Credentials</h2>
              <p class="gpr-caption">Credential records and assigned service capabilities.</p>
            </div>
            <span class="ce-tag gpr-count"><span class="ce-mono"><?= $profilesCount ?></span> <?= $profilesCount === 1 ? 'Profile' : 'Profiles' ?></span>
          </div>
          <div class="table-responsive gpr-table-wrap" data-mobile-cards="false">
            <table class="table table-hover align-middle ce-table ce-table-mobile gpr-table gpr-profiles-table" aria-labelledby="practitioner-ledger-heading">
              <colgroup><col><col><col><col><col></colgroup>
              <thead>
                <tr>
                  <th scope="col">Practitioner</th>
                  <th scope="col">Credential Tier</th>
                  <th scope="col">License &amp; Expiry</th>
                  <th scope="col">Standing</th>
                  <th scope="col">Service Scopes</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($profiles === []): ?>
                  <tr>
                    <td colspan="5" class="ce-empty-row">
                      <div class="gpr-empty-state">
                        <i class="bi bi-person-badge" aria-hidden="true"></i>
                        <strong>No practitioner profiles registered</strong>
                        <span><?= $canManagePractitioners ? 'Use the registration form to record credentials for a Guidance staff account.' : 'An authorized credential manager can register practitioner profiles for this tenant.' ?></span>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($profiles as $profile): ?>
                    <?php
                    $fullName = trim((string) ($profile['first_name'] ?? '') . ' ' . (string) ($profile['last_name'] ?? ''));
                    $displayName = $fullName !== '' ? $fullName : (string) ($profile['username'] ?? 'Guidance Staff');
                    $scopes = is_array($profile['service_scope'] ?? null) ? $profile['service_scope'] : [];
                    $standing = (string) ($profile['professional_standing'] ?? 'inactive');
                    $isActive = (int) ($profile['is_active'] ?? 0) === 1;
                    $expiry = !empty($profile['credential_expiry']) ? strtotime((string) $profile['credential_expiry']) : false;
                    $isExpired = $expiry !== false && $expiry < $today;
                    ?>
                    <tr>
                      <td data-label="Practitioner">
                        <div class="gpr-person-name"><?= esc($displayName) ?></div>
                        <div class="gpr-person-meta">
                          <span class="ce-mono">@<?= esc($profile['username'] ?? '') ?></span>
                          <span><?= esc(ucwords(str_replace('_', ' ', (string) ($profile['user_role'] ?? 'staff')))) ?></span>
                        </div>
                        <?php if (!empty($profile['mis_id'])): ?>
                          <div class="gpr-meta ce-mono">ID: <?= esc($profile['mis_id']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td data-label="Credential Tier">
                        <span class="ce-tag gpr-tier"><?= esc($tierLabel((string) ($profile['credential_tier'] ?? ''))) ?></span>
                      </td>
                      <td data-label="License &amp; Expiry">
                        <?php if (!empty($profile['license_number'])): ?>
                          <div class="gpr-license ce-mono"><?= esc($profile['license_number']) ?></div>
                        <?php else: ?>
                          <div class="gpr-meta">No license #</div>
                        <?php endif; ?>
                        <div class="gpr-meta">
                          <?php if ($expiry !== false): ?>
                            <span>Exp: <time class="ce-mono" datetime="<?= esc(date('Y-m-d', $expiry), 'attr') ?>"><?= esc(date('M j, Y', $expiry)) ?></time></span>
                          <?php else: ?>
                            <span><?= !empty($profile['credential_expiry']) ? 'Expiry unavailable' : 'No expiry set' ?></span>
                          <?php endif; ?>
                        </div>
                        <?php if ($isExpired): ?>
                          <span class="ce-status-dot ce-status-crimson gpr-expired">Expired</span>
                        <?php endif; ?>
                      </td>
                      <td data-label="Standing">
                        <div class="gpr-standing">
                          <span class="ce-status-dot <?= $standingColor($standing) ?>"><?= esc($standing === 'active' ? 'Active standing' : ucwords(str_replace('_', ' ', $standing))) ?></span>
                          <span class="gpr-meta <?= $isActive ? '' : 'gpr-inactive' ?>"><?= $isActive ? 'Profile active' : 'Profile inactive' ?></span>
                        </div>
                      </td>
                      <td data-label="Service Scopes">
                        <?php if ($scopes === []): ?>
                          <span class="gpr-meta">None assigned</span>
                        <?php else: ?>
                          <div class="gpr-scope-tags">
                            <?php foreach ($scopes as $scope): ?>
                              <span class="ce-tag" title="<?= esc((string) $scope, 'attr') ?>"><?= esc($availableScopes[$scope] ?? ucwords(str_replace('_', ' ', (string) $scope))) ?></span>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?= view('partials/table_pagination', [
              'pagination' => $profilesPagination,
              'paginationPageParam' => 'profiles_page',
              'paginationExtraQuery' => $paginationQuery,
              'baseUrl' => site_url('guidance/governance/practitioners'),
              'paginationLabel' => 'Practitioner profile pages',
              'paginationLiveFilters' => true,
              'paginationCardFooter' => true,
          ]) ?>
        </div>

        <!-- Active Supervision Ledger -->
        <div class="ce-card gpr-ledger" aria-labelledby="supervision-ledger-heading">
          <div class="ce-card-header">
            <div class="gpr-heading">
              <h2 class="ce-card-title" id="supervision-ledger-heading"><i class="bi bi-diagram-3" aria-hidden="true"></i> Active Supervision Assignments</h2>
              <p class="gpr-caption">Current associate-supervisor relationships and co-sign policy.</p>
            </div>
            <span class="ce-tag gpr-count"><span class="ce-mono"><?= $supervisionCount ?></span> Active</span>
          </div>
          <div class="table-responsive gpr-table-wrap" data-mobile-cards="false">
            <table class="table table-hover align-middle ce-table ce-table-mobile gpr-table gpr-supervisions-table" aria-labelledby="supervision-ledger-heading">
              <colgroup><col><col><col><col></colgroup>
              <thead>
                <tr>
                  <th scope="col">Associate</th>
                  <th scope="col">Assigned Supervisor</th>
                  <th scope="col">Co-Sign Policy</th>
                  <th scope="col">Starts At</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($supervisions === []): ?>
                  <tr>
                    <td colspan="4" class="ce-empty-row">
                      <div class="gpr-empty-state">
                        <i class="bi bi-diagram-3" aria-hidden="true"></i>
                        <strong>No active supervision assignments</strong>
                        <span><?= $canReassign ? 'Use the assignment form to record a supervisor for an associate.' : 'An authorized staff member can assign supervision for this tenant.' ?></span>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($supervisions as $sup): ?>
                    <?php
                    $assocName = trim((string) ($sup['associate_first_name'] ?? '') . ' ' . (string) ($sup['associate_last_name'] ?? ''));
                    $supName = trim((string) ($sup['supervisor_first_name'] ?? '') . ' ' . (string) ($sup['supervisor_last_name'] ?? ''));
                    $startsAt = !empty($sup['starts_at']) ? strtotime((string) $sup['starts_at']) : false;
                    ?>
                    <tr>
                      <td data-label="Associate">
                        <div class="gpr-person-name"><?= esc($assocName !== '' ? $assocName : $sup['associate_username']) ?></div>
                        <div class="gpr-meta ce-mono">@<?= esc($sup['associate_username']) ?></div>
                      </td>
                      <td data-label="Assigned Supervisor">
                        <div class="gpr-person-name"><?= esc($supName !== '' ? $supName : $sup['supervisor_username']) ?></div>
                        <div class="gpr-meta ce-mono">@<?= esc($sup['supervisor_username']) ?></div>
                      </td>
                      <td data-label="Co-Sign Policy">
                        <?php if ((int) ($sup['co_sign_required'] ?? 1) === 1): ?>
                          <span class="ce-status-dot ce-status-amber">Co-Sign Required</span>
                        <?php else: ?>
                          <span class="ce-status-dot ce-status-slate">Consultative Only</span>
                        <?php endif; ?>
                      </td>
                      <td data-label="Starts At">
                        <?php if ($startsAt !== false): ?>
                          <time class="gpr-start-date" datetime="<?= esc(date('Y-m-d\TH:i:s', $startsAt), 'attr') ?>">
                            <span class="ce-mono"><?= esc(date('M j, Y', $startsAt)) ?></span>
                            <span class="gpr-meta ce-mono"><?= esc(date('g:i A', $startsAt)) ?></span>
                          </time>
                        <?php else: ?>
                          <span class="gpr-meta">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?= view('partials/table_pagination', [
              'pagination' => $supervisionPagination,
              'paginationPageParam' => 'supervision_page',
              'paginationExtraQuery' => $paginationQuery,
              'baseUrl' => site_url('guidance/governance/practitioners'),
              'paginationLabel' => 'Active supervision pages',
              'paginationLiveFilters' => true,
              'paginationCardFooter' => true,
          ]) ?>
        </div>

      </div>

      <?php if ($hasManagement): ?>
        <div class="gpr-management">

          <?php if ($canManagePractitioners): ?>
            <div class="ce-card gpr-action-card" aria-labelledby="practitioner-form-heading">
              <div class="ce-card-header">
                <h2 class="ce-card-title" id="practitioner-form-heading"><i class="bi bi-person-plus" aria-hidden="true"></i> Register / Update Practitioner</h2>
              </div>
              <div class="ce-card-body">
                <form action="<?= esc(site_url('/guidance/governance/practitioners/register'), 'attr') ?>" method="POST" class="gpr-form" data-background-submit>
                  <?= csrf_field() ?>

                  <fieldset class="gpr-form-section">
                    <legend class="gpr-section-title">Staff account</legend>
                    <div>
                      <label for="prac-user-id" class="form-label">Guidance Staff Account <span class="gpr-required" aria-hidden="true">*</span></label>
                      <select id="prac-user-id" name="user_id" class="form-select form-select-sm" data-searchable required>
                        <option value="">Select user...</option>
                        <?php foreach ($guidanceUsers as $gu): ?>
                          <?php $gName = trim((string) ($gu['first_name'] ?? '') . ' ' . (string) ($gu['last_name'] ?? '')); ?>
                          <option value="<?= (int) $gu['id'] ?>">
                            <?= esc(($gName !== '' ? $gName : $gu['username']) . ' (@' . $gu['username'] . ' · ' . $gu['role'] . ')') ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </fieldset>

                  <fieldset class="gpr-form-section">
                    <legend class="gpr-section-title">Credential details</legend>
                    <div>
                      <label for="prac-tier" class="form-label">Credential Tier <span class="gpr-required" aria-hidden="true">*</span></label>
                      <select id="prac-tier" name="credential_tier" class="form-select form-select-sm" required>
                        <?php foreach ($tiers as $t): ?>
                          <option value="<?= esc($t, 'attr') ?>"><?= esc($tierLabel($t)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="gpr-credential-fields">
                      <div>
                        <label for="prac-license" class="form-label">PRC / License #</label>
                        <input type="text" id="prac-license" name="license_number" class="form-control form-control-sm ce-mono" maxlength="64" placeholder="Optional for associate">
                      </div>
                      <div>
                        <label for="prac-expiry-display" class="form-label">Credential Expiry</label>
                        <input type="text" id="prac-expiry-display" class="form-control form-control-sm" data-datepicker data-target="#prac-expiry" placeholder="DD/MM/YYYY" autocomplete="off">
                        <input type="hidden" id="prac-expiry" name="credential_expiry" value="">
                      </div>
                    </div>
                  </fieldset>

                  <fieldset class="gpr-form-section">
                    <legend class="gpr-section-title">Authorized Service Scopes</legend>
                    <div class="gpr-scope-grid">
                      <?php foreach ($availableScopes as $scopeKey => $scopeLabel): ?>
                        <div class="form-check gpr-scope-option">
                          <input class="form-check-input" type="checkbox" name="service_scope[]" value="<?= esc($scopeKey, 'attr') ?>" id="scope-<?= esc($scopeKey, 'attr') ?>">
                          <label class="form-check-label" for="scope-<?= esc($scopeKey, 'attr') ?>"><?= esc($scopeLabel) ?></label>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </fieldset>

                  <p class="gpr-save-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Saving replaces the selected account's credential details and service scopes, and marks its profile and professional standing active. Enter the complete values before saving.</p>
                  <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-save" aria-hidden="true"></i> Save Practitioner Profile
                  </button>
                </form>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($canReassign): ?>
            <div class="ce-card gpr-action-card" aria-labelledby="supervision-form-heading">
              <div class="ce-card-header">
                <h2 class="ce-card-title" id="supervision-form-heading"><i class="bi bi-diagram-3" aria-hidden="true"></i> Assign Supervision</h2>
              </div>
              <div class="ce-card-body">
                <form action="<?= esc(site_url('/guidance/governance/practitioners/supervision'), 'attr') ?>"
                      method="POST"
                      class="gpr-form"
                      data-background-submit
                      data-confirm="Assign this supervisor to the selected associate? Any previous active supervision for the associate will be closed."
                      data-confirm-title="Assign Supervision"
                      data-confirm-label="Save Assignment"
                      data-confirm-tone="primary">
                  <?= csrf_field() ?>

                  <fieldset class="gpr-form-section">
                    <legend class="gpr-section-title">Supervision relationship</legend>
                    <div>
                      <label for="sup-associate" class="form-label">Counselor Associate <span class="gpr-required" aria-hidden="true">*</span></label>
                      <select id="sup-associate" name="associate_user_id" class="form-select form-select-sm" data-searchable required>
                        <option value="">Select associate...</option>
                        <?php foreach ($guidanceUsers as $gu): ?>
                          <?php $gName = trim((string) ($gu['first_name'] ?? '') . ' ' . (string) ($gu['last_name'] ?? '')); ?>
                          <option value="<?= (int) $gu['id'] ?>">
                            <?= esc(($gName !== '' ? $gName : $gu['username']) . ' (@' . $gu['username'] . ')') ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label for="sup-supervisor" class="form-label">Clinical Supervisor <span class="gpr-required" aria-hidden="true">*</span></label>
                      <select id="sup-supervisor" name="supervisor_user_id" class="form-select form-select-sm" data-searchable required>
                        <option value="">Select supervisor...</option>
                        <?php foreach ($guidanceUsers as $gu): ?>
                          <?php $gName = trim((string) ($gu['first_name'] ?? '') . ' ' . (string) ($gu['last_name'] ?? '')); ?>
                          <option value="<?= (int) $gu['id'] ?>">
                            <?= esc(($gName !== '' ? $gName : $gu['username']) . ' (@' . $gu['username'] . ' · ' . $gu['role'] . ')') ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </fieldset>

                  <fieldset class="gpr-form-section">
                    <legend class="gpr-section-title">Co-sign policy</legend>
                    <div class="form-check gpr-cosign-option">
                      <input type="hidden" name="co_sign_required" value="0">
                      <input class="form-check-input" type="checkbox" id="sup-cosign" name="co_sign_required" value="1" checked>
                      <label class="form-check-label" for="sup-cosign">Require supervisor co-sign for finalization</label>
                    </div>
                    <p class="form-text m-0">Unchecked records a consultative-only assignment. Clinical finalization remains subject to practitioner eligibility rules.</p>
                  </fieldset>

                  <div class="ce-notice ce-notice-amber gpr-assignment-note">
                    <div class="ce-notice-body">
                      <i class="bi bi-arrow-repeat ce-notice-icon" aria-hidden="true"></i>
                      <span>This replaces the associate's previous active supervision. Review the relationship before confirming.</span>
                    </div>
                  </div>
                  <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Assign Supervisor
                  </button>
                </form>
              </div>
            </div>
          <?php endif; ?>

        </div>
      <?php endif; ?>
    </div>

  </div>
</section>
<?= $this->endSection() ?>
