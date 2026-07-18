<?php
declare(strict_types=1);

/**
 * Cron jobs are written as individual /etc/cron.d/panel-<id> files (never
 * by editing a shared user crontab in place), through the same audited
 * Executor bridge. Commands are built from a fixed template per
 * command_type - never from a free-form string supplied by the user.
 */
final class CronService
{
    private const ALLOWED_TYPES = ['php_artisan', 'php_script', 'node_script'];

    /** @return array<int,array<string,mixed>> */
    public static function listJobs(): array
    {
        return Database::app()->query(
            'SELECT c.*, w.domain AS website_domain, n.app_name AS node_app_name
             FROM cron_jobs c
             LEFT JOIN websites w ON w.id = c.website_id
             LEFT JOIN nodejs_apps n ON n.id = c.nodejs_app_id
             ORDER BY c.created_at DESC'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM cron_jobs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function createJob(
        string $name,
        string $ownerType,
        ?int $websiteId,
        ?int $nodejsAppId,
        string $schedule,
        string $commandType,
        string $commandArg,
        ?int $userId
    ): array {
        if (!preg_match('/^[a-zA-Z0-9 _-]{1,100}$/', $name)) {
            throw new InvalidArgumentException('Nama cron job tidak valid');
        }
        if (!Validator::cronSchedule($schedule)) {
            throw new InvalidArgumentException('Format jadwal cron tidak valid (5 kolom: menit jam tanggal bulan hari)');
        }
        if (!in_array($commandType, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('Tipe command tidak dikenal');
        }
        if ($commandType !== 'php_artisan' && !Validator::relativeScriptPath($commandArg)) {
            throw new InvalidArgumentException('Path script tidak valid');
        }

        $pdo = Database::app();
        $stmt = $pdo->prepare(
            'INSERT INTO cron_jobs (name, owner_type, website_id, nodejs_app_id, schedule, command_type, command_arg, is_enabled, created_by)
             VALUES (:name, :ot, :wid, :nid, :sched, :ctype, :carg, 1, :uid)'
        );
        $stmt->execute([
            'name' => $name, 'ot' => $ownerType, 'wid' => $websiteId, 'nid' => $nodejsAppId,
            'sched' => $schedule, 'ctype' => $commandType, 'carg' => $commandArg, 'uid' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        self::writeCronFile($id);
        ActivityLog::record($userId, 'cron.create', "Cron job dibuat: {$name}");

        return self::find($id);
    }

    public static function toggleJob(int $id, bool $enabled, ?int $userId): void
    {
        $job = self::find($id);
        if ($job === null) {
            throw new InvalidArgumentException('Cron job tidak ditemukan');
        }

        Database::app()->prepare('UPDATE cron_jobs SET is_enabled = :e WHERE id = :id')
            ->execute(['e' => $enabled ? 1 : 0, 'id' => $id]);

        if ($enabled) {
            self::writeCronFile($id);
        } else {
            Executor::run('cron-delete', ["panel-{$id}"], null, 10);
        }

        ActivityLog::record($userId, 'cron.toggle', "Cron job {$job['name']} " . ($enabled ? 'diaktifkan' : 'dinonaktifkan'));
    }

    public static function deleteJob(int $id, ?int $userId): void
    {
        $job = self::find($id);
        if ($job === null) {
            return;
        }
        Executor::run('cron-delete', ["panel-{$id}"], null, 10);
        Database::app()->prepare('DELETE FROM cron_jobs WHERE id = :id')->execute(['id' => $id]);
        ActivityLog::record($userId, 'cron.delete', "Cron job dihapus: {$job['name']}");
    }

    private static function writeCronFile(int $id): void
    {
        $job = self::find($id);
        if ($job === null) {
            return;
        }

        $logFile = LOG_PATH . "/cron-panel-{$id}.log";
        $line = self::buildCommandLine($job, $logFile);
        if ($line === null) {
            return;
        }

        $content = "# Managed by Server Panel - cron job #{$id} ({$job['name']}). Do not edit manually.\n"
            . "{$job['schedule']} {$line}\n";

        $result = Executor::run('cron-write', ["panel-{$id}"], $content, 10);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menulis cron job: ' . $result['output']);
        }
    }

    private static function buildCommandLine(array $job, string $logFile): ?string
    {
        $safeLog = escapeshellarg($logFile);

        if ($job['owner_type'] === 'php') {
            $website = Database::app()->prepare('SELECT * FROM websites WHERE id = :id');
            $website->execute(['id' => $job['website_id']]);
            $site = $website->fetch();
            if (!$site) {
                return null;
            }
            $siteRoot = escapeshellarg(dirname($site['document_root']));
            $version = $site['php_version'];
            $phpBin = "/usr/bin/php{$version}";

            if ($job['command_type'] === 'php_artisan') {
                return "www-data {$phpBin} {$siteRoot}/artisan schedule:run >> {$safeLog} 2>&1";
            }
            // command_arg was validated by Validator::relativeScriptPath() at
            // creation time (charset [a-zA-Z0-9_./-] only, no shell metachars).
            $scriptPath = $job['command_arg'];
            return "www-data {$phpBin} {$siteRoot}/{$scriptPath} >> {$safeLog} 2>&1";
        }

        if ($job['owner_type'] === 'nodejs') {
            $app = Database::app()->prepare('SELECT * FROM nodejs_apps WHERE id = :id');
            $app->execute(['id' => $job['nodejs_app_id']]);
            $nodeApp = $app->fetch();
            if (!$nodeApp) {
                return null;
            }
            $cwd = escapeshellarg($nodeApp['project_path']);
            $scriptPath = $job['command_arg'];
            $nvmDir = '/home/nodeapps/.nvm';
            return "nodeapps bash -lc 'export NVM_DIR={$nvmDir}; [ -s \"\$NVM_DIR/nvm.sh\" ] && . \"\$NVM_DIR/nvm.sh\"; cd {$cwd} && node {$scriptPath}' >> {$safeLog} 2>&1";
        }

        return null;
    }
}
