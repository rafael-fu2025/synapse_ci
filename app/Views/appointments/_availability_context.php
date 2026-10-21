<?php $context = $workspaceContext ?? \App\Services\Scheduling\AvailabilityWorkspaceContext::DEFAULTS; ?>
<?php foreach (['q', 'provider', 'day', 'section'] as $contextKey): ?>
<input type="hidden" name="return_<?= $contextKey ?>" value="<?= esc((string) ($contextKey === 'section' ? ($returnSection ?? $context['section']) : $context[$contextKey])) ?>">
<?php endforeach; ?>
