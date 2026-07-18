<?php
declare(strict_types=1);

/**
 * Application bootstrap - loaded by every entry point in public/.
 * Wires config, secure sessions, autoloading and error handling.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak stack traces to the browser

define('BASE_PATH', dirname(__DIR__) === __DIR__ ? __DIR__ : dirname(__FILE__));
define('APP_PATH', __DIR__);

require APP_PATH . '/app/config/config.php';
Config::load(APP_PATH . '/.env');

define('LOG_PATH', Config::get('LOG_PATH', APP_PATH . '/storage/logs'));

set_exception_handler(function (Throwable $e): void {
    error_log('[UNCAUGHT] ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    @file_put_contents(
        LOG_PATH . '/app-error.log',
        '[' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage() . "\n",
        FILE_APPEND
    );
    http_response_code(500);
    echo 'Terjadi kesalahan pada server. Silakan coba lagi nanti.';
    exit;
});

// ---------------------------------------------------------------------------
// Simple PSR-4-ish autoloader for app/{services,helpers,controllers}
// ---------------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $dirs = ['services', 'controllers'];
    foreach ($dirs as $dir) {
        $path = APP_PATH . "/app/{$dir}/{$class}.php";
        if (is_file($path)) {
            require $path;
            return;
        }
    }
});

require APP_PATH . '/app/config/database.php';
foreach (glob(APP_PATH . '/app/helpers/*.php') as $helperFile) {
    require $helperFile;
}
foreach (glob(APP_PATH . '/app/scripts/*.php') as $scriptFile) {
    require $scriptFile;
}

date_default_timezone_set('UTC');

// ---------------------------------------------------------------------------
// Secure session configuration
// ---------------------------------------------------------------------------
$sessionPath = APP_PATH . '/storage/sessions';
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => Config::getBool('SESSION_SECURE_COOKIE', true),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('panel_sid');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

Auth::enforceSessionPolicy();
