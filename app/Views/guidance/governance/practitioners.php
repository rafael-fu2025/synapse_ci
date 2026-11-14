<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <a href="<?= esc(site_url('/guidance/governance/gates')) ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-shield-check me-1" aria-hidden="true"></i> Activation Gates
  </a>
  <a href="<?= esc(site_url('/guidance/cases')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-folder2-open me-1" aria-hidden="true"></i> Counseling Cases
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

$tierLabel = static fn (string $tier): string => match ($tier) {
    'counselor_associate' => 'Counselor Associate',
    'licensed_counselor'  => 'Licensed Counselor',
    'guidance_supervisor' => 'Guidance Supervisor',
    'qualified_assessor'  => 'Qualified Assessor',
    default               => ucwords(str_replace('_', ' ', $tier)),
};

$tierBadge = static fn (string $tier): string => match ($tier) {
    'guidance_supervisor' => 'bg-primary-subtle text-primary border-primary-subtle',
    'licensed_counselor'  => 'bg-success-subtle text-success border-success-subtle',
    'qualified_assessor'  => 'bg-info-subtle text-info border-info-subtle',
    'counselor_associate' => 'bg-warning-subtle text-warning border-warning-subtle',
    default               => 'bg-secondary-subtle text-secondary border-secondary-subtle',
};
?>

