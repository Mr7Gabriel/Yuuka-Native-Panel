<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('database.view');

$stmt = Database::app()->prepare('SELECT setting_value FROM settings WHERE setting_key = "phpmyadmin_url"');
$stmt->execute();
$base = $stmt->fetchColumn();

if (!$base) {
    flash('error', 'URL phpMyAdmin belum dikonfigurasi. Atur di menu Pengaturan.');
    redirect('/settings.php');
}

$db = (string) ($_GET['db'] ?? '');
$target = rtrim((string) $base, '/') . '/index.php';
if ($db !== '' && Validator::dbName($db)) {
    $target .= '?route=%2Fdatabase%2Fstructure&db=' . urlencode($db);
}

redirect($target);
