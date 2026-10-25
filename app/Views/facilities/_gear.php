<div class="bmg-gear" data-bmg-gear data-gear-seed="<?= (int) ($gearSeed ?? 0) ?>" aria-hidden="true"
     title="Illustration only — motor state and RPM are not measured.">
  <img class="bmg-drum-art-light" src="<?= esc(base_url('drum-maroon.png'), 'attr') ?>" alt="" draggable="false">
  <img class="bmg-drum-art-dark" src="<?= esc(base_url('drum-whte.png'), 'attr') ?>" alt="" draggable="false">
  <canvas data-bmg-gear-canvas width="192" height="192" hidden></canvas>
</div>