<div class="guidance-practitioners-workspace"
     data-refresh-owner="guidance.governance.practitioners"
     data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div data-refresh-region="workspace" data-refresh-heading tabindex="-1">

    <div class="row g-4">
      <div class="col-12 <?= ($canManagePractitioners || $canReassign) ? 'col-xl-8' : '' ?>">

        <!-- Practitioner Profiles Table -->
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
            <div>
              <h2 class="h6 fw-bold mb-0">Registered Practitioner Credentials</h2>
              <small class="text-muted">Only active credentialed practitioners can perform safety triage, clinical authoring, or co-signing.</small>
            </div>
            <span class="badge bg-secondary-subtle text-secondary border"><?= count($profiles) ?> Profiles</span>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-uppercase text-muted">
                  <tr>
                    <th class="ps-3">Practitioner</th>
                    <th>Credential Tier</th>
                    <th>License &amp; Expiry</th>
                    <th>Standing</th>
                    <th class="pe-3">Service Scopes</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($profiles === []): ?>
                    <tr>
                      <td colspan="5" class="text-center py-4 text-muted">
                        No practitioner profiles registered for this tenant yet.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($profiles as $profile): ?>
                      <?php
                      $fullName = trim((string) ($profile['first_name'] ?? '') . ' ' . (string) ($profile['last_name'] ?? ''));
                      $displayName = $fullName !== '' ? $fullName : (string) $profile['username'];
                      $scopes = $profile['service_scope'] ?? [];
                      ?>
                      <tr>
                        <td class="ps-3">
                          <div class="fw-semibold"><?= esc($displayName) ?></div>
                          <div class="small text-muted">
                            @<?= esc($profile['username']) ?> · <?= esc($profile['user_role'] ?? 'staff') ?>
                            <?php if (!empty($profile['mis_id'])): ?>
                              · ID: <?= esc($profile['mis_id']) ?>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td>
                          <span class="badge border <?= $tierBadge((string) $profile['credential_tier']) ?>">
                            <?= esc($tierLabel((string) $profile['credential_tier'])) ?>
                          </span>
                        </td>
                        <td class="small">
                          <div><?= !empty($profile['license_number']) ? esc($profile['license_number']) : '<span class="text-muted">No license #</span>' ?></div>
                          <div class="text-muted">
                            <?= !empty($profile['credential_expiry']) ? 'Exp: ' . esc(date('M j, Y', strtotime((string) $profile['credential_expiry']))) : 'No expiry set' ?>
                          </div>
                        </td>
                        <td>
                          <?php if ((int) ($profile['is_active'] ?? 0) === 1 && ($profile['professional_standing'] ?? '') === 'active'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                          <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle text-capitalize">
                              <?= esc($profile['professional_standing'] ?? 'inactive') ?>
                            </span>
                          <?php endif; ?>
                        </td>
                        <td class="pe-3">
                          <?php if ($scopes === []): ?>
                            <span class="small text-muted">None assigned</span>
                          <?php else: ?>
                            <div class="d-flex flex-wrap gap-1">
                              <?php foreach ($scopes as $scope): ?>
                                <span class="badge bg-body-tertiary text-body border small"><?= esc($scope) ?></span>
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
          </div>
        </div>

        <!-- Active Supervisions Table -->
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
            <div>
              <h2 class="h6 fw-bold mb-0">Active Supervision Assignments</h2>
              <small class="text-muted">Counselor associates require supervisor co-sign to finalize clinical records.</small>
            </div>
            <span class="badge bg-secondary-subtle text-secondary border"><?= count($supervisions) ?> Active</span>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-uppercase text-muted">
                  <tr>
                    <th class="ps-3">Associate</th>
                    <th>Assigned Supervisor</th>
                    <th>Co-Sign Policy</th>
                    <th class="pe-3">Starts At</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($supervisions === []): ?>
                    <tr>
                      <td colspan="4" class="text-center py-4 text-muted">
                        No active supervision assignments recorded.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($supervisions as $sup): ?>
                      <?php
                      $assocName = trim((string) ($sup['associate_first_name'] ?? '') . ' ' . (string) ($sup['associate_last_name'] ?? ''));
                      $supName   = trim((string) ($sup['supervisor_first_name'] ?? '') . ' ' . (string) ($sup['supervisor_last_name'] ?? ''));
                      ?>
                      <tr>
                        <td class="ps-3">
                          <div class="fw-semibold"><?= esc($assocName !== '' ? $assocName : $sup['associate_username']) ?></div>
                          <small class="text-muted">@<?= esc($sup['associate_username']) ?></small>
                        </td>
                        <td>
                          <div class="fw-semibold"><?= esc($supName !== '' ? $supName : $sup['supervisor_username']) ?></div>
                          <small class="text-muted">@<?= esc($sup['supervisor_username']) ?></small>
                        </td>
                        <td>
                          <?php if ((int) ($sup['co_sign_required'] ?? 1) === 1): ?>
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Co-Sign Required</span>
                          <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border">Consultative Only</span>
                          <?php endif; ?>
                        </td>
                        <td class="small text-muted pe-3">
                          <?= !empty($sup['starts_at']) ? esc(date('M j, Y g:i A', strtotime((string) $sup['starts_at']))) : '—' ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div>

      <?php if ($canManagePractitioners || $canReassign): ?>
        <div class="col-12 col-xl-4">

          <?php if ($canManagePractitioners): ?>
            <div class="card border-0 shadow-sm mb-4">
              <div class="card-header bg-transparent py-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-person-plus me-1" aria-hidden="true"></i> Register / Update Practitioner</h2>
              </div>
              <div class="card-body">
                <form action="<?= esc(site_url('/guidance/governance/practitioners/register'), 'attr') ?>" method="POST" data-background-submit>
                  <?= csrf_field() ?>
                  <div class="mb-3">
                    <label for="prac-user-id" class="form-label small fw-semibold">Guidance Staff Account</label>
                    <select id="prac-user-id" name="user_id" class="form-select form-select-sm" required>
                      <option value="">Select user...</option>
                      <?php foreach ($guidanceUsers as $gu): ?>
                        <?php $gName = trim((string) ($gu['first_name'] ?? '') . ' ' . (string) ($gu['last_name'] ?? '')); ?>
                        <option value="<?= (int) $gu['id'] ?>">
                          <?= esc(($gName !== '' ? $gName : $gu['username']) . ' (@' . $gu['username'] . ' · ' . $gu['role'] . ')') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="mb-3">
                    <label for="prac-tier" class="form-label small fw-semibold">Credential Tier</label>
                    <select id="prac-tier" name="credential_tier" class="form-select form-select-sm" required>
                      <?php foreach ($tiers as $t): ?>
                        <option value="<?= esc($t, 'attr') ?>"><?= esc($tierLabel($t)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="row g-2 mb-3">
                    <div class="col-sm-6">
                      <label for="prac-license" class="form-label small fw-semibold">PRC / License #</label>
                      <input type="text" id="prac-license" name="license_number" class="form-control form-control-sm" maxlength="64" placeholder="Optional for associate">
                    </div>
                    <div class="col-sm-6">
                      <label for="prac-expiry-display" class="form-label small fw-semibold">Credential Expiry</label>
                      <input type="text" id="prac-expiry-display" class="form-control form-control-sm" data-datepicker data-target="#prac-expiry" placeholder="DD/MM/YYYY" autocomplete="off">
                      <input type="hidden" id="prac-expiry" name="credential_expiry" value="">
                    </div>
                  </div>

                  <div class="mb-3">
                    <div class="form-label small fw-semibold">Authorized Service Scopes</div>
                    <div class="border rounded p-2 bg-body-tertiary">
                      <?php foreach ($availableScopes as $scopeKey => $scopeLabel): ?>
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="service_scope[]" value="<?= esc($scopeKey, 'attr') ?>" id="scope-<?= esc($scopeKey, 'attr') ?>">
                          <label class="form-check-label small" for="scope-<?= esc($scopeKey, 'attr') ?>">
                            <?= esc($scopeLabel) ?>
                          </label>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-save me-1" aria-hidden="true"></i> Save Practitioner Profile
                  </button>
                </form>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($canReassign): ?>
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-transparent py-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i> Assign Supervision</h2>
              </div>
              <div class="card-body">
                <form action="<?= esc(site_url('/guidance/governance/practitioners/supervision'), 'attr') ?>"
                      method="POST"
                      data-background-submit
                      data-confirm="Assign this supervisor to the selected associate? Any previous active supervision for the associate will be closed."
                      data-confirm-title="Assign Supervision"
                      data-confirm-label="Save Assignment"
                      data-confirm-tone="primary">
                  <?= csrf_field() ?>
                  <div class="mb-3">
                    <label for="sup-associate" class="form-label small fw-semibold">Counselor Associate</label>
                    <select id="sup-associate" name="associate_user_id" class="form-select form-select-sm" required>
                      <option value="">Select associate...</option>
                      <?php foreach ($guidanceUsers as $gu): ?>
                        <?php $gName = trim((string) ($gu['first_name'] ?? '') . ' ' . (string) ($gu['last_name'] ?? '')); ?>
                        <option value="<?= (int) $gu['id'] ?>">
                          <?= esc(($gName !== '' ? $gName : $gu['username']) . ' (@' . $gu['username'] . ')') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="mb-3">
                    <label for="sup-supervisor" class="form-label small fw-semibold">Clinical Supervisor</label>
                    <select id="sup-supervisor" name="supervisor_user_id" class="form-select form-select-sm" required>
                      <option value="">Select supervisor...</option>
                      <?php foreach ($guidanceUsers as $gu): ?>
                        <?php $gName = trim((string) ($gu['first_name'] ?? '') . ' ' . (string) ($gu['last_name'] ?? '')); ?>
                        <option value="<?= (int) $gu['id'] ?>">
                          <?= esc(($gName !== '' ? $gName : $gu['username']) . ' (@' . $gu['username'] . ' · ' . $gu['role'] . ')') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="form-check mb-3">
                    <input type="hidden" name="co_sign_required" value="0">
                    <input class="form-check-input" type="checkbox" id="sup-cosign" name="co_sign_required" value="1" checked>
                    <label class="form-check-label small" for="sup-cosign">
                      Require supervisor co-sign for finalization
                    </label>
                  </div>

                  <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                    <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Assign Supervisor
                  </button>
                </form>
              </div>
            </div>
          <?php endif; ?>

        </div>
      <?php endif; ?>
    </div>

  </div>
</div>
<?= $this->endSection() ?>
