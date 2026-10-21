<div class="mt-2 small">
<?php if (in_array($question['type'], ['single', 'multiple'], true)): ?><ul class="mb-2"><?php foreach ($question['options'] as $option): ?><li><?= esc($option) ?></li><?php endforeach; ?></ul>
<?php elseif ($question['type'] === 'rating'): ?><p class="mb-2">Rating scale: 1–5</p>
<?php else: ?><p class="mb-2">Text answer, up to 2,000 characters</p><?php endif; ?>
<?php if (($reviewNote ?? '') !== ''): ?><p class="text-muted mb-0" style="white-space:pre-wrap;overflow-wrap:anywhere">Author review note: <?= esc($reviewNote) ?></p><?php endif; ?>
</div>
