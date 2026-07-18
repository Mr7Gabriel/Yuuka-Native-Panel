<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

$summary = SystemService::summary();
$services = SystemService::serviceStatuses();
$nodejsStatus = NodeService::combinedStatus();
$websiteCount = count(NginxService::listWebsites());
$dbCount = count(DatabaseService::listRegistry());
$cloudflare = CloudflareService::status();

$pageTitle = 'Dashboard';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-0">Dashboard</h4>
    <p class="text-muted mb-0">Ringkasan status server secara real-time</p>
  </div>
  <div id="statsBlock" data-refresh-url="/ajax_stats.php" data-refresh-interval="5000"></div>
</div>

<div class="row g-3 mb-4" id="statCards">
  <div class="col-6 col-lg-3">
    <div class="card stat-card">
      <div class="card-body">
        <div class="text-muted small">CPU Usage</div>
        <div class="stat-value" id="cpuValue"><?= e((string) $summary['cpu_percent']) ?>%</div>
        <div class="progress mt-2" style="height:6px;"><div class="progress-bar bg-primary" id="cpuBar" style="width:<?= (float)$summary['cpu_percent'] ?>%"></div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card">
      <div class="card-body">
        <div class="text-muted small">RAM Usage</div>
        <div class="stat-value" id="ramValue"><?= e((string) $summary['ram']['percent']) ?>%</div>
        <div class="small text-muted" id="ramDetail"><?= e((string) $summary['ram']['used_mb']) ?> / <?= e((string) $summary['ram']['total_mb']) ?> MB</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card">
      <div class="card-body">
        <div class="text-muted small">Disk Usage</div>
        <div class="stat-value" id="diskValue"><?= e((string) $summary['disk']['percent']) ?>%</div>
        <div class="small text-muted" id="diskDetail"><?= e((string) $summary['disk']['used_gb']) ?> / <?= e((string) $summary['disk']['total_gb']) ?> GB</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card">
      <div class="card-body">
        <div class="text-muted small">Load Average</div>
        <div class="stat-value" id="loadValue"><?= e(implode(' / ', array_map(fn($v) => round($v, 2), $summary['load']))) ?></div>
        <div class="small text-muted">Uptime: <?= e($summary['uptime']) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Status Layanan</div>
      <div class="card-body">
        <ul class="list-group list-group-flush">
          <?php foreach ($services as $name => $status): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
              <span><?= e($name) ?></span>
              <span><span class="status-dot <?= e($status) ?>"></span><?= e($status) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Ringkasan</div>
      <div class="card-body">
        <div class="row text-center g-3">
          <div class="col-4">
            <div class="fs-4 fw-bold"><?= e((string) $websiteCount) ?></div>
            <div class="text-muted small">Website PHP</div>
          </div>
          <div class="col-4">
            <div class="fs-4 fw-bold"><?= e((string) count($nodejsStatus['managed'])) ?></div>
            <div class="text-muted small">Aplikasi Node.js</div>
          </div>
          <div class="col-4">
            <div class="fs-4 fw-bold"><?= e((string) $dbCount) ?></div>
            <div class="text-muted small">Database</div>
          </div>
        </div>
        <hr>
        <div class="d-flex justify-content-between align-items-center">
          <span>Cloudflare Tunnel</span>
          <span>
            <?php if (!$cloudflare['configured']): ?>
              <span class="badge text-bg-secondary">Not Configured</span>
            <?php else: ?>
              <span class="status-dot <?= e($cloudflare['status']) ?>"></span><?= e($cloudflare['status']) ?>
            <?php endif; ?>
          </span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
    <span>Aplikasi Node.js (via PM2)</span>
    <a href="/nodejs.php" class="btn btn-sm btn-outline-primary">Kelola semua</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr><th>App Name</th><th>Status</th><th>CPU</th><th>RAM</th><th>Restarts</th></tr>
        </thead>
        <tbody>
          <?php if (empty($nodejsStatus['managed'])): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada aplikasi Node.js terdaftar</td></tr>
          <?php endif; ?>
          <?php foreach ($nodejsStatus['managed'] as $item): $rt = $item['runtime']; ?>
            <tr>
              <td><?= e($item['meta']['app_name']) ?></td>
              <td><span class="status-dot <?= e($item['status']) ?>"></span><?= e($item['status']) ?></td>
              <td><?= $rt ? e((string) $rt['cpu_percent']) . '%' : '-' ?></td>
              <td><?= $rt ? e((string) round($rt['memory_bytes'] / 1048576, 1)) . ' MB' : '-' ?></td>
              <td><?= $rt ? e((string) $rt['restart_count']) : '-' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.getElementById('statsBlock').addEventListener('panel:refresh', function (e) {
  var d = e.detail;
  if (!d || !d.ok) return;
  document.getElementById('cpuValue').textContent = d.cpu_percent + '%';
  document.getElementById('cpuBar').style.width = d.cpu_percent + '%';
  document.getElementById('ramValue').textContent = d.ram.percent + '%';
  document.getElementById('ramDetail').textContent = d.ram.used_mb + ' / ' + d.ram.total_mb + ' MB';
  document.getElementById('diskValue').textContent = d.disk.percent + '%';
  document.getElementById('diskDetail').textContent = d.disk.used_gb + ' / ' + d.disk.total_gb + ' GB';
  document.getElementById('loadValue').textContent = d.load.map(function(v){return Math.round(v*100)/100;}).join(' / ');
});
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
