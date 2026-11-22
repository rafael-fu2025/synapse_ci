        <!-- Tab 3: Lab & Diagnostic Results -->
        <div class="tab-pane fade" id="tab-labs" role="tabpanel">
          <?php if (empty($labResults)): ?>
            <div class="cp-empty-pane">
              <i class="bi bi-flask" aria-hidden="true"></i>
              No laboratory or diagnostic results recorded for this patient yet.
            </div>
          <?php else: ?>
            <div class="cp-table-card">
              <div class="cp-table-wrap">
                <table class="table align-middle mb-0 cp-table" data-mobile-cards="false">
                  <thead>
                    <tr>
                      <th scope="col">Test Name</th>
                      <th scope="col">SY / Context</th>
                      <th scope="col">Status</th>
                      <th scope="col">Summary &amp; Notes</th>
                      <th scope="col">Submitted (Manila)</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($labResults as $lab): ?>
                      <?php
                        $labTone = match ($lab['result_status']) {
                            'normal' => 'cp-status-inline--active',
                            'abnormal' => 'cp-status-inline--danger',
                            'inconclusive' => 'cp-status-inline--warn',
                            default => 'cp-status-inline--muted',
                        };
                      ?>
                      <tr class="cp-row">
                        <td class="cp-cell-header fw-semibold"><?= esc($lab['test_name']) ?></td>
                        <td data-label="SY / Context">
                          <div class="cp-mono fw-semibold"><?= esc($lab['school_year'] ?? '—') ?></div>
                          <small class="text-muted"><?= esc(ucwords(str_replace('_', ' ', (string) ($lab['context_type'] ?? '')))) ?></small>
                        </td>
                        <td data-label="Status">
                          <span class="cp-status-inline <?= esc($labTone) ?>">
                            <span class="cp-dot" aria-hidden="true"></span><?= esc(ucfirst((string) $lab['result_status'])) ?>
                          </span>
                        </td>
                        <td data-label="Summary &amp; Notes" class="cp-cell-full text-break">
                          <div><?= esc($lab['result_summary'] ?? '—') ?></div>
                          <?php if (!empty($lab['clinical_notes'])): ?>
                            <div class="text-muted small mt-1"><?= esc($lab['clinical_notes']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td data-label="Submitted (Manila)">
                          <span class="cp-mono small"><?= esc($formatManila($lab['submitted_at'] ?? $lab['created_at'] ?? null)) ?></span>
                          <div class="text-muted small"><?= esc($lab['recorded_by_name'] ?? 'Clinic staff') ?></div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($canWriteLabs)): ?>
            <div class="cp-subform-panel">
              <h4 class="cp-subform-title"><i class="bi bi-plus-circle" aria-hidden="true"></i>Record Laboratory / Diagnostic Result</h4>
              <form method="POST" action="<?= site_url('/clinic/patients/' . (int) $person['id'] . '/labs') ?>">
                <?= csrf_field() ?>
                <div class="row g-3">
                  <div class="col-md-5">
                    <label for="labTestName" class="form-label">Test Name</label>
                    <input type="text" name="test_name" id="labTestName" class="form-control" placeholder="e.g. Chest X-Ray, CBC, Urinalysis" maxlength="100" required>
                  </div>
                  <div class="col-md-3">
                    <label for="labSchoolYear" class="form-label">School Year</label>
                    <select name="school_year" id="labSchoolYear" class="form-select">
                      <?php foreach (($schoolYearOptions ?? [$currentSchoolYear]) as $syOpt): ?>
                        <option value="<?= esc($syOpt) ?>" <?= ($syOpt === ($currentSchoolYear ?? '')) ? 'selected' : '' ?>><?= esc($syOpt) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label for="labContextType" class="form-label">Context</label>
                    <select name="context_type" id="labContextType" class="form-select">
                      <option value="enrollment_annual">Enrollment / Annual Medical</option>
                      <option value="encounter_followup">Consultation Follow-up</option>
                      <option value="other">Other</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label for="labResultStatus" class="form-label">Classification</label>
                    <select name="result_status" id="labResultStatus" class="form-select">
                      <option value="normal">Normal</option>
                      <option value="abnormal">Abnormal</option>
                      <option value="inconclusive">Inconclusive</option>
                      <option value="pending">Pending</option>
                    </select>
                  </div>
                  <div class="col-md-8">
                    <label for="labSummary" class="form-label">Summary / Findings</label>
                    <input type="text" name="result_summary" id="labSummary" class="form-control" placeholder="Key laboratory result summary" maxlength="255">
                  </div>
                  <div class="col-12">
                    <label for="labNotes" class="form-label">Clinical Interpretation / Follow-up Notes</label>
                    <textarea name="clinical_notes" id="labNotes" rows="2" class="form-control" maxlength="2000"></textarea>
                  </div>
                </div>
                <div class="mt-3 d-flex flex-column-reverse flex-sm-row justify-content-sm-end gap-2">
                  <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>Save Lab Result
                  </button>
                </div>
              </form>
            </div>
          <?php endif; ?>
        </div>
