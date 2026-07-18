<?php $flashes = getFlashes(); ?>
<?php foreach ($flashes as $f): ?>
  <?php
    $cls = match ($f['type']) {
        'error' => 'danger',
        'success' => 'success',
        'warning' => 'warning',
        default => 'info',
    };
  ?>
  <div class="alert alert-<?= e($cls) ?> alert-dismissible fade show" role="alert">
    <?= e($f['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endforeach; ?>
