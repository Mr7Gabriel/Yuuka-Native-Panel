<?php
declare(strict_types=1);

/**
 * Invoked every minute by system cron (as the 'panel' user - see
 * modules/panel.sh) to run any due HTTP health checks. Health checks are
 * purely informational: PM2 (pm2 jlist) remains the source of truth for
 * whether a Node.js process is actually running.
 */

require __DIR__ . '/../app/config/config.php';
Config::load(__DIR__ . '/../.env');

define('LOG_PATH', Config::get('LOG_PATH', __DIR__ . '/../storage/logs'));

require __DIR__ . '/../app/config/database.php';
foreach (glob(__DIR__ . '/../app/helpers/*.php') as $helperFile) {
    require $helperFile;
}
foreach (glob(__DIR__ . '/../app/scripts/*.php') as $scriptFile) {
    require $scriptFile;
}
spl_autoload_register(function (string $class): void {
    foreach (['services', 'controllers'] as $dir) {
        $path = __DIR__ . "/../app/{$dir}/{$class}.php";
        if (is_file($path)) {
            require $path;
            return;
        }
    }
});

try {
    $count = HealthCheckService::runDueChecks();
    if ($count > 0) {
        @file_put_contents(LOG_PATH . '/health-check.log', '[' . date('c') . "] Ran {$count} health check(s)\n", FILE_APPEND);
    }
} catch (Throwable $e) {
    @file_put_contents(LOG_PATH . '/health-check.log', '[' . date('c') . '] ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
}
