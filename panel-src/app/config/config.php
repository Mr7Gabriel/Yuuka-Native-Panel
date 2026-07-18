<?php
declare(strict_types=1);

/**
 * Minimal .env loader + typed config accessor. No external dependencies -
 * the panel intentionally has zero Composer/npm build step.
 */
final class Config
{
    private static ?array $values = null;

    public static function load(string $envPath): void
    {
        if (self::$values !== null) {
            return;
        }
        self::$values = [];

        if (!is_file($envPath)) {
            throw new RuntimeException("File konfigurasi .env tidak ditemukan: {$envPath}");
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$values === null) {
            throw new RuntimeException('Config belum di-load');
        }
        return self::$values[$key] ?? $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }
}
