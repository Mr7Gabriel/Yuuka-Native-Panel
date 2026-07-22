<?php
declare(strict_types=1);

/**
 * Reads Cloudflare Tunnel status live from systemd/cloudflared. Never
 * reads or displays the tunnel token - the token lives only at
 * /etc/cloudflared/tunnel.env (600, root-owned, loaded by systemd via
 * EnvironmentFile=) and is never touched by the panel application layer.
 */
final class CloudflareService
{
    /**
     * The panel PHP-FPM pool's open_basedir is locked to its own directory
     * (see modules/panel.sh), so presence is inferred from the Executor
     * (which runs as root, unrestricted) rather than checking the
     * filesystem path directly from PHP.
     */
    public static function isInstalled(): bool
    {
        return self::binaryVersion() !== null;
    }

    public static function status(): array
    {
        $version = self::binaryVersion();
        if ($version === null) {
            return [
                'configured' => false,
                'status' => 'not_configured',
                'version' => null,
            ];
        }

        $result = Executor::run('cloudflared-status', [], null, 10);
        $status = trim($result['output']);
        if ($status === '') {
            $status = 'unknown';
        }

        return [
            'configured' => true,
            'status' => $status, // active | inactive | failed | unknown
            'version' => $version,
        ];
    }

    private static function binaryVersion(): ?string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $result = Executor::run('cloudflared-version', [], null, 10);
        $cached = $result['ok'] && trim($result['output']) !== '' ? trim($result['output']) : null;
        return $cached;
    }

    public static function restart(?int $userId): void
    {
        $result = Executor::run('cloudflared-restart', [], null, 20);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal restart cloudflared: ' . $result['output']);
        }
        ActivityLog::record($userId, 'cloudflare.restart', 'cloudflared di-restart');
    }

    public static function stop(?int $userId): void
    {
        $result = Executor::run('cloudflared-stop', [], null, 20);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal stop cloudflared: ' . $result['output']);
        }
        ActivityLog::record($userId, 'cloudflare.stop', 'cloudflared dihentikan');
    }

    public static function start(?int $userId): void
    {
        $result = Executor::run('cloudflared-start', [], null, 20);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal start cloudflared: ' . $result['output']);
        }
        ActivityLog::record($userId, 'cloudflare.start', 'cloudflared dijalankan');
    }
}
