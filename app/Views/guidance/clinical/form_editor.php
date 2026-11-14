<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<?php
$mode = (string) ($mode ?? 'create');
$formKey = (string) ($formKey ?? '');
$definition = $definition ?? [];
$schema = $schema ?? [];
$instance = $instance ?? null;
$draft = $draft ?? null;
$revision = $revision ?? null;
$attestations = $attestations ?? [];
$formData = $formData ?? [];
$person = $person ?? null;
$contextIds = $contextIds ?? [];
$practitionerProfile = $practitionerProfile ?? null;
$activeSupervision = $activeSupervision ?? null;
$canFinalizeDirectly = (bool) ($canFinalizeDirectly ?? false);
$isAssignedSupervisor = (bool) ($isAssignedSupervisor ?? false);
$authorInfo = $authorInfo ?? null;
$supervisorInfo = $supervisorInfo ?? null;
$attestationStatement = (string) ($attestationStatement ?? '');

$instanceId = (int) ($instance['id'] ?? 0);
$status = (string) ($instance['status'] ?? 'draft');
$caseId = (int) ($contextIds['case_id'] ?? 0);
$episodeId = (int) ($contextIds['episode_id'] ?? 0);
?>
<?php if ($caseId > 0): ?>
    <a href="<?= site_url('guidance/cases/' . $caseId) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Case
    </a>
<?php elseif ($episodeId > 0): ?>
    <a href="<?= site_url('guidance/episodes/' . $episodeId) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Episode
    </a>
<?php else: ?>
    <a href="<?= site_url('guidance') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Guidance Hub
    </a>
<?php endif; ?>

<a href="<?= site_url('guidance/clinical/cosign-queue') ?>" class="btn btn-outline-warning btn-sm">
    <i class="bi bi-pen me-1"></i>Co-Sign Queue
</a>

<?php if ($instanceId > 0): ?>
    <a href="<?= site_url('guidance/clinical/forms/' . $instanceId . '/pdf?mode=preview') ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-file-earmark-pdf me-1"></i>Preview PDF
    </a>
    <?php if ($status === 'finalized'): ?>
        <a href="<?= site_url('guidance/clinical/forms/' . $instanceId . '/pdf?mode=issue') ?>" target="_blank" class="btn btn-primary btn-sm">
            <i class="bi bi-printer me-1"></i>Issue Official PDF
        </a>
    <?php endif; ?>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$statusBadges = [
    'draft'          => ['bg' => 'secondary', 'label' => 'Draft (Editable)'],
    'pending_cosign' => ['bg' => 'warning text-dark', 'label' => 'Pending Supervisor Co-Sign'],
    'finalized'      => ['bg' => 'success', 'label' => 'Finalized & Attested'],
];
$stMeta = $statusBadges[$status] ?? ['bg' => 'secondary', 'label' => ucfirst($status)];
$isEditable = ($mode === 'create') || ($status === 'draft');

