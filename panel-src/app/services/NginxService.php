<?php
declare(strict_types=1);

final class NginxService
{
    /** @return array<int,array<string,mixed>> */
    public static function listWebsites(): array
    {
        return Database::app()->query('SELECT * FROM websites ORDER BY domain')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM websites WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @throws InvalidArgumentException on validation failure
     * @throws RuntimeException on system-level failure (nginx test, fs)
     */
    public static function createWebsite(string $domain, string $phpVersion, ?int $userId): array
    {
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        if (!PhpService::isValidVersion($phpVersion)) {
            throw new InvalidArgumentException('Versi PHP tidak tersedia di server ini');
        }

        $pdo = Database::app();
        $exists = $pdo->prepare('SELECT COUNT(*) FROM websites WHERE domain = :d');
        $exists->execute(['d' => $domain]);
        if ((int) $exists->fetchColumn() > 0) {
            throw new InvalidArgumentException('Domain sudah terdaftar');
        }

        $mkdir = Executor::run('fs-mkdir-website', [$domain], null, 15);
        if (!$mkdir['ok']) {
            throw new RuntimeException('Gagal membuat direktori website: ' . $mkdir['output']);
        }
        $documentRoot = "/var/www/{$domain}/public";

        $siteName = "site-{$domain}";
        $config = nginx_build_php_site_config($domain, $phpVersion, $documentRoot);
        $write = nginx_write_config($siteName, $config);
        if (!$write['ok']) {
            throw new RuntimeException('Konfigurasi Nginx tidak valid: ' . $write['output']);
        }

        $enable = nginx_enable_site($siteName);
        if (!$enable['ok']) {
            throw new RuntimeException('Gagal mengaktifkan situs: ' . $enable['output']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO websites (domain, php_version, document_root, nginx_conf_name, is_enabled, created_by)
             VALUES (:domain, :php, :root, :conf, 1, :uid)'
        );
        $stmt->execute(['domain' => $domain, 'php' => $phpVersion, 'root' => $documentRoot, 'conf' => $siteName, 'uid' => $userId]);
        $id = (int) $pdo->lastInsertId();

        $domainStmt = $pdo->prepare('INSERT INTO domains (domain, type, website_id) VALUES (:d, "php", :wid)');
        $domainStmt->execute(['d' => $domain, 'wid' => $id]);

        ActivityLog::record($userId, 'website.create', "Website dibuat: {$domain} (PHP {$phpVersion})");

        return self::find($id);
    }

    public static function toggleWebsite(int $id, bool $enable, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }

        $result = $enable ? nginx_enable_site($site['nginx_conf_name']) : nginx_disable_site($site['nginx_conf_name']);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal mengubah status situs: ' . $result['output']);
        }

        $stmt = Database::app()->prepare('UPDATE websites SET is_enabled = :e WHERE id = :id');
        $stmt->execute(['e' => $enable ? 1 : 0, 'id' => $id]);

        ActivityLog::record($userId, 'website.toggle', "Website {$site['domain']} " . ($enable ? 'diaktifkan' : 'dinonaktifkan'));
    }

    public static function deleteWebsite(int $id, bool $deleteFiles, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }

        nginx_delete_site($site['nginx_conf_name']);

        if ($deleteFiles) {
            Executor::run('fs-remove-website', [$site['domain']], null, 30);
        }

        $pdo = Database::app();
        $pdo->prepare('DELETE FROM domains WHERE website_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM websites WHERE id = :id')->execute(['id' => $id]);

        ActivityLog::record($userId, 'website.delete', "Website dihapus: {$site['domain']} (files_removed=" . ($deleteFiles ? 'yes' : 'no') . ')');
    }
}
