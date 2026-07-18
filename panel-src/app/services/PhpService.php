<?php
declare(strict_types=1);

/**
 * Reads which PHP-FPM versions are installed on this server. The panel's
 * PHP-FPM pool runs with open_basedir locked to the panel directory only
 * (see modules/panel.sh), so it cannot probe /etc/php directly - instead
 * the installer records the installed versions into the `settings` table
 * once (module_panel_write_settings) and this class reads that.
 */
final class PhpService
{
    private const ALL_VERSIONS = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];

    public static function installedVersions(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $stmt = Database::app()->prepare('SELECT setting_value FROM settings WHERE setting_key = "php_installed_versions"');
        $stmt->execute();
        $csv = (string) $stmt->fetchColumn();

        $cache = array_values(array_filter(
            array_map('trim', explode(',', $csv)),
            static fn (string $v) => in_array($v, self::ALL_VERSIONS, true)
        ));

        return $cache;
    }

    public static function isValidVersion(string $version): bool
    {
        return in_array($version, self::installedVersions(), true);
    }
}
