        <!-- Tab 3: Lab & Diagnostic Results -->
        <div class="tab-pane fade" id="tab-labs">
          <?php if (empty($labResults)): ?>
            <p class="text-muted small mb-4">No laboratory or diagnostic results recorded for this patient yet.</p>
          <?php else: ?>
            <div class="table-responsive mb-4">
              <table class="table table-hover align-middle small mb-0">
                <thead class="table-light text-uppercase">
                  <tr>
                    <th>Test Name</th>
                    <th>SY / Context</th>
                    <th>Status</th>
                    <th>Summary &amp; Notes</th>
                    <th>Submitted (Manila)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($labResults as $lab): ?>
                    <tr>
                      <td class="fw-semibold"><?= esc($lab['test_name']) ?></td>
                      <td>
                        <div><?= esc($lab['school_year'] ?? '—') ?></div>
                        <small class="text-muted"><?= esc(str_replace('_', ' ', (string) ($lab['context_type'] ?? ''))) ?></small>
                      </td>
                      <td>
                        <?php $lBadge = match ($lab['result_status']) { 'normal' => 'bg-success', 'abnormal' => 'bg-danger', 'inconclusive' => 'bg-warning text-dark', default => 'bg-secondary' }; ?>
                        <span class="badge <?= $lBadge ?> text-uppercase"><?= esc($lab['result_status']) ?></span>
                      </td>
                      <td class="text-break">
                        <?= esc($lab['result_summary'] ?? '—') ?>
                        <?php if (!empty($lab['clinical_notes'])): ?>
                          <div class="text-muted small"><?= esc($lab['clinical_notes']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?= esc($formatManila($lab['submitted_at'] ?? $lab['created_at'] ?? null)) ?>
                        <div class="text-muted" style="font-size: 0.75rem;"><?= esc($lab['recorded_by_name'] ?? 'Clinic staff') ?></div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if (!empty($canWriteLabs)): ?>
            <div class="border rounded p-3 bg-light-subtle">
              <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle me-1 text-primary"></i> Record Laboratory / Diagnostic Result</h6>
              <form method="POST" action="<?= site_url('/clinic/patients/' . (int) $person['id'] . '/labs') ?>">
                <?= csrf_field() ?>
                <div class="row g-3">
                  <div class="col-md-5">
                    <label for="labTestName" class="form-label small fw-semibold">Test Name</label>
                    <input type="text" name="test_name" id="labTestName" class="form-control form-control-sm" placeholder="e.g. Chest X-Ray, CBC, Urinalysis" maxlength="100" required>
                  </div>
                  <div class="col-md-3">
                    <label for="labSchoolYear" class="form-label small fw-semibold">School Year</label>
                    <select name="school_year" id="labSchoolYear" class="form-select form-select-sm">
                      <?php foreach (($schoolYearOptions ?? [$currentSchoolYear]) as $syOpt): ?>
                        <option value="<?= esc($syOpt) ?>" <?= ($syOpt === ($currentSchoolYear ?? '')) ? 'selected' : '' ?>><?= esc($syOpt) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label for="labContextType" class="form-label small fw-semibold">Context</label>
                    <select name="context_type" id="labContextType" class="form-select form-select-sm">
                      <option value="enrollment_annual">Enrollment / Annual Medical</option>
                      <option value="encounter_followup">Consultation Follow-up</option>
                      <option value="other">Other</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label for="labResultStatus" class="form-label small fw-semibold">Classification</label>
                    <select name="result_status" id="labResultStatus" class="form-select form-select-sm">
                      <option value="normal">Normal</option>
                      <option value="abnormal">Abnormal</option>
                      <option value="inconclusive">Inconclusive</option>
                      <option value="pending">Pending</option>
                    </select>
                  </div>
                  <div class="col-md-8">
                    <label for="labSummary" class="form-label small fw-semibold">Summary / Findings</label>
                    <input type="text" name="result_summary" id="labSummary" class="form-control form-control-sm" placeholder="Key laboratory result summary" maxlength="255">
                  </div>
                  <div class="col-12">
                    <label for="labNotes" class="form-label small fw-semibold">Clinical Interpretation / Follow-up Notes</label>
                    <textarea name="clinical_notes" id="labNotes" rows="2" class="form-control form-control-sm" maxlength="2000"></textarea>
                  </div>
                </div>
                <div class="mt-3 d-flex justify-content-end">
                  <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Save Lab Result</button>
                </div>
              </form>
            </div>
          <?php endif; ?>
        </div>

