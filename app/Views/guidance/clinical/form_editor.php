<?= $this->extend('layouts/app') ?>

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
$isInterview = $formKey === 'psychosocial_support_interview';
$isCounselingContact = $formKey === 'counseling_contact_sheet';
$isContactWorkspace = $formKey === 'psychosocial_support_contact' || $isCounselingContact;
$isEncounterWorkspace = $isInterview || $isContactWorkspace;
$wsPrefix = $isContactWorkspace ? 'gcw' : 'giw';
$wsClass = $isContactWorkspace ? 'guidance-contact' : 'guidance-interview';
?>

<?= $this->section('styles') ?>
<?php if ($isInterview): ?>
  <link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-interview.css'), 'attr') ?>">
<?php elseif ($isContactWorkspace): ?>
  <link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-contact.css'), 'attr') ?>">
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('page_actions') ?>
<?php if ($isEncounterWorkspace): ?><div class="ce-page-actions <?= $wsPrefix ?>-page-actions"><?php endif; ?>
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

<a href="<?= site_url('guidance/clinical/cosign-queue') ?>" class="btn <?= $isEncounterWorkspace ? 'btn-outline-secondary' : 'btn-outline-warning' ?> btn-sm">
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
<?php if ($isEncounterWorkspace): ?></div><?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($isEncounterWorkspace): ?>
<section class="guidance-ui <?= $wsClass ?> ce-encounter" aria-label="<?= esc($isCounselingContact ? 'Counseling contact workspace' : ($isContactWorkspace ? 'Psychosocial support contact workspace' : 'Psychosocial support interview workspace'), 'attr') ?>">
<?php else: ?>
<div class="guidance-ui">
<?php endif; ?>
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
        'client_type'                  => [
            'label'         => 'Type of Client',
            'type'          => 'enum',
            'options'       => ['walk_in', 'follow_up', 'referral'],
            'option_labels' => ['walk_in' => 'Walk-in', 'follow_up' => 'Follow-up', 'referral' => 'Referral'],
            'required'      => true,
            'col'           => 'col-md-4',
        ],
        'interview_date'               => ['label' => 'Date', 'type' => 'date', 'required' => true, 'col' => 'col-md-4'],
        'interview_time'               => ['label' => 'Time', 'type' => 'time', 'required' => false, 'col' => 'col-md-4'],
        'student_name'                 => ['label' => "Student's Name", 'type' => 'string', 'required' => true, 'col' => 'col-md-6'],
        'student_id'                   => ['label' => 'Student / MIS ID', 'type' => 'string', 'required' => false, 'col' => 'col-md-3'],
        'course_year'                  => ['label' => 'Grade / Strand / Course & Year', 'type' => 'string', 'required' => true, 'col' => 'col-md-3'],
        'presenting_concerns'          => [
            'label'     => '1. Issues / Concerns (How would you describe your current situation? What are you experiencing? What are your main concerns?)',
            'type'      => 'textarea',
            'encrypted' => true,
            'required'  => true,
        ],
        'concern_severity'             => [
            'label'         => '2. How serious are these matters for you at this time?',
            'type'          => 'enum',
            'options'       => ['very_serious', 'serious', 'not_so_serious'],
            'option_labels' => ['very_serious' => 'Very Serious', 'serious' => 'Serious', 'not_so_serious' => 'Not so Serious'],
            'required'      => true,
            'col'           => 'col-md-6',
        ],
        'problem_duration'             => [
            'label'     => '3. How long have these been your problem?',
            'type'      => 'textarea',
            'encrypted' => true,
            'required'  => false,
        ],
        'perceived_cause'              => [
            'label'     => '4. What do you think originally caused these problem/s?',
            'type'      => 'textarea',
            'encrypted' => true,
            'required'  => false,
        ],
        'coping_barriers'              => [
            'label'     => '5. What other things currently making it hard to deal with the problem/s?',
            'type'      => 'textarea',
            'encrypted' => true,
            'required'  => false,
        ],
        'prior_coping_attempts'        => [
            'label'     => '6. What have you already tried in order to deal with the problem/s?',
            'type'      => 'textarea',
            'encrypted' => true,
            'required'  => false,
        ],
        'desired_help'                 => [
            'label'     => '7. What do you think would help solve the problem/s? What type of help do you want?',
            'type'      => 'textarea',
            'encrypted' => true,
            'required'  => false,
        ],
        'hoped_changes'                => [
            'label'     => '8. What changes are you hoping for?',
            'type'      => 'textarea',
            'encrypted' => true,
            'required'  => false,
        ],
        'privacy_consent_acknowledged' => [
            'label'    => "I have read and understood this Privacy Notice and voluntarily give my consent to the processing of my personal or my son/daughter/ward's personal and sensitive personal information for guidance and counseling purposes.",
            'type'     => 'boolean',
            'required' => true,
        ],
    ],
    'psychosocial_support_contact' => [
        'department'                   => ['label' => 'Department', 'type' => 'string', 'required' => false, 'col' => 'col-md-4'],
        'session_date'                 => ['label' => 'Date', 'type' => 'date', 'required' => true, 'col' => 'col-md-4'],
        'student_id'                   => ['label' => 'Student / MIS ID', 'type' => 'string', 'required' => false, 'col' => 'col-md-4'],
        'student_name'                 => ['label' => 'Name of Student', 'type' => 'string', 'required' => true, 'col' => 'col-md-6'],
        'course_year'                  => ['label' => 'Course / Year / Grade / Section', 'type' => 'string', 'required' => true, 'col' => 'col-md-6'],
        'time_started'                 => ['label' => 'Time Started', 'type' => 'time', 'required' => false, 'col' => 'col-md-3'],
        'time_ended'                   => ['label' => 'Time Ended', 'type' => 'time', 'required' => false, 'col' => 'col-md-3'],
        'referral_source'              => [
            'label'         => 'Contact / Referral Source',
            'type'          => 'enum',
            'options'       => ['walk_in', 'counselor_associate_initiated', 'referral', 'follow_up'],
            'option_labels' => [
                'walk_in'                       => 'Walk-in',
                'counselor_associate_initiated' => 'Counselor Associate-initiated',
                'referral'                      => 'Referral',
                'follow_up'                     => 'Follow-up',
            ],
            'required'      => true,
            'col'           => 'col-md-3',
        ],
        'session_type'                 => [
            'label'         => 'Type of Session',
            'type'          => 'enum',
            'options'       => ['intake', 'follow_up', 'crisis', 'individual', 'group'],
            'option_labels' => [
                'intake'     => 'Intake',
                'follow_up'  => 'Follow-up',
                'crisis'     => 'Crisis',
                'individual' => 'Individual',
                'group'      => 'Group',
            ],
            'required'      => true,
            'col'           => 'col-md-3',
        ],
        'presenting_concerns'          => ['label' => 'Presenting Concern/s', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'predisposing_factors'         => ['label' => 'Predisposing Factors', 'type' => 'textarea', 'encrypted' => true, 'required' => false, 'col' => 'col-md-6'],
        'precipitating_factors'        => ['label' => 'Precipitating Factors', 'type' => 'textarea', 'encrypted' => true, 'required' => false, 'col' => 'col-md-6'],
        'perpetuating_factors'         => ['label' => 'Perpetuating Factors', 'type' => 'textarea', 'encrypted' => true, 'required' => false, 'col' => 'col-md-6'],
        'protective_risk_factors'      => ['label' => 'Protective / Risk Factors', 'type' => 'textarea', 'encrypted' => true, 'required' => false, 'col' => 'col-md-6'],
        'intervention_plan'            => ['label' => 'Plans — Intervention', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'follow_up_plan'               => ['label' => 'Plans — Follow up', 'type' => 'textarea', 'encrypted' => true, 'required' => false],
        'next_session_schedule'        => ['label' => 'Schedule for Next Session (Date and Time)', 'type' => 'datetime', 'required' => false, 'col' => 'col-md-6'],
        'privacy_consent_acknowledged' => [
            'label'    => "I have read and understood this Privacy Notice and voluntarily give my consent to the processing of my personal or my son/daughter/ward's personal and sensitive personal information for guidance and counseling purposes.",
            'type'     => 'boolean',
            'required' => true,
        ],
    ],
    'counseling_contact_sheet' => [
        'department'                   => ['label' => 'Department', 'type' => 'string', 'required' => false, 'col' => 'col-md-4'],
        'session_date'                 => ['label' => 'Date', 'type' => 'date', 'required' => true, 'col' => 'col-md-4'],
        'student_id'                   => ['label' => 'Student / MIS ID', 'type' => 'string', 'required' => false, 'col' => 'col-md-4'],
        'student_name'                 => ['label' => 'Name of Student', 'type' => 'string', 'required' => true, 'col' => 'col-md-6'],
        'course_year'                  => ['label' => 'Course / Year / Grade / Section', 'type' => 'string', 'required' => true, 'col' => 'col-md-6'],
        'time_started'                 => ['label' => 'Time Started', 'type' => 'time', 'required' => false, 'col' => 'col-md-3'],
        'time_ended'                   => ['label' => 'Time Ended', 'type' => 'time', 'required' => false, 'col' => 'col-md-3'],
        'referral_source'              => [
            'label'         => 'Contact / Referral Source',
            'type'          => 'enum',
            'options'       => ['walk_in', 'counselor_initiated', 'referral', 'follow_up'],
            'option_labels' => [
                'walk_in'             => 'Walk-in',
                'counselor_initiated' => 'Counselor-initiated',
                'referral'            => 'Referral',
                'follow_up'           => 'Follow-up',
            ],
            'required'      => true,
            'col'           => 'col-md-3',
        ],
        'session_type'                 => [
            'label'         => 'Type of Session',
            'type'          => 'enum',
            'options'       => ['intake', 'follow_up', 'crisis', 'individual', 'group'],
            'option_labels' => [
                'intake'     => 'Intake',
                'follow_up'  => 'Follow-up',
                'crisis'     => 'Crisis',
                'individual' => 'Individual',
                'group'      => 'Group',
            ],
            'required'      => true,
            'col'           => 'col-md-3',
        ],
        'presenting_concerns'          => ['label' => 'Presenting Concern/s', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'predisposing_factors'         => ['label' => 'Predisposing Factors', 'type' => 'textarea', 'encrypted' => true, 'required' => false, 'col' => 'col-md-6'],
        'precipitating_factors'        => ['label' => 'Precipitating Factors', 'type' => 'textarea', 'encrypted' => true, 'required' => false, 'col' => 'col-md-6'],
        'perpetuating_factors'         => ['label' => 'Perpetuating Factors', 'type' => 'textarea', 'encrypted' => true, 'required' => false, 'col' => 'col-md-6'],
        'protective_risk_factors'      => ['label' => 'Protective / Risk Factors', 'type' => 'textarea', 'encrypted' => true, 'required' => false, 'col' => 'col-md-6'],
        'intervention_plan'            => ['label' => 'Plans — Intervention', 'type' => 'textarea', 'encrypted' => true, 'required' => true],
        'follow_up_plan'               => ['label' => 'Plans — Follow up', 'type' => 'textarea', 'encrypted' => true, 'required' => false],
        'next_session_schedule'        => ['label' => 'Schedule for Next Session (Date and Time)', 'type' => 'datetime', 'required' => false, 'col' => 'col-md-6'],
        'privacy_consent_acknowledged' => [
            'label'    => "I have read and understood this Privacy Notice and voluntarily give my consent to the processing of my personal or my son/daughter/ward's personal and sensitive personal information for guidance and counseling purposes.",
            'type'     => 'boolean',
            'required' => true,
        ],
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
<?php if ($isEncounterWorkspace): ?>
  <?php
  $statusDot = match ($status) {
      'pending_cosign' => 'ce-status-amber',
      'finalized' => 'ce-status-emerald',
      default => 'ce-status-slate',
  };
  $clientLabel = $personName !== '' ? $personName : (!empty($contextIds['person_id']) ? 'Person #' . (int) $contextIds['person_id'] : ($isCounselingContact ? 'New counseling contact' : ($isContactWorkspace ? 'New client contact' : 'New client interview')));
  $initials = '';
  foreach (array_slice(explode(' ', $personName), 0, 2) as $namePart) {
      $initials .= strtoupper(substr($namePart, 0, 1));
  }
  $authorName = $authorInfo !== null ? (trim((string) (($authorInfo['first_name'] ?? '') . ' ' . ($authorInfo['last_name'] ?? ''))) ?: (string) ($authorInfo['username'] ?? '')) : '';
  $supervisorName = $supervisorInfo !== null ? (trim((string) (($supervisorInfo['first_name'] ?? '') . ' ' . ($supervisorInfo['last_name'] ?? ''))) ?: (string) ($supervisorInfo['username'] ?? '')) : '';
  ?>
  <div class="ce-patient-card <?= $wsPrefix ?>-identity">
    <div class="ce-patient-top">
      <div class="ce-patient-identity">
        <?php
          $giwTenantId = function_exists('session') ? (int) (session()->get('user')['tenant_id'] ?? 0) : 0;
          $giwPersonId = (int) ($person['id'] ?? $contextIds['person_id'] ?? 0);
          $giwPhotoUrl = ($giwTenantId > 0 && class_exists(\App\Services\ProfilePhotoService::class))
            ? (new \App\Services\ProfilePhotoService())->personPhotoUrl($giwTenantId, $giwPersonId)
            : null;
          $giwInitials = $initials !== '' ? $initials : ($isCounselingContact ? 'CC' : ($isContactWorkspace ? 'PC' : 'PI'));
        ?>
        <span class="ce-avatar" aria-hidden="true"><?= class_exists(\App\Services\ProfileAvatar::class) ? \App\Services\ProfileAvatar::contentHtml($giwPhotoUrl, $giwInitials, is_array($person ?? null) ? $person : ['name' => $clientLabel]) : esc($giwInitials) ?></span>
        <div class="<?= $wsPrefix ?>-identity-copy">
          <div class="<?= $wsPrefix ?>-form-reference">
            <span class="ce-mono"><?= esc((string) ($definition['form_code'] ?? $formKey)) ?></span>
            <span class="ce-tag ce-mono"><?= esc($instanceId > 0 ? (string) ($instance['instance_number'] ?? ('#' . $instanceId)) : 'New Draft') ?></span>
          </div>
          <h2 class="ce-patient-name"><?= esc($clientLabel) ?></h2>
          <div class="ce-patient-meta">
            <?php if (!empty($person['mis_id'])): ?><span class="ce-mono"><?= esc((string) $person['mis_id']) ?></span><?php endif; ?>
            <span><?= esc($isCounselingContact ? 'Counseling Contact Form' : ($isContactWorkspace ? 'Psychosocial Support Contact' : 'Psychosocial Support Interview')) ?></span>
          </div>
        </div>
      </div>
      <span class="ce-tag <?= $wsPrefix ?>-status"><span class="ce-status-dot <?= $statusDot ?>"><?= esc($stMeta['label']) ?></span></span>
    </div>
    <dl class="<?= $wsPrefix ?>-context">
      <div>
        <dt>Record context</dt>
        <dd class="<?= $wsPrefix ?>-context-links">
          <?php if ($caseId > 0): ?><a href="<?= esc(site_url('guidance/cases/' . $caseId), 'attr') ?>" class="ce-mono">Case #<?= $caseId ?></a><?php endif; ?>
          <?php if ($episodeId > 0): ?><a href="<?= esc(site_url('guidance/episodes/' . $episodeId), 'attr') ?>" class="ce-mono">Episode #<?= $episodeId ?></a><?php endif; ?>
          <?php if (!empty($contextIds['session_id'])): ?><span class="ce-mono">Session #<?= (int) $contextIds['session_id'] ?></span><?php endif; ?>
          <?php if ($caseId <= 0 && $episodeId <= 0 && empty($contextIds['session_id'])): ?><span><?= esc($isCounselingContact ? 'Counseling contact' : ($isContactWorkspace ? 'Psychosocial contact' : 'Client interview')) ?></span><?php endif; ?>
        </dd>
      </div>
      <div><dt>Author</dt><dd><?= esc($authorName !== '' ? $authorName : 'Not yet recorded') ?></dd></div>
      <div><dt>Assigned supervisor</dt><dd><?= esc($supervisorName !== '' ? $supervisorName : ($isAssociate && $activeSupervision === null ? 'No active assignment' : 'Not recorded')) ?></dd></div>
      <div><dt>Your credential tier</dt><dd><?= esc($tier !== '' ? ucwords(str_replace('_', ' ', $tier)) : 'Not recorded') ?></dd></div>
    </dl>
  </div>

  <div class="ce-notice ce-notice-sky">
    <div class="ce-notice-body">
      <i class="bi bi-shield-lock ce-notice-icon" aria-hidden="true"></i>
      <span><strong>Confidential clinical documentation</strong> — Sensitive narrative fields are encrypted at rest. PDF preview uses the saved record; it does not include unsaved edits.</span>
    </div>
  </div>
  <?php if ($status === 'pending_cosign'): ?>
    <?php
    $submittedAt = $instance['submitted_for_cosign_at'] ?? $draft['submitted_for_cosign_at'] ?? null;
    $subNotes = $instance['submission_notes'] ?? $draft['submission_notes'] ?? null;
    ?>
    <div class="ce-notice ce-notice-amber">
      <div class="ce-notice-body">
        <i class="bi bi-hourglass-split ce-notice-icon" aria-hidden="true"></i>
        <div><strong>Awaiting Supervisor Co-Sign</strong>
          <?php if (!empty($submittedAt)): ?><span class="ce-mono"> · <?= esc(date('M d, Y g:i A', strtotime((string) $submittedAt))) ?></span><?php endif; ?>
          <div class="<?= $wsPrefix ?>-notice-detail">The submitted documentation is locked during review.</div>
          <?php if (!empty($subNotes)): ?><div class="<?= $wsPrefix ?>-submission-notes"><strong>Associate Notes:</strong> <?= esc((string) $subNotes) ?></div><?php endif; ?>
        </div>
      </div>
    </div>
  <?php elseif ($status === 'finalized'): ?>
    <div class="ce-notice ce-notice-emerald"><div class="ce-notice-body"><i class="bi bi-patch-check ce-notice-icon" aria-hidden="true"></i><span><strong>Finalized &amp; Attested</strong> — This record is read-only. Corrections follow the existing addendum process.</span></div></div>
  <?php endif; ?>
<?php else: ?>
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

            <div class="small text-lg-end text-muted">
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

<?php endif; ?>

<!-- Main Form Body -->
<?php
$formAction = $mode === 'create'
    ? site_url('guidance/clinical/forms/create')
    : site_url('guidance/clinical/forms/' . $instanceId . '/save');
$interviewSections = [
    'giw-consent'  => ['title' => 'Privacy Notice & Informed Consent', 'hint' => 'Review the Foundation University OGC Data Privacy Act (RA 10173) Notice and confirm client consent.', 'fields' => ['privacy_consent_acknowledged']],
    'giw-client'   => ['title' => 'Client & Interview Details', 'hint' => 'Record client type, interview date and time, student name, and grade/strand/course & year.', 'fields' => ['client_type', 'interview_date', 'interview_time', 'student_name', 'student_id', 'course_year']],
    'giw-concerns' => ['title' => '1–3. Issues, Severity & Duration', 'hint' => 'Document the student’s main issues/concerns, perceived seriousness, and how long the problem has persisted.', 'fields' => ['presenting_concerns', 'concern_severity', 'problem_duration']],
    'giw-context'  => ['title' => '4–6. Causes, Barriers & Prior Coping', 'hint' => 'Record perceived original causes, current difficulties making coping hard, and what has already been tried.', 'fields' => ['perceived_cause', 'coping_barriers', 'prior_coping_attempts']],
    'giw-goals'    => ['title' => '7–8. Desired Help & Hoped Changes', 'hint' => 'Document what would help solve the problem, type of help wanted, and hoped-for changes.', 'fields' => ['desired_help', 'hoped_changes']],
];

$contactSections = [
    'gcw-consent'  => ['title' => 'Privacy Notice & Informed Consent', 'hint' => 'Review the Foundation University OGC Data Privacy Act (RA 10173) Notice and confirm client consent.', 'fields' => ['privacy_consent_acknowledged']],
    'gcw-session'  => ['title' => 'Session & Student Details', 'hint' => 'Record department, date, student name, course/year/grade/section, time started/ended, contact source, and type of session.', 'fields' => ['department', 'session_date', 'student_name', 'student_id', 'course_year', 'time_started', 'time_ended', 'referral_source', 'session_type']],
    'gcw-summary'  => ['title' => $isCounselingContact ? 'Summary of Counseling Session' : 'Summary of Psychosocial Support Session', 'hint' => 'Document presenting concerns and the four contextual factors (predisposing, precipitating, perpetuating, protective/risk).', 'fields' => ['presenting_concerns', 'predisposing_factors', 'precipitating_factors', 'perpetuating_factors', 'protective_risk_factors']],
    'gcw-plans'    => ['title' => 'Plans & Next Session', 'hint' => 'Record intervention plan, follow-up plan, and schedule for the next session.', 'fields' => ['intervention_plan', 'follow_up_plan', 'next_session_schedule']],
];
$encounterSections = $isInterview ? $interviewSections : ($isContactWorkspace ? $contactSections : []);
?>
<?php if ($isEncounterWorkspace): ?>
  <nav class="<?= $wsPrefix ?>-section-nav" aria-label="<?= $isContactWorkspace ? 'Contact documentation sections' : 'Interview documentation sections' ?>">
    <?php foreach ($encounterSections as $sectionId => $sectionMeta): ?>
      <a href="#<?= esc($sectionId, 'attr') ?>"><?= esc($sectionMeta['title']) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="<?= $wsPrefix ?>-layout">
<?php endif; ?>
<form id="clinicalFormEditor" method="post" action="<?= esc($formAction, 'attr') ?>" class="<?= $isEncounterWorkspace ? $wsPrefix . '-documentation' : 'card border-0 shadow-sm mb-3' ?>" data-background-submit>
    <?= csrf_field() ?>
    <?php if ($mode === 'create'): ?>
        <input type="hidden" name="form_key" value="<?= esc($formKey) ?>">
        <?php foreach ($contextIds as $ctxKey => $ctxVal): ?>
            <?php if (! empty($ctxVal)): ?>
                <input type="hidden" name="<?= esc($ctxKey) ?>" value="<?= (int) $ctxVal ?>">
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($isEncounterWorkspace): ?>
      <div class="<?= $wsPrefix ?>-document-heading">
        <h2 class="ce-card-title"><?= esc($isCounselingContact ? 'Counseling Contact Form (F-OGC-GCS-CCF-009-00)' : ($isContactWorkspace ? 'Psychosocial Support Contact Form (F-OGC-GPS-PCF-008-00)' : 'Psychosocial Support Interview Form (F-OGC-GPS-PIF-008-00)')) ?></h2>
        <span class="ce-tag"><?= $isEditable ? 'Required fields marked *' : 'Read-Only' ?></span>
      </div>
      <?php foreach ($encounterSections as $sectionId => $sectionMeta): ?>
        <section class="ce-card <?= $wsPrefix ?>-section" id="<?= esc($sectionId, 'attr') ?>" aria-labelledby="<?= esc($sectionId . '-heading', 'attr') ?>">
          <div class="ce-card-header">
            <div>
              <h3 class="ce-card-title" id="<?= esc($sectionId . '-heading', 'attr') ?>"><?= esc($sectionMeta['title']) ?></h3>
              <p class="<?= $wsPrefix ?>-section-hint"><?= esc($sectionMeta['hint']) ?></p>
            </div>
          </div>
          <div class="ce-card-body">
            <?php if (($sectionId === 'giw-consent' || $sectionId === 'gcw-consent')): ?>
              <div class="alert alert-light border small mb-3">
                <p class="mb-2">In accordance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong>, the University Guidance Center collects and processes your personal information or your son/daughter/ward's personal information and, when necessary, sensitive personal information for the purposes of providing counseling services, conducting assessments or interventions, maintaining records, and coordinating with authorized University officials for student welfare.</p>
                <p class="mb-2">Processing is based on the University's legitimate educational mandate and/or your consent, particularly when sensitive personal information is involved. The data will be kept confidential and accessed only by authorized personnel. It will be stored securely, retained in accordance with University policies, and disclosed only with your consent or as required by law.</p>
                <p class="mb-0">You have the right to be informed, access, correct, object to processing, and request the erasure of your personal data or your son/daughter/ward's personal data, subject to applicable legal limitations. For privacy-related concerns, you may contact the University Data Protection Officer (DPO): <strong>dpo@foundationu.com</strong>. <a href="<?= esc(site_url('privacy-policy'), 'attr') ?>" target="_blank" rel="noopener noreferrer" data-navigation="false" class="ms-1">Read full Data Privacy Policy</a>.</p>
              </div>
            <?php endif; ?>
            <div class="row g-3">
              <?php foreach ($sectionMeta['fields'] as $fieldName): ?>
                <?= view('guidance/clinical/_field', ['fieldName' => $fieldName, 'meta' => $fieldsToRender[$fieldName], 'schemaFields' => $schemaFields, 'formData' => $formData, 'isEditable' => $isEditable, 'isInterview' => $isInterview, 'isContactWorkspace' => $isContactWorkspace, 'wsPrefix' => $wsPrefix], ['saveData' => false]) ?>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php endforeach; ?>
    <?php elseif (!$isEncounterWorkspace): ?>
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 p-0">Clinical Documentation Fields</h5>
        <?php if (! $isEditable): ?>
            <span class="badge bg-light text-dark border">Read-Only</span>
        <?php endif; ?>
    </div>

    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($fieldsToRender as $fieldName => $meta): ?>
              <?= view('guidance/clinical/_field', ['fieldName' => $fieldName, 'meta' => $meta, 'schemaFields' => $schemaFields, 'formData' => $formData, 'isEditable' => $isEditable, 'isInterview' => false], ['saveData' => false]) ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isEditable): ?>
        <div class="<?= $isEncounterWorkspace ? 'ce-card ' . $wsPrefix . '-save-bar' : 'card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2' ?>">
            <span class="small text-muted">
                <?= $mode === 'create'
                    ? 'Creating the draft enables PDF preview, co-sign submission, or attestation.'
                    : 'Save edits before submitting or finalizing.' ?>
            </span>
            <button type="submit" class="btn btn-primary w-100 w-sm-auto">
                <i class="bi bi-save me-1" aria-hidden="true"></i> <?= $mode === 'create' ? 'Create Encrypted Draft' : 'Save Encrypted Draft' ?>
            </button>
        </div>
    <?php endif; ?>
</form>

<!-- Role-Aware Workflow & Attestation Panels -->
<?php if ($isEncounterWorkspace): ?>
<aside class="<?= $wsPrefix ?>-review" aria-label="Review and attestation">
  <div class="ce-card <?= $wsPrefix ?>-review-context">
    <div class="ce-card-header"><h2 class="ce-card-title"><i class="bi bi-shield-check" aria-hidden="true"></i> Record workflow</h2></div>
    <div class="ce-card-body">
      <span class="ce-status-dot <?= $statusDot ?>"><?= esc($stMeta['label']) ?></span>
      <p class="<?= $wsPrefix ?>-review-hint">
        <?= $mode === 'create' ? 'Create the encrypted draft to enable PDF preview and review actions.' : ($status === 'finalized' ? 'This documentation is locked. The attestation ledger records its finalized review.' : ($status === 'pending_cosign' ? ($isAssignedSupervisor ? 'Review the submitted documentation before applying your co-signature.' : 'You can inspect this submission. Co-signature belongs to its eligible assigned supervisor.') : 'Save your draft independently of submission or finalization. Review actions include the current documentation fields.')) ?>
      </p>
    </div>
  </div>
<?php endif; ?>
<?php if ($instanceId > 0 && $status === 'draft'): ?>
    <?php if (! $canFinalizeDirectly): ?>
        <!-- Associate Action: Submit for Supervisor Co-Sign -->
        <div class="<?= $isEncounterWorkspace ? 'ce-card ' . $wsPrefix . '-workflow-card' : 'card border-warning shadow-sm mb-3' ?>">
            <div class="<?= $isEncounterWorkspace ? 'ce-card-header' : 'card-header bg-warning-subtle border-0 py-2' ?>">
                <h6 class="<?= $isEncounterWorkspace ? 'ce-card-title' : 'card-title mb-0 p-0 text-dark' ?>">
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
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="small text-muted">
                        As a Counselor Associate, finalized clinical records require active supervisor co-signature.
                    </span>
                    <button type="submit" class="btn btn-warning w-100 w-sm-auto">
                        <i class="bi bi-send me-1"></i>Submit for Supervisor Co-Sign
                    </button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <!-- Licensed Counselor / Supervisor Action: Digitally Attest & Finalize -->
        <div class="<?= $isEncounterWorkspace ? 'ce-card ' . $wsPrefix . '-workflow-card' : 'card border-success shadow-sm mb-3' ?>">
            <div class="<?= $isEncounterWorkspace ? 'ce-card-header' : 'card-header bg-success-subtle border-0 py-2' ?>">
                <h6 class="<?= $isEncounterWorkspace ? 'ce-card-title' : 'card-title mb-0 p-0 text-success-emphasis' ?>">
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
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-success w-100 w-sm-auto">
                        <i class="bi bi-check2-circle me-1"></i>Digitally Attest &amp; Finalize
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($instanceId > 0 && $status === 'pending_cosign' && $isAssignedSupervisor): ?>
    <!-- Assigned Supervisor Action: Co-Sign & Finalize -->
    <div class="<?= $isEncounterWorkspace ? 'ce-card ' . $wsPrefix . '-workflow-card' : 'card border-success shadow-sm mb-3' ?>">
        <div class="<?= $isEncounterWorkspace ? 'ce-card-header' : 'card-header bg-success-subtle border-0 py-2' ?>">
            <h6 class="<?= $isEncounterWorkspace ? 'ce-card-title' : 'card-title mb-0 p-0 text-success-emphasis' ?>">
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
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-success w-100 w-sm-auto">
                    <i class="bi bi-check2-all me-1"></i>Co-Sign &amp; Finalize
                </button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($status === 'finalized' && ! empty($attestations)): ?>
    <!-- Finalized Attestation Ledger -->
    <div class="<?= $isEncounterWorkspace ? 'ce-card ' . $wsPrefix . '-attestation-ledger' : 'card border-0 shadow-sm' ?>">
        <div class="<?= $isEncounterWorkspace ? 'ce-card-header' : 'card-header bg-white border-0 py-3' ?>">
            <h6 class="<?= $isEncounterWorkspace ? 'ce-card-title' : 'card-title mb-0 p-0' ?>">
                <i class="bi bi-shield-check me-2 text-success" aria-hidden="true"></i>Digital Attestations &amp; Co-Sign Ledger
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 <?= $isEncounterWorkspace ? 'ce-table ' . $wsPrefix . '-attestations-table' : '' ?>">
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
                                <td data-label="Role">
                                    <span class="badge bg-light text-dark border">
                                        <?= esc(ucwords(str_replace('_', ' ', (string) ($att['attestation_role'] ?? 'author')))) ?>
                                    </span>
                                </td>
                                <td data-label="Practitioner / Attestor" class="small fw-semibold"><?= esc($attName) ?></td>
                                <td data-label="Statement" class="small"><?= esc((string) ($att['attestation_statement'] ?? '')) ?></td>
                                <td data-label="Attested At" class="small text-muted text-md-nowrap">
                                    <?= ! empty($att['attested_at']) ? esc(date('M d, Y g:i A', strtotime((string) $att['attested_at']))) : '—' ?>
                                </td>
                                <td data-label="Signature Hash" class="small font-monospace text-muted">
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
<?php if ($isEncounterWorkspace): ?>
</aside>
</div>
</section>
<?php elseif (!$isEncounterWorkspace): ?>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($isInterview): ?>
<script src="<?= esc(base_url('assets/js/pages/guidance-interview.js'), 'attr') ?>"></script>
<?php elseif ($isContactWorkspace): ?>
<script src="<?= esc(base_url('assets/js/pages/guidance-contact.js'), 'attr') ?>"></script>
<?php else: ?>
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
<?php endif; ?>
<?= $this->endSection() ?>
