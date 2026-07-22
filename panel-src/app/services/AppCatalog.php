<?php
declare(strict_types=1);

/**
 * Fixed catalog of applications installable via App Installer. Data only -
 * no logic here. This is the whole SSRF answer for the feature: the
 * controller/service only ever accepts an `app_slug` looked up against
 * this array, it NEVER accepts a URL from the client - every URL that
 * ever reaches curl comes from a constant defined here.
 *
 * "partial" tier apps (joomla/drupal/phpbb) pin a specific download URL +
 * version rather than resolving "latest" dynamically, because none of
 * them expose an API as simple/stable as WordPress's version-check
 * endpoint. These need occasional manual bumping as new releases ship -
 * that is a one-line constant update, not an architecture change.
 */
final class AppCatalog
{
    public const TIER_FULL = 'full';
    public const TIER_PARTIAL = 'partial';
    public const TIER_GENERIC = 'generic';

    private const APPS = [
        'wordpress' => [
            'name' => 'WordPress',
            'icon' => 'bi-wordpress',
            'description' => 'CMS blog/website paling populer. Instalasi penuh otomatis - wp-config.php langsung siap pakai, tinggal buka wp-admin/install.php untuk buat user admin.',
            'tier' => self::TIER_FULL,
            'requires_database' => true,
            'version_check_url' => 'https://api.wordpress.org/core/version-check/1.7/',
            'salt_api_url' => 'https://api.wordpress.org/secret-key/1.1/salt/',
        ],
        'joomla' => [
            'name' => 'Joomla',
            'icon' => 'bi-globe',
            'description' => 'CMS Joomla. File diekstrak & database kosong dibuat - selesaikan instalasi lewat web installer bawaan Joomla.',
            'tier' => self::TIER_PARTIAL,
            'requires_database' => true,
            // Pinned 2026-07-23 - bump periodically as new Joomla releases ship.
            'version' => '6.1.2',
            'download_url' => 'https://github.com/joomla/joomla-cms/releases/download/6.1.2/Joomla_6.1.2-Stable-Full_Package.zip',
        ],
        'drupal' => [
            'name' => 'Drupal',
            'icon' => 'bi-droplet',
            'description' => 'CMS Drupal. File diekstrak & database kosong dibuat - selesaikan instalasi lewat web installer bawaan Drupal.',
            'tier' => self::TIER_PARTIAL,
            'requires_database' => true,
            // Pinned 2026-07-23 - bump periodically as new Drupal releases ship.
            'version' => '11.4.4',
            'download_url' => 'https://ftp.drupal.org/files/projects/drupal-11.4.4.zip',
        ],
        'phpbb' => [
            'name' => 'phpBB',
            'icon' => 'bi-chat-square-text',
            'description' => 'Forum phpBB. File diekstrak & database kosong dibuat - selesaikan instalasi lewat web installer bawaan phpBB.',
            'tier' => self::TIER_PARTIAL,
            'requires_database' => true,
            // Pinned 2026-07-23 - bump periodically as new phpBB releases ship.
            'version' => '3.3.17',
            'download_url' => 'https://download.phpbb.com/pub/release/3.3/3.3.17/phpBB-3.3.17.zip',
        ],
        'custom' => [
            'name' => 'Custom App (Upload ZIP Sendiri)',
            'icon' => 'bi-upload',
            'description' => 'Upload ZIP aplikasi PHP sendiri ke website baru. Tidak ada konfigurasi otomatis - diekstrak persis apa adanya.',
            'tier' => self::TIER_GENERIC,
            'requires_database' => false,
        ],
    ];

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return self::APPS;
    }

    /** @return array<string,mixed>|null */
    public static function get(string $slug): ?array
    {
        return self::APPS[$slug] ?? null;
    }

    public static function exists(string $slug): bool
    {
        return isset(self::APPS[$slug]);
    }
}
