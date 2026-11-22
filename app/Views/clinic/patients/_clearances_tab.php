        <!-- Tab 4: Enrollment & Annual Health Clearances -->
        <div class="tab-pane fade" id="tab-clearances" role="tabpanel">
          <div class="cp-notice mb-3">
            <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Retained per school year strictly for authorized Clinic personnel. Individual lab details remain restricted under Clinic RBAC.
          </div>

          <?php if (empty($enrollmentClearances)): ?>
            <div class="cp-empty-pane">
              <i class="bi bi-patch-check" aria-hidden="true"></i>
              No enrollment or annual medical clearances logged yet.
            </div>
          <?php else: ?>
            <div class="cp-table-card">
              <div class="cp-table-wrap">
                <table class="table align-middle mb-0 cp-table" data-mobile-cards="false">
                  <thead>
                    <tr>
                      <th scope="col">School Year / Term</th>
                      <th scope="col">Category</th>
                      <th scope="col">Checklist Completed</th>
                      <th scope="col">Status</th>
                      <th scope="col" class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($enrollmentClearances as $clr): ?>
                      <?php
                        $items = json_decode((string) ($clr['required_labs_json'] ?? '{}'), true) ?: [];
                        $clrTone = match ($clr['clearance_status']) {
                            'cleared' => 'cp-status-inline--active',
                            'hold_followup' => 'cp-status-inline--danger',
                            default => 'cp-status-inline--warn',
                        };
                      ?>
                      <tr class="cp-row">
                        <td class="cp-cell-header">
                          <strong class="cp-mono"><?= esc($clr['school_year']) ?></strong>
                          <div class="text-muted small"><?= esc($clr['semester']) ?></div>
                        </td>
                        <td data-label="Category"><?= esc(ucfirst((string) $clr['applicant_category'])) ?></td>
                        <td data-label="Checklist Completed" class="cp-cell-full">
                          <div class="d-flex flex-wrap gap-1">
                            <?php foreach (['physical_exam' => 'PE', 'cbc' => 'CBC', 'urinalysis' => 'UA', 'chest_xray' => 'CXR', 'fecalysis' => 'Fec', 'dental_exam' => 'Dental'] as $k => $short): ?>
                              <span class="cp-tag <?= !empty($items[$k]) ? 'cp-tag--verified' : 'cp-tag--pending' ?>">
                                <?php if (!empty($items[$k])): ?><i class="bi bi-check2" aria-hidden="true"></i><?php endif; ?><?= $short ?>
                              </span>
                            <?php endforeach; ?>
                          </div>
                          <?php if (!empty($clr['remarks'])): ?>
                            <div class="text-muted small mt-1"><?= esc($clr['remarks']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td data-label="Status">
                          <span class="cp-status-inline <?= esc($clrTone) ?>">
                            <span class="cp-dot" aria-hidden="true"></span><?= esc(ucwords(str_replace('_', ' ', (string) $clr['clearance_status']))) ?>
                          </span>
                          <?php if (!empty($clr['cleared_by_name'])): ?>
                            <div class="text-muted small">By <?= esc($clr['cleared_by_name']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="text-end cp-cell-actions">
                          <a href="<?= site_url('/clinic/clearances/' . (int) $clr['id'] . '/certificate') ?>" class="btn btn-outline-secondary btn-sm clinic-cert-btn" target="_blank" rel="noopener">
                            <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>PDF
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($canWriteClearance)): ?>
            <div class="cp-subform-panel">
              <h4 class="cp-subform-title"><i class="bi bi-clipboard-check" aria-hidden="true"></i>Update / Issue School-Year Medical Clearance</h4>
              <form method="POST" action="<?= site_url('/clinic/patients/' . (int) $person['id'] . '/clearances') ?>"
                    data-confirm="Save this school-year health clearance checklist and status?"
                    data-confirm-title="Update medical clearance"
                    data-confirm-label="Save clearance"
                    data-confirm-tone="primary">
                <?= csrf_field() ?>
                <div class="row g-3 mb-3">
                  <div class="col-md-3">
                    <label for="clrSy" class="form-label">School Year</label>
                    <select name="school_year" id="clrSy" class="form-select">
                      <?php foreach (($schoolYearOptions ?? [$currentSchoolYear]) as $syOpt): ?>
                        <option value="<?= esc($syOpt) ?>" <?= ($syOpt === ($currentSchoolYear ?? '')) ? 'selected' : '' ?>><?= esc($syOpt) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="clrSemester" class="form-label">Term / Period</label>
                    <select name="semester" id="clrSemester" class="form-select">
                      <option value="1st Semester">1st Semester</option>
                      <option value="2nd Semester">2nd Semester</option>
                      <option value="Summer">Summer</option>
                      <option value="Annual">Annual</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="clrCategory" class="form-label">Category</label>
                    <select name="applicant_category" id="clrCategory" class="form-select">
                      <option value="freshman" <?= ($person['kind'] ?? '') === 'student' ? 'selected' : '' ?>>Freshman / New</option>
                      <option value="transferee">Transferee</option>
                      <option value="returning">Returning Student</option>
                      <option value="employee" <?= ($person['kind'] ?? '') === 'employee' ? 'selected' : '' ?>>Employee Annual</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="clrStatus" class="form-label">Clearance Decision</label>
                    <select name="clearance_status" id="clrStatus" class="form-select">
                      <option value="pending">Pending Labs</option>
                      <option value="cleared">Cleared</option>
                      <option value="hold_followup">Hold / For Follow-up</option>
                    </select>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="form-label">Verified Requirements</div>
                  <div class="cp-check-grid">
                    <?php foreach (['physical_exam' => 'Physical Exam', 'cbc' => 'CBC', 'urinalysis' => 'Urinalysis', 'chest_xray' => 'Chest X-Ray', 'fecalysis' => 'Fecalysis', 'dental_exam' => 'Dental Exam'] as $key => $label): ?>
                      <div class="form-check cp-check-chip">
                        <input class="form-check-input" type="checkbox" name="checklist[<?= esc($key) ?>]" value="1" id="chk_<?= esc($key) ?>">
                        <label class="form-check-label" for="chk_<?= esc($key) ?>"><?= esc($label) ?></label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="mb-3">
                  <label for="clrRemarks" class="form-label">Clearance Remarks / Physician Note</label>
                  <input type="text" name="remarks" id="clrRemarks" class="form-control" placeholder="e.g. Fit to enroll; chest x-ray reviewed normal" maxlength="500">
                </div>
                <div class="d-flex flex-column-reverse flex-sm-row justify-content-sm-end gap-2">
                  <button type="submit" class="btn btn-success btn-sm">
                    <i class="bi bi-patch-check" aria-hidden="true"></i>Save Enrollment Clearance
                  </button>
                </div>
              </form>
            </div>
          <?php endif; ?>
        </div>
