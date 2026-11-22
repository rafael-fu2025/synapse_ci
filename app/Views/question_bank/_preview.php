<div class="qb-preview">
  <?php if (in_array($question['type'], ['single', 'multiple'], true)): ?>
    <p class="qb-preview-label"><?= $question['type'] === 'multiple' ? 'Select all that apply' : 'Select one answer' ?></p>
    <ul class="qb-preview-options <?= $question['type'] === 'multiple' ? 'is-multiple' : '' ?>">
      <?php foreach ($question['options'] as $option): ?>
        <li><?= esc($option) ?></li>
      <?php endforeach; ?>
      <?php if (!empty($question['allow_other'])): ?>
        <li class="qb-preview-other">Other (please specify)</li>
      <?php endif; ?>
    </ul>
  <?php elseif ($question['type'] === 'rating'): ?>
    <?php
      $minAnchor = trim((string) ($question['scale_min_label'] ?? ''));
      $maxAnchor = trim((string) ($question['scale_max_label'] ?? ''));
    ?>
    <p class="qb-preview-label">Rating scale: 1–5<?php if ($minAnchor !== '' || $maxAnchor !== ''): ?> (<?= $minAnchor !== '' ? '1 = ' . esc($minAnchor) : '1' ?>, <?= $maxAnchor !== '' ? '5 = ' . esc($maxAnchor) : '5' ?>)<?php endif; ?></p>
    <div class="qb-preview-scale" aria-hidden="true">
      <?php for ($rating = 1; $rating <= 5; $rating++): ?><span><?= $rating ?></span><?php endfor; ?>
    </div>
  <?php else: ?>
    <p class="qb-preview-label">Text answer, up to 2,000 characters</p>
    <div class="qb-preview-text" aria-hidden="true">Respondent's written answer</div>
  <?php endif; ?>
  <?php if (($reviewNote ?? '') !== ''): ?>
    <div class="qb-preview-note">
      <strong>Author review note</strong>
      <p><?= esc($reviewNote) ?></p>
    </div>
  <?php endif; ?>
</div>
