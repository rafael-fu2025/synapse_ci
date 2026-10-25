<section class="card bmg-panel mb-4" id="bmg-setup" aria-labelledby="bmg-setup-title" aria-describedby="bmg-setup-help">
  <div class="card-header py-3 bmg-panel-heading"><h2 class="card-title mb-0 p-0" id="bmg-setup-title">Drum setup checklist</h2></div>
  <div class="card-body pt-3 bmg-panel-body">
    <p class="small text-muted" id="bmg-setup-help">Use saved configuration to plan setup. This checklist does not verify firmware, physical safety, connectivity or motor operation. Nothing is enabled automatically.</p>
    <ol class="ps-3 mb-0">
      <li class="mb-3">
        <h3 class="h6">Configure drum <span class="badge bg-light text-dark border">Saved record</span></h3>
        <p class="small mb-2"><?= esc($unit['unit_code']) ?> · <?= esc($unit['drum_capacity_kg']) ?> kg · <?= esc(ucfirst($unit['status'])) ?>. Review the drum details; active status is configuration, not proof of operation.</p>
        <?php if ($configure): ?><button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#unitModal">Configure drum</button><?php else: ?><p class="small text-muted mb-0">A configuration admin must make changes.</p><?php endif; ?>
      </li>
      <li class="mb-3">
        <h3 class="h6">Register controller <span class="badge bg-light text-dark border"><?= $device === null ? 'Not registered' : 'Binding saved' ?></span></h3>
        <p class="small mb-2">Registration disables saved schedule intent and cancels pending commands. A binding does not establish that a controller is online or a motor is running.</p>
        <a href="#bmg-device">Review device registration</a>
        <?php if ($configure && $device === null): ?><button class="btn btn-outline-primary btn-sm ms-2" type="button" data-bs-toggle="modal" data-bs-target="#deviceModal" aria-describedby="deviceGateHelp">Register controller</button><?php endif; ?>
      </li>
      <li class="mb-3">
        <h3 class="h6">USB commissioning <span class="badge bg-light text-dark border">Optional · not verified</span></h3>
        <p class="small mb-2">If needed, use only approved firmware and the existing commissioning authorization. Physically isolate motor power before opening a USB port: opening or identifying can reset the controller. Keep the hardware gate off until firmware and safety verification is complete. USB release is not an emergency stop.</p>
        <a href="#bmg-device"><?= !empty($canUsb) ? 'Find USB installer & serial monitor' : 'Review device setup; USB commissioning requires separate access' ?></a>
      </li>
      <li class="mb-3">
        <h3 class="h6">Review schedule <span class="badge bg-light text-dark border"><?= $schedule['times'] === [] ? 'No daily times saved' : 'Saved intent to review' ?></span></h3>
        <p class="small mb-2"><?= count($schedule['times']) ?> daily times · <?= (int) $schedule['duration_seconds'] ?> seconds per run · Asia/Manila (UTC+08:00). A saved schedule is not proof that the device has applied it.</p>
        <a href="#bmg-schedule">Review daily rotation schedule</a>
      </li>
      <li>
        <h3 class="h6">Deliberately enable rotation <span class="badge bg-light text-dark border"><?= !empty($schedule['enabled']) ? 'Saved intent enabled' : 'Saved intent disabled' ?></span></h3>
        <p class="small mb-2">Only enable after local safety verification and schedule review. Enabling requires configuration and hardware-control permission, both server hardware gates, an active drum and a bound device. The server rechecks authorization when saving. Enabled intent does not prove rotation or a safe motor state.</p>
        <?php if ($canCommand): ?>
        <a href="#bmg-schedule">Review the enable checkbox and explicitly save the schedule</a>
        <?php else: ?>
        <p class="small text-muted mb-2">Enabling is unavailable with the current permissions, server gates or saved drum/device configuration. Ask an authorized admin to review prerequisites; this checklist cannot bypass them.</p>
        <a href="#bmg-schedule">Review saved schedule intent</a>
        <?php endif; ?>
      </li>
    </ol>
  </div>
</section>
