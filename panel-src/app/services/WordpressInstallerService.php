<?php
declare(strict_types=1);

/**
 * WordPress-specific pieces of App Installer: resolving the current stable
 * release via the official version-check API, fetching fresh secret-key
 * salts, and generating a ready-to-use wp-config.php.
 */
final class WordpressInstallerService
{
    /** @return array{bytes:string,version:string} */
    public static function downloadLatest(): array
    {
        $app = AppCatalog::get('wordpress');
        $body = AppInstallerService::fetchTextUrl($app['version_check_url'], 65536);

        // api.wordpress.org/core/version-check/1.7/ responds with JSON
        // (verified directly against the live endpoint), not PHP-serialized
        // data - do not switch this to unserialize().
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['offers'][0]['download']) || empty($data['offers'][0]['version'])) {
            throw new RuntimeException('Gagal membaca informasi versi WordPress terbaru');
        }

        $downloadUrl = (string) $data['offers'][0]['download'];
        if (!str_starts_with($downloadUrl, 'https://')) {
            // Belt-and-suspenders even though this came from the official
            // API rather than user input.
            throw new RuntimeException('URL unduhan WordPress tidak valid');
        }

        $bytes = AppInstallerService::downloadFixedUrl($downloadUrl);
        return ['bytes' => $bytes, 'version' => (string) $data['offers'][0]['version']];
    }

    public static function fetchSalts(): string
    {
        $app = AppCatalog::get('wordpress');
        $salts = AppInstallerService::fetchTextUrl($app['salt_api_url'], 16384);
        if (!str_contains($salts, 'AUTH_KEY')) {
            throw new RuntimeException('Gagal mengambil salt keys WordPress');
        }
        return $salts;
    }

    public static function buildConfig(string $dbName, string $dbUser, string $dbPassword, string $saltsBlock): string
    {
        // Randomized per install, purely to avoid every WordPress site on
        // the server sharing identical table names by coincidence - not a
        // security boundary.
        $prefix = 'wp_' . substr(bin2hex(random_bytes(3)), 0, 6) . '_';

        // var_export() on every dynamic value is what makes this safe
        // against breaking out of the PHP string literal regardless of
        // what characters end up in the (server-generated) password.
        return "<?php\n"
            . 'define(\'DB_NAME\', ' . var_export($dbName, true) . ");\n"
            . 'define(\'DB_USER\', ' . var_export($dbUser, true) . ");\n"
            . 'define(\'DB_PASSWORD\', ' . var_export($dbPassword, true) . ");\n"
            . 'define(\'DB_HOST\', ' . var_export('127.0.0.1', true) . ");\n"
            . "define('DB_CHARSET', 'utf8mb4');\n"
            . "define('DB_COLLATE', '');\n\n"
            . $saltsBlock . "\n\n"
            . '$table_prefix = ' . var_export($prefix, true) . ";\n\n"
            . "define('WP_DEBUG', false);\n\n"
            . "if (!defined('ABSPATH')) {\n"
            . "    define('ABSPATH', __DIR__ . '/');\n"
            . "}\n"
            . "require_once ABSPATH . 'wp-settings.php';\n";
    }
}
