<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('apps.view');

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'install') {
            Rbac::require('apps.install');

            $appSlug = (string) ($_POST['app_slug'] ?? '');
            $domain = trim((string) ($_POST['domain'] ?? ''));
            $phpVersion = (string) ($_POST['php_version'] ?? '');
            $createDatabase = isset($_POST['create_database']);
            $dbName = trim((string) ($_POST['db_name'] ?? '')) ?: null;
            $dbUser = trim((string) ($_POST['db_user'] ?? '')) ?: null;
            $dbPassword = (string) ($_POST['db_password'] ?? '') ?: null;

            $customZipBytes = null;
            if ($appSlug === 'custom') {
                if (!isset($_FILES['app_zip']) || $_FILES['app_zip']['error'] !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Upload ZIP gagal atau tidak ada file dipilih');
                }
                $customZipBytes = (string) file_get_contents($_FILES['app_zip']['tmp_name']);
            }

            $result = AppInstallerService::installApp(
                $appSlug,
                $domain,
                $phpVersion,
                $createDatabase,
                $dbName,
                $dbUser,
                $dbPassword,
                $customZipBytes,
                $user['id']
            );

            $msg = "Aplikasi {$result['app_name']} berhasil diinstal ke {$result['domain']}.";
            if ($result['app_slug'] === 'wordpress') {
                $msg .= " Buka http://{$result['domain']}/wp-admin/install.php untuk membuat user admin WordPress pertama.";
            } elseif (!empty($result['db_name'])) {
                $msg .= " Database: {$result['db_name']} / user: {$result['db_user']}"
                    . (!empty($result['db_password']) ? " / password: {$result['db_password']} (catat sekarang, tidak ditampilkan lagi)" : '')
                    . " - lanjutkan instalasi lewat web installer bawaan aplikasi.";
            }
            flash('success', $msg);
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/app_installer.php');
}

$catalog = AppCatalog::all();
$phpVersions = PhpService::installedVersions();
$installedApps = AppInstallerService::listInstalled();

$tierBadge = [
    AppCatalog::TIER_FULL => 'text-bg-success',
    AppCatalog::TIER_PARTIAL => 'text-bg-info',
    AppCatalog::TIER_GENERIC => 'text-bg-secondary',
];
$tierLabel = [
    AppCatalog::TIER_FULL => 'Otomatis Penuh',
    AppCatalog::TIER_PARTIAL => 'Otomatis Sebagian',
    AppCatalog::TIER_GENERIC => 'Custom',
];

$pageTitle = 'App Installer';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-0">App Installer</h4>
    <p class="text-muted mb-0">Instal aplikasi PHP siap pakai ke website baru dengan satu form</p>
  </div>
</div>

