        <!-- Tab 4: Enrollment & Annual Health Clearances -->
        <div class="tab-pane fade" id="tab-clearances">
          <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-shield-lock me-1"></i> Retained per school year strictly for authorized Clinic personnel. Individual lab details remain restricted under Clinic RBAC.
          </div>
          <?php if (empty($enrollmentClearances)): ?>
            <p class="text-muted small mb-4">No enrollment or annual medical clearances logged yet.</p>
          <?php else: ?>
            <div class="table-responsive mb-4">
              <table class="table table-hover align-middle small mb-0">
                <thead class="table-light text-uppercase">
                  <tr>
                    <th>School Year / Term</th>
                    <th>Category</th>
                    <th>Checklist Completed</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($enrollmentClearances as $clr): ?>
                    <?php $items = json_decode((string) ($clr['required_labs_json'] ?? '{}'), true) ?: []; ?>
                    <tr>
                      <td>
                        <strong><?= esc($clr['school_year']) ?></strong>
                        <div class="text-muted"><?= esc($clr['semester']) ?></div>
                      </td>
                      <td><?= esc(ucfirst((string) $clr['applicant_category'])) ?></td>
                      <td>
                        <div class="d-flex flex-wrap gap-1">
                          <?php foreach (['physical_exam' => 'PE', 'cbc' => 'CBC', 'urinalysis' => 'UA', 'chest_xray' => 'CXR', 'fecalysis' => 'Fec', 'dental_exam' => 'Dental'] as $k => $short): ?>
                            <span class="badge <?= !empty($items[$k]) ? 'bg-success-subtle text-success' : 'bg-light text-muted border' ?>"><?= $short ?></span>
                          <?php endforeach; ?>
                        </div>
                        <?php if (!empty($clr['remarks'])): ?>
                          <div class="text-muted mt-1"><?= esc($clr['remarks']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php $cBadge = match ($clr['clearance_status']) { 'cleared' => 'bg-success', 'hold_followup' => 'bg-danger', default => 'bg-warning text-dark' }; ?>
                        <span class="badge <?= $cBadge ?> text-uppercase"><?= esc(str_replace('_', ' ', (string) $clr['clearance_status'])) ?></span>
                        <?php if (!empty($clr['cleared_by_name'])): ?>
                          <div class="text-muted" style="font-size: 0.75rem;">By <?= esc($clr['cleared_by_name']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <a href="<?= site_url('/clinic/clearances/' . (int) $clr['id'] . '/certificate') ?>" class="btn btn-outline-dark btn-sm" target="_blank" rel="noopener">
                          <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if (!empty($canWriteClearance)): ?>
            <div class="border rounded p-3 bg-light-subtle">
              <h6 class="fw-bold mb-3"><i class="bi bi-clipboard-check me-1 text-primary"></i> Update / Issue School-Year Medical Clearance</h6>
              <form method="POST" action="<?= site_url('/clinic/patients/' . (int) $person['id'] . '/clearances') ?>"
                    data-confirm="Save this school-year health clearance checklist and status?"
                    data-confirm-title="Update medical clearance"
                    data-confirm-label="Save clearance"
                    data-confirm-tone="primary">
                <?= csrf_field() ?>
                <div class="row g-3 mb-3">
                  <div class="col-md-3">
                    <label for="clrSy" class="form-label small fw-semibold">School Year</label>
                    <select name="school_year" id="clrSy" class="form-select form-select-sm">
                      <?php foreach (($schoolYearOptions ?? [$currentSchoolYear]) as $syOpt): ?>
                        <option value="<?= esc($syOpt) ?>" <?= ($syOpt === ($currentSchoolYear ?? '')) ? 'selected' : '' ?>><?= esc($syOpt) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="clrSemester" class="form-label small fw-semibold">Term / Period</label>
                    <select name="semester" id="clrSemester" class="form-select form-select-sm">
                      <option value="1st Semester">1st Semester</option>
                      <option value="2nd Semester">2nd Semester</option>
                      <option value="Summer">Summer</option>
                      <option value="Annual">Annual</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="clrCategory" class="form-label small fw-semibold">Category</label>
                    <select name="applicant_category" id="clrCategory" class="form-select form-select-sm">
                      <option value="freshman" <?= ($person['kind'] ?? '') === 'student' ? 'selected' : '' ?>>Freshman / New</option>
                      <option value="transferee">Transferee</option>
                      <option value="returning">Returning Student</option>
                      <option value="employee" <?= ($person['kind'] ?? '') === 'employee' ? 'selected' : '' ?>>Employee Annual</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="clrStatus" class="form-label small fw-semibold">Clearance Decision</label>
                    <select name="clearance_status" id="clrStatus" class="form-select form-select-sm">
                      <option value="pending">Pending Labs</option>
                      <option value="cleared">Cleared</option>
                      <option value="hold_followup">Hold / For Follow-up</option>
                    </select>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="small fw-semibold mb-2">Verified Requirements</div>
                  <div class="d-flex flex-wrap gap-3 small">
                    <?php foreach (['physical_exam' => 'Physical Exam', 'cbc' => 'CBC', 'urinalysis' => 'Urinalysis', 'chest_xray' => 'Chest X-Ray', 'fecalysis' => 'Fecalysis', 'dental_exam' => 'Dental Exam'] as $key => $label): ?>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="checklist[<?= esc($key) ?>]" value="1" id="chk_<?= esc($key) ?>">
                        <label class="form-check-label" for="chk_<?= esc($key) ?>"><?= esc($label) ?></label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="mb-3">
                  <label for="clrRemarks" class="form-label small fw-semibold">Clearance Remarks / Physician Note</label>
                  <input type="text" name="remarks" id="clrRemarks" class="form-control form-control-sm" placeholder="e.g. Fit to enroll; chest x-ray reviewed normal" maxlength="500">
                </div>
                <div class="d-flex justify-content-end">
                  <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-patch-check me-1"></i> Save Enrollment Clearance</button>
                </div>
              </form>
            </div>
          <?php endif; ?>
        </div>