$personName = '';
if ($person !== null) {
    $personName = trim((string) (($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')));
}

$tier = (string) ($practitionerProfile['credential_tier'] ?? '');
$isAssociate = ($tier === 'counselor_associate');

// Canonical UI field definitions & labels supplementing the manifest schema
$uiCatalog = [
    'psychosocial_support_interview' => [
        'student_name'                 => ['label' => 'Student / Client Full Name', 'type' => 'string', 'required' => true, 'col' => 'col-md-6'],
        'student_id'                   => ['label' => 'Student / MIS ID', 'type' => 'string', 'required' => true, 'col' => 'col-md-3'],
        'interview_date'               => ['label' => 'Interview Date', 'type' => 'date', 'required' => true, 'col' => 'col-md-3'],
        'course_year'                  => ['label' => 'Course / Year / Department', 'type' => 'string', 'required' => true, 'col' => 'col-md-12'],
        'demographics'                 => [
            'label'      => 'Client Demographics',
            'type'       => 'object',
            'required'   => true,
            'properties' => [
                'age'     => ['label' => 'Age', 'type' => 'integer'],
                'gender'  => ['label' => 'Gender / Sex', 'type' => 'string'],
                'contact' => ['label' => 'Contact Number / Email', 'type' => 'string'],
            ],
        ],
        'presenting_concerns'          => ['label' => 'Presenting Concerns', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'mental_health_screening'      => [
            'label'      => 'Mental Health Screening (Optional)',
            'type'       => 'object',
            'required'   => false,
            'properties' => [
                'phq9_score' => ['label' => 'PHQ-9 Score (0-27)', 'type' => 'integer'],
                'gad7_score' => ['label' => 'GAD-7 Score (0-21)', 'type' => 'integer'],
                'risk_level' => ['label' => 'Screening Risk Level', 'type' => 'enum', 'options' => ['', 'low', 'moderate', 'high', 'critical']],
            ],
        ],
        'family_social_support'        => ['label' => 'Family & Social Support Context', 'type' => 'textarea', 'encrypted' => true, 'required' => false],
        'counselor_assessment'         => ['label' => 'Counselor Clinical Assessment', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'intervention_plan'            => ['label' => 'Intervention & Follow-Up Plan', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'privacy_consent_acknowledged' => ['label' => 'Client privacy notice & informed consent acknowledged', 'type' => 'boolean', 'required' => true],
    ],
    'psychosocial_support_contact' => [
        'session_date'       => ['label' => 'Session Date', 'type' => 'date', 'required' => true, 'col' => 'col-md-4'],
        'session_number'     => ['label' => 'Session Number', 'type' => 'integer', 'required' => true, 'col' => 'col-md-4'],
        'modality'           => ['label' => 'Contact Modality', 'type' => 'enum', 'options' => ['in_person', 'telehealth', 'phone'], 'required' => true, 'col' => 'col-md-4'],
        'client_state'       => ['label' => 'Client Presenting State', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'interventions_used' => ['label' => 'Psychosocial Interventions Used', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'client_response'    => ['label' => 'Client Response & Affect', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'action_items'       => ['label' => 'Action Items / Safety Follow-Up', 'type' => 'textarea', 'encrypted' => true, 'required' => false],
        'next_session_date'  => ['label' => 'Next Session Date', 'type' => 'date', 'required' => false, 'col' => 'col-md-4'],
    ],
    'counseling_contact_sheet' => [
        'session_date'                => ['label' => 'Session Date', 'type' => 'date', 'required' => true, 'col' => 'col-md-3'],
        'session_number'              => ['label' => 'Session #', 'type' => 'integer', 'required' => true, 'col' => 'col-md-2'],
        'time_start'                  => ['label' => 'Start Time', 'type' => 'time', 'required' => true, 'col' => 'col-md-2'],
        'time_end'                    => ['label' => 'End Time', 'type' => 'time', 'required' => true, 'col' => 'col-md-2'],
        'session_type'                => ['label' => 'Session Type', 'type' => 'enum', 'options' => ['intake', 'regular', 'crisis', 'follow_up', 'closure'], 'required' => true, 'col' => 'col-md-3'],
        'concerns_discussed'          => ['label' => 'Concerns Discussed', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'counseling_goals'            => ['label' => 'Counseling Goals', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'interventions_and_responses' => ['label' => 'Interventions & Client Responses', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'homework_and_commitments'    => ['label' => 'Homework & Commitments', 'type' => 'textarea', 'encrypted' => true, 'required' => false],
        'next_schedule'               => ['label' => 'Next Scheduled Session', 'type' => 'datetime', 'required' => false, 'col' => 'col-md-5'],
    ],
];

$schemaFields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
$fieldsToRender = $uiCatalog[$formKey] ?? [];
if ($fieldsToRender === [] && $schemaFields !== []) {
    foreach ($schemaFields as $k => $meta) {
        $fieldsToRender[$k] = [
            'label'     => (string) ($meta['label'] ?? ucwords(str_replace('_', ' ', (string) $k))),
            'type'      => !empty($meta['encrypted']) ? 'textarea' : (string) ($meta['type'] ?? 'string'),
            'required'  => (bool) ($meta['required'] ?? false),
            'encrypted' => (bool) ($meta['encrypted'] ?? false),
            'options'   => $meta['options'] ?? [],
        ];
    }
}
?>

<!-- Controlled Form Metadata Header -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <span class="badge bg-light text-dark border"><?= esc((string) ($definition['form_code'] ?? $formKey)) ?></span>
                    <?php if ($instanceId > 0): ?>
                        <span class="badge bg-primary-subtle text-primary border"><?= esc((string) ($instance['instance_number'] ?? ('#' . $instanceId))) ?></span>
                    <?php else: ?>
                        <span class="badge bg-info-subtle text-info border">New Draft</span>
                    <?php endif; ?>
                    <span class="badge bg-<?= esc($stMeta['bg']) ?>"><?= esc($stMeta['label']) ?></span>
                    <?php if ($tier !== ''): ?>
                        <span class="badge bg-light text-secondary border">
                            Your Tier: <?= esc(ucwords(str_replace('_', ' ', $tier))) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <h4 class="mb-1"><?= esc((string) ($definition['canonical_title'] ?? $definition['title'] ?? 'Controlled Clinical Form')) ?></h4>
                <div class="small text-muted">
                    <?php if ($personName !== ''): ?>
                        Client: <strong><?= esc($personName) ?></strong> (<?= esc((string) ($person['mis_id'] ?? ('Person #' . (int) ($person['id'] ?? 0)))) ?>)
                    <?php elseif (! empty($contextIds['person_id'])): ?>
                        Client: <strong>Person #<?= (int) $contextIds['person_id'] ?></strong>
                    <?php endif; ?>
                    <?php if ($caseId > 0): ?>
                        &middot; Case #<?= $caseId ?>
                    <?php endif; ?>
                    <?php if ($episodeId > 0): ?>
                        &middot; Episode #<?= $episodeId ?>
                    <?php endif; ?>
                    <?php if (! empty($contextIds['session_id'])): ?>
                        &middot; Session #<?= (int) $contextIds['session_id'] ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="small text-end text-muted">
                <?php if ($authorInfo !== null): ?>
                    <?php $aName = trim((string) (($authorInfo['first_name'] ?? '') . ' ' . ($authorInfo['last_name'] ?? ''))) ?: (string) ($authorInfo['username'] ?? ''); ?>
                    <div>Author: <strong><?= esc($aName) ?></strong></div>
                <?php endif; ?>
                <?php if ($supervisorInfo !== null): ?>
                    <?php $sName = trim((string) (($supervisorInfo['first_name'] ?? '') . ' ' . ($supervisorInfo['last_name'] ?? ''))) ?: (string) ($supervisorInfo['username'] ?? ''); ?>
                    <div>Assigned Supervisor: <strong><?= esc($sName) ?></strong></div>
                <?php elseif ($isAssociate && $activeSupervision === null): ?>
                    <div class="text-danger fw-semibold">No active supervisor assignment found</div>
                <?php endif; ?>
                <div class="mt-1">
                    <i class="bi bi-shield-lock me-1 text-success"></i>Sensitive narrative fields are encrypted at rest
                </div>
            </div>
        </div>

        <?php if ($status === 'pending_cosign'): ?>
            <div class="alert alert-warning mt-3 mb-0 py-2 small">
                <i class="bi bi-hourglass-split me-1"></i>
                <strong>Awaiting Supervisor Co-Sign</strong>
                <?php $submittedAt = $instance['submitted_for_cosign_at'] ?? $draft['submitted_for_cosign_at'] ?? null; ?>
                <?php $subNotes = $instance['submission_notes'] ?? $draft['submission_notes'] ?? null; ?>
                <?php if (! empty($submittedAt)): ?>
                    (submitted <?= esc(date('M d, Y g:i A', strtotime((string) $submittedAt))) ?>)
                <?php endif; ?>
                <?php if (! empty($subNotes)): ?>
                    <div class="mt-1">Associate Notes: <?= esc((string) $subNotes) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Main Form Body -->
<?php
$formAction = $mode === 'create'
    ? site_url('guidance/clinical/forms/create')
    : site_url('guidance/clinical/forms/' . $instanceId . '/save');
?>
<form id="clinicalFormEditor" method="post" action="<?= $formAction ?>" class="card border-0 shadow-sm mb-3" data-background-submit>
    <?= csrf_field() ?>
    <?php if ($mode === 'create'): ?>
        <input type="hidden" name="form_key" value="<?= esc($formKey) ?>">
        <?php foreach ($contextIds as $ctxKey => $ctxVal): ?>
            <?php if (! empty($ctxVal)): ?>
                <input type="hidden" name="<?= esc($ctxKey) ?>" value="<?= (int) $ctxVal ?>">
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 p-0">Clinical Documentation Fields</h5>
        <?php if (! $isEditable): ?>
            <span class="badge bg-light text-dark border">Read-Only</span>
        <?php endif; ?>
    </div>

    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($fieldsToRender as $fieldName => $meta): ?>
                <?php
                $schemaMeta = is_array($schemaFields[$fieldName] ?? null) ? $schemaFields[$fieldName] : [];
                $type = (string) ($meta['type'] ?? $schemaMeta['type'] ?? 'string');
                $label = (string) ($meta['label'] ?? $schemaMeta['label'] ?? ucwords(str_replace('_', ' ', (string) $fieldName)));
                $required = (bool) ($meta['required'] ?? $schemaMeta['required'] ?? false);
                $encrypted = (bool) ($meta['encrypted'] ?? $schemaMeta['encrypted'] ?? false);
                $colClass = (string) ($meta['col'] ?? 'col-12');
                $val = $formData[$fieldName] ?? ($schemaMeta['default'] ?? '');
                $disabledAttr = $isEditable ? '' : 'disabled';
                $reqAttr = $required ? 'required' : '';
                ?>

                <?php if ($type === 'object'): ?>
                    <?php
                    $subProps = $meta['properties'] ?? $schemaMeta['properties'] ?? [];
                    $objVal = is_array($val) ? $val : [];
                    ?>
                    <div class="col-12">
                        <div class="border rounded p-3 bg-light-subtle">
                            <div class="fw-semibold small text-uppercase text-muted mb-2">
                                <?= esc($label) ?>
                                <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                            </div>
                            <div class="row g-2">
                                <?php foreach ($subProps as $subKey => $subMeta): ?>
                                    <?php
                                    $subLabel = (string) ($subMeta['label'] ?? ucwords(str_replace('_', ' ', (string) $subKey)));
                                    $subType = (string) ($subMeta['type'] ?? 'string');
                                    $subVal = $objVal[$subKey] ?? '';
                                    $inputId = 'field_' . $fieldName . '_' . $subKey;
                                    ?>
                                    <div class="col-md-4">
                                        <label for="<?= esc($inputId) ?>" class="form-label small fw-semibold"><?= esc($subLabel) ?></label>
                                        <?php if ($subType === 'enum'): ?>
                                            <select id="<?= esc($inputId) ?>" name="fields[<?= esc($fieldName) ?>][<?= esc($subKey) ?>]" class="form-select form-select-sm" <?= $disabledAttr ?>>
                                                <?php foreach (($subMeta['options'] ?? []) as $opt): ?>
                                                    <option value="<?= esc((string) $opt) ?>" <?= ((string) $subVal === (string) $opt) ? 'selected' : '' ?>>
                                                        <?= $opt === '' ? '-- Not Specified --' : esc(ucwords(str_replace('_', ' ', (string) $opt))) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ($subType === 'integer'): ?>
                                            <input type="number" id="<?= esc($inputId) ?>" name="fields[<?= esc($fieldName) ?>][<?= esc($subKey) ?>]" class="form-control form-control-sm" value="<?= esc((string) $subVal) ?>" <?= $disabledAttr ?>>
                                        <?php else: ?>
                                            <input type="text" id="<?= esc($inputId) ?>" name="fields[<?= esc($fieldName) ?>][<?= esc($subKey) ?>]" class="form-control form-control-sm" value="<?= esc((string) $subVal) ?>" <?= $disabledAttr ?>>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                <?php elseif ($type === 'boolean'): ?>
                    <div class="col-12">
                        <div class="form-check">
                            <input type="hidden" name="fields[<?= esc($fieldName) ?>]" value="0">
                            <input class="form-check-input" type="checkbox" id="field_<?= esc($fieldName) ?>"
                                   name="fields[<?= esc($fieldName) ?>]" value="1"
                                   <?= ! empty($val) ? 'checked' : '' ?> <?= $disabledAttr ?> <?= $reqAttr ?>>
                            <label class="form-check-label fw-semibold" for="field_<?= esc($fieldName) ?>">
                                <?= esc($label) ?>
                                <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                        </div>
                    </div>

                <?php elseif ($type === 'textarea'): ?>
                    <div class="col-12">
                        <label for="field_<?= esc($fieldName) ?>" class="form-label fw-semibold">
                            <?= esc($label) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                            <?php if ($encrypted): ?>
                                <span class="badge bg-light text-success border ms-1"><i class="bi bi-lock-fill me-1"></i>Encrypted</span>
                            <?php endif; ?>
                        </label>
                        <textarea id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]" rows="3"
                                  class="form-control" <?= $disabledAttr ?> <?= $reqAttr ?>><?= esc((string) $val) ?></textarea>
                    </div>

                <?php elseif ($type === 'enum'): ?>
                    <?php $options = $meta['options'] ?? $schemaMeta['options'] ?? []; ?>
                    <div class="<?= esc($colClass) ?>">
                        <label for="field_<?= esc($fieldName) ?>" class="form-label fw-semibold">
                            <?= esc($label) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>
                        <select id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]" class="form-select" <?= $disabledAttr ?> <?= $reqAttr ?>>
                            <?php foreach ($options as $opt): ?>
                                <option value="<?= esc((string) $opt) ?>" <?= ((string) $val === (string) $opt) ? 'selected' : '' ?>>
                                    <?= esc(ucwords(str_replace('_', ' ', (string) $opt))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                <?php elseif ($type === 'integer'): ?>
                    <div class="<?= esc($colClass) ?>">
                        <label for="field_<?= esc($fieldName) ?>" class="form-label fw-semibold">
                            <?= esc($label) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>
                        <input type="number" min="1" id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]"
                               class="form-control" value="<?= esc((string) $val) ?>" <?= $disabledAttr ?> <?= $reqAttr ?>>
                    </div>

                <?php elseif ($type === 'date'): ?>
                    <div class="<?= esc($colClass) ?>">
                        <label for="field_<?= esc($fieldName) ?>_display" class="form-label fw-semibold">
                            <?= esc($label) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>
                        <input type="text" id="field_<?= esc($fieldName) ?>_display"
                               class="form-control" data-datepicker data-target="#field_<?= esc($fieldName) ?>"
                               placeholder="DD/MM/YYYY" autocomplete="off" <?= $disabledAttr ?> <?= $reqAttr ?>>
                        <input type="hidden" id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]"
                               value="<?= esc(substr((string) $val, 0, 10)) ?>">
                    </div>

                <?php elseif ($type === 'time'): ?>
                    <div class="<?= esc($colClass) ?>">
                        <label for="field_<?= esc($fieldName) ?>_display" class="form-label fw-semibold">
                            <?= esc($label) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>
                        <input type="text" id="field_<?= esc($fieldName) ?>_display"
                               class="form-control" data-timepicker data-target="#field_<?= esc($fieldName) ?>"
                               placeholder="hh:mm A" autocomplete="off" <?= $disabledAttr ?> <?= $reqAttr ?>>
                        <input type="hidden" id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]"
                               value="<?= esc(substr((string) $val, 0, 5)) ?>">
                    </div>

                <?php elseif ($type === 'datetime'): ?>
                    <?php
                    $dtVal = (string) $val;
                    if ($dtVal !== '' && strpos($dtVal, ' ') !== false && strpos($dtVal, 'T') === false) {
                        $dtVal = str_replace(' ', 'T', substr($dtVal, 0, 16));
                    }
                    ?>
                    <div class="<?= esc($colClass) ?>">
                        <label for="field_<?= esc($fieldName) ?>_display" class="form-label fw-semibold">
                            <?= esc($label) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>
                        <input type="text" id="field_<?= esc($fieldName) ?>_display"
                               class="form-control" data-datetimepicker data-target="#field_<?= esc($fieldName) ?>"
                               placeholder="DD/MM/YYYY hh:mm A" autocomplete="off" <?= $disabledAttr ?> <?= $reqAttr ?>>
                        <input type="hidden" id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]"
                               value="<?= esc($dtVal) ?>">
                    </div>

                <?php else: ?>
                    <div class="<?= esc($colClass) ?>">
                        <label for="field_<?= esc($fieldName) ?>" class="form-label fw-semibold">
                            <?= esc($label) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>
                        <input type="text" id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]"
                               class="form-control" value="<?= esc((string) $val) ?>" <?= $disabledAttr ?> <?= $reqAttr ?>>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($isEditable): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <span class="small text-muted">
                <?= $mode === 'create'
                    ? 'Creating the draft enables PDF preview, co-sign submission, or attestation.'
                    : 'Save edits before submitting or finalizing.' ?>
            </span>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i><?= $mode === 'create' ? 'Create Encrypted Draft' : 'Save Encrypted Draft' ?>
            </button>
        </div>
    <?php endif; ?>
</form>

<!-- Role-Aware Workflow & Attestation Panels -->
<?php if ($instanceId > 0 && $status === 'draft'): ?>
    <?php if (! $canFinalizeDirectly): ?>
        <!-- Associate Action: Submit for Supervisor Co-Sign -->
        <div class="card border-warning shadow-sm mb-3">
            <div class="card-header bg-warning-subtle border-0 py-2">
                <h6 class="card-title mb-0 p-0 text-dark">
                    <i class="bi bi-send-check me-2"></i>Submit for Supervisor Co-Sign
                </h6>
            </div>
            <form method="post" action="<?= site_url('guidance/clinical/forms/' . $instanceId . '/submit-cosign') ?>"
                  class="card-body js-sync-clinical-fields" data-background-submit
                  data-confirm="Submit this clinical record to your assigned supervisor for co-signature? Direct edits will be locked while under review."
                  data-confirm-title="Submit for Co-Sign"
                  data-confirm-label="Yes, Submit"
                  data-confirm-tone="warning">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label for="submissionNotes" class="form-label small fw-semibold">Submission Notes for Assigned Supervisor (Optional)</label>
                    <textarea id="submissionNotes" name="submission_notes" rows="2" class="form-control"
                              placeholder="Highlight risk observations, clinical questions, or supervision points..."></textarea>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small text-muted">
                        As a Counselor Associate, finalized clinical records require active supervisor co-signature.
                    </span>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-send me-1"></i>Submit for Supervisor Co-Sign
                    </button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <!-- Licensed Counselor / Supervisor Action: Digitally Attest & Finalize -->
        <div class="card border-success shadow-sm mb-3">
            <div class="card-header bg-success-subtle border-0 py-2">
                <h6 class="card-title mb-0 p-0 text-success">
                    <i class="bi bi-patch-check me-2"></i>Digitally Attest &amp; Finalize
                </h6>
            </div>
            <form method="post" action="<?= site_url('guidance/clinical/forms/' . $instanceId . '/finalize') ?>"
                  class="card-body js-sync-clinical-fields" data-background-submit
                  data-confirm="Digitally attest and permanently finalize this clinical record? Once finalized, entries are locked and corrections require an addendum."
                  data-confirm-title="Digitally Attest &amp; Finalize"
                  data-confirm-label="Yes, Finalize"
                  data-confirm-tone="success">
                <?= csrf_field() ?>
                <div class="alert alert-light border small mb-3">
                    <div class="fw-semibold mb-1">Attestation Statement</div>
                    <div><?= esc($attestationStatement) ?></div>
                    <input type="hidden" name="attestation_statement" value="<?= esc($attestationStatement) ?>">
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="directAttestConfirm" name="attestation_confirmed" value="1" required>
                    <label class="form-check-label small fw-semibold" for="directAttestConfirm">
                        I attest that this clinical record is accurate, complete, and finalized under my practitioner credentials.
                    </label>
                </div>
                <div class="text-end">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check2-circle me-1"></i>Digitally Attest &amp; Finalize
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($instanceId > 0 && $status === 'pending_cosign' && $isAssignedSupervisor): ?>
    <!-- Assigned Supervisor Action: Co-Sign & Finalize -->
    <div class="card border-success shadow-sm mb-3">
        <div class="card-header bg-success-subtle border-0 py-2">
            <h6 class="card-title mb-0 p-0 text-success">
                <i class="bi bi-pen-fill me-2"></i>Supervisor Review: Co-Sign &amp; Finalize
            </h6>
        </div>
        <form method="post" action="<?= site_url('guidance/clinical/forms/' . $instanceId . '/cosign') ?>"
              class="card-body" data-background-submit
              data-confirm="Apply your supervisor co-signature and permanently finalize this clinical record? Once finalized, entries are locked."
              data-confirm-title="Co-Sign &amp; Finalize"
              data-confirm-label="Yes, Co-Sign &amp; Finalize"
              data-confirm-tone="success">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="supervisorNotes" class="form-label small fw-semibold">Supervisor Clinical Review Notes</label>
                <textarea id="supervisorNotes" name="supervisor_notes" rows="2" class="form-control"
                          placeholder="Optional supervisory feedback or addendum notes..."></textarea>
            </div>
            <div class="alert alert-light border small mb-3">
                <div class="fw-semibold mb-1">Co-Signature Attestation Statement</div>
                <div><?= esc($attestationStatement) ?></div>
                <input type="hidden" name="attestation_statement" value="<?= esc($attestationStatement) ?>">
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="coSignConfirm" name="attestation_confirmed" value="1" required>
                <label class="form-check-label small fw-semibold" for="coSignConfirm">
                    I have reviewed this clinical documentation and apply my supervisor co-signature to finalize it.
                </label>
            </div>
            <div class="text-end">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check2-all me-1"></i>Co-Sign &amp; Finalize
                </button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($status === 'finalized' && ! empty($attestations)): ?>
    <!-- Finalized Attestation Ledger -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 py-3">
            <h6 class="card-title mb-0 p-0">
                <i class="bi bi-shield-check me-2 text-success"></i>Digital Attestations &amp; Co-Sign Ledger
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Role</th>
                            <th>Practitioner / Attestor</th>
                            <th>Statement</th>
                            <th>Attested At</th>
                            <th>Signature Hash</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attestations as $att): ?>
                            <?php
                            $attName = trim((string) (($att['first_name'] ?? '') . ' ' . ($att['last_name'] ?? '')));
                            if ($attName === '') {
                                $attName = (string) ($att['username'] ?? ('User #' . (int) ($att['attested_by_user_id'] ?? 0)));
                            }
                            ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?= esc(ucwords(str_replace('_', ' ', (string) ($att['attestation_role'] ?? 'author')))) ?>
                                    </span>
                                </td>
                                <td class="small fw-semibold"><?= esc($attName) ?></td>
                                <td class="small"><?= esc((string) ($att['attestation_statement'] ?? '')) ?></td>
                                <td class="small text-muted text-nowrap">
                                    <?= ! empty($att['attested_at']) ? esc(date('M d, Y g:i A', strtotime((string) $att['attested_at']))) : '—' ?>
                                </td>
                                <td class="small font-monospace text-muted">
                                    <?= esc(substr((string) ($att['signature_hash'] ?? ''), 0, 16)) ?>...
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const editor = document.getElementById('clinicalFormEditor');
    if (!editor) return;

    document.querySelectorAll('form.js-sync-clinical-fields').forEach(function (actionForm) {
        actionForm.addEventListener('submit', function () {
            actionForm.querySelectorAll('input[data-synced-field="1"]').forEach(function (el) {
                el.remove();
            });
            const fd = new FormData(editor);
            for (const [key, val] of fd.entries()) {
                if (key.startsWith('fields[')) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = key;
                    hidden.value = String(val);
                    hidden.setAttribute('data-synced-field', '1');
                    actionForm.appendChild(hidden);
                }
            }
        }, true);
    });
});
</script>
<?= $this->endSection() ?>