<div class="row g-4 mb-4">
  <?php foreach ($catalog as $slug => $app): ?>
  <div class="col-md-4">
    <div class="card stat-card h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex align-items-center gap-2 mb-2">
          <i class="bi <?= e($app['icon']) ?> fs-3 text-primary"></i>
          <div>
            <div class="fw-semibold"><?= e($app['name']) ?></div>
            <span class="badge <?= e($tierBadge[$app['tier']]) ?>"><?= e($tierLabel[$app['tier']]) ?></span>
            <?php if (!empty($app['version'])): ?>
              <span class="text-muted small">v<?= e($app['version']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <p class="text-muted small flex-grow-1"><?= e($app['description']) ?></p>
        <?php if (Rbac::can($user['role'], 'apps.install')): ?>
        <button type="button" class="btn btn-primary btn-sm mt-2"
                data-bs-toggle="modal" data-bs-target="#installAppModal"
                data-slug="<?= e($slug) ?>"
                data-name="<?= e($app['name']) ?>"
                data-tier="<?= e($app['tier']) ?>"
                data-requires-db="<?= $app['requires_database'] ? '1' : '0' ?>">
          <i class="bi bi-download me-1"></i>Instal
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold">Aplikasi Terinstall</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr><th>Domain</th><th>Aplikasi</th><th>Versi</th><th>Database</th><th>Diinstal</th></tr>
        </thead>
        <tbody>
        <?php if (empty($installedApps)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">Belum ada aplikasi terinstall</td></tr>
        <?php endif; ?>
        <?php foreach ($installedApps as $row): ?>
          <tr>
            <td><a href="http://<?= e($row['domain']) ?>" target="_blank" rel="noopener"><?= e($row['domain']) ?></a></td>
            <td><?= e(AppCatalog::get($row['app_slug'])['name'] ?? $row['app_slug']) ?></td>
            <td><?= e($row['app_version'] ?? '-') ?></td>
            <td><?= e($row['db_name'] ?? '-') ?></td>
            <td class="text-muted small"><?= e($row['installed_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-muted small p-3 mb-0">Hapus aplikasi lewat menu <a href="/websites.php">Hapus Website</a> pada domain terkait - database & catatan aplikasi ikut dibersihkan otomatis.</p>
  </div>
</div>

<?php if (Rbac::can($user['role'], 'apps.install')): ?>
<div class="modal fade" id="installAppModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" enctype="multipart/form-data">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Instal <span id="installAppName"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="install">
          <input type="hidden" name="app_slug" id="installAppSlug">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Domain</label>
              <input type="text" name="domain" class="form-control" placeholder="contoh.com" required pattern="^[a-zA-Z0-9.-]+$">
              <div class="form-text">Website baru akan dibuat otomatis di domain ini.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Versi PHP</label>
              <select name="php_version" class="form-select" required>
                <?php foreach ($phpVersions as $v): ?>
                  <option value="<?= e($v) ?>">PHP <?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12" id="installZipField" style="display:none">
              <label class="form-label">File ZIP Aplikasi</label>
              <input type="file" name="app_zip" accept=".zip" class="form-control">
            </div>

            <div class="col-12" id="installDbFields" style="display:none">
              <hr>
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="create_database" id="installCreateDb" value="1">
                <label class="form-check-label" for="installCreateDb">Buat database untuk aplikasi ini</label>
              </div>
              <div class="row g-3" id="installDbDetailFields" style="display:none">
                <div class="col-md-4">
                  <label class="form-label">Nama Database</label>
                  <input type="text" name="db_name" class="form-control" pattern="^[a-zA-Z0-9_]{1,64}$">
                </div>
                <div class="col-md-4">
                  <label class="form-label">User Database</label>
                  <input type="text" name="db_user" class="form-control" pattern="^[a-zA-Z0-9_]{1,32}$">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Password Database</label>
                  <input type="password" name="db_password" class="form-control" minlength="8">
                </div>
              </div>
            </div>

            <div class="col-12" id="installFullTierNote" style="display:none">
              <div class="alert alert-success small mb-0">
                Database dibuat &amp; dikonfigurasi otomatis - tidak perlu diisi manual.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Instal</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('installAppModal').addEventListener('show.bs.modal', function (ev) {
  var btn = ev.relatedTarget;
  var slug = btn.getAttribute('data-slug');
  var tier = btn.getAttribute('data-tier');
  var requiresDb = btn.getAttribute('data-requires-db') === '1';

  document.getElementById('installAppSlug').value = slug;
  document.getElementById('installAppName').textContent = btn.getAttribute('data-name');

  document.getElementById('installZipField').style.display = (slug === 'custom') ? '' : 'none';
  document.getElementById('installFullTierNote').style.display = (tier === 'full') ? '' : 'none';

  var dbFields = document.getElementById('installDbFields');
  var createDbCheckbox = document.getElementById('installCreateDb');
  if (tier === 'full') {
    dbFields.style.display = 'none';
    createDbCheckbox.checked = false;
  } else if (requiresDb) {
    dbFields.style.display = '';
    createDbCheckbox.checked = true;
    createDbCheckbox.disabled = true;
  } else {
    dbFields.style.display = '';
    createDbCheckbox.checked = false;
    createDbCheckbox.disabled = false;
  }
  document.getElementById('installDbDetailFields').style.display = createDbCheckbox.checked ? '' : 'none';
});
document.getElementById('installCreateDb').addEventListener('change', function () {
  document.getElementById('installDbDetailFields').style.display = this.checked ? '' : 'none';
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
