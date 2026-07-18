<?php
declare(strict_types=1);

/**
 * Application-level HTTP health checks, performed via PHP's cURL client
 * directly (no shell invocation - the URL never touches a command line).
 * This is purely informational: PM2 (see nodejs_pm2_jlist()) remains the
 * single source of truth for whether a process is actually running.
 */
final class HealthCheckService
{
    public static function forApp(int $nodejsAppId): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM health_checks WHERE nodejs_app_id = :id');
        $stmt->execute(['id' => $nodejsAppId]);
        return $stmt->fetch() ?: null;
    }

    public static function configure(int $nodejsAppId, string $url, string $method, int $timeout, int $interval): void
    {
        if (!Validator::healthCheckUrl($url)) {
            throw new InvalidArgumentException('URL health check tidak valid (harus http/https)');
        }
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            throw new InvalidArgumentException('Metode HTTP tidak valid');
        }
        $timeout = max(1, min(30, $timeout));
        $interval = max(10, min(3600, $interval));

        $stmt = Database::app()->prepare(
            'INSERT INTO health_checks (nodejs_app_id, url, http_method, timeout_seconds, interval_seconds, is_enabled)
             VALUES (:id, :url, :method, :timeout, :interval, 1)
             ON DUPLICATE KEY UPDATE url = :url2, http_method = :method2, timeout_seconds = :timeout2, interval_seconds = :interval2, is_enabled = 1'
        );
        $stmt->execute([
            'id' => $nodejsAppId, 'url' => $url, 'method' => $method, 'timeout' => $timeout, 'interval' => $interval,
            'url2' => $url, 'method2' => $method, 'timeout2' => $timeout, 'interval2' => $interval,
        ]);
    }

    public static function disable(int $nodejsAppId): void
    {
        Database::app()->prepare('UPDATE health_checks SET is_enabled = 0 WHERE nodejs_app_id = :id')
            ->execute(['id' => $nodejsAppId]);
    }

    /** Performs the check right now and persists the result. */
    public static function runCheck(array $healthCheck): array
    {
        $start = microtime(true);
        $status = 'unknown';
        $httpCode = null;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $healthCheck['url'],
            CURLOPT_CUSTOMREQUEST => $healthCheck['http_method'],
            CURLOPT_NOBODY => $healthCheck['http_method'] === 'HEAD',
            CURLOPT_TIMEOUT => (int) $healthCheck['timeout_seconds'],
            CURLOPT_CONNECTTIMEOUT => (int) $healthCheck['timeout_seconds'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseMs = (int) round((microtime(true) - $start) * 1000);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            $status = 'timeout';
        } elseif ($errno === CURLE_COULDNT_CONNECT) {
            $status = 'connection_refused';
        } elseif ($errno !== 0) {
            $status = 'unhealthy';
        } elseif ($httpCode >= 200 && $httpCode < 400) {
            $status = 'healthy';
        } else {
            $status = 'unhealthy';
        }

        $failureIncrement = $status === 'healthy' ? 0 : 1;

        $stmt = Database::app()->prepare(
            'UPDATE health_checks
             SET last_status = :status, last_status_code = :code, last_response_ms = :ms,
                 last_checked_at = NOW(),
                 failure_count = IF(:inc = 1, failure_count + 1, 0)
             WHERE nodejs_app_id = :id'
        );
        $stmt->execute([
            'status' => $status, 'code' => $httpCode ?: null, 'ms' => $responseMs,
            'inc' => $failureIncrement, 'id' => $healthCheck['nodejs_app_id'],
        ]);

        return ['status' => $status, 'http_code' => $httpCode, 'response_ms' => $responseMs];
    }

    /** Runs every enabled health check whose interval has elapsed. Intended to be invoked from a system cron. */
    public static function runDueChecks(): int
    {
        $stmt = Database::app()->query(
            "SELECT * FROM health_checks WHERE is_enabled = 1
             AND (last_checked_at IS NULL OR last_checked_at < DATE_SUB(NOW(), INTERVAL interval_seconds SECOND))"
        );
        $count = 0;
        foreach ($stmt->fetchAll() as $check) {
            self::runCheck($check);
            $count++;
        }
        return $count;
    }
}
