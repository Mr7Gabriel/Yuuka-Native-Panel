<?php
declare(strict_types=1);

/**
 * PDO connection factory. Two distinct connections are exposed on purpose:
 *
 *  - app()         connects as DB_USERNAME, scoped to the panel's own
 *                   metadata database only (websites, apps, users, ...).
 *  - provisioner()  connects as DB_PROVISIONER_USERNAME, which has the
 *                   elevated CREATE/DROP/CREATE USER/GRANT privileges
 *                   needed to provision tenant databases. Only
 *                   DatabaseService is allowed to use this connection.
 */
final class Database
{
    private static ?PDO $app = null;
    private static ?PDO $provisioner = null;

    public static function app(): PDO
    {
        if (self::$app === null) {
            self::$app = self::connect(
                Config::get('DB_DATABASE'),
                Config::get('DB_USERNAME'),
                Config::get('DB_PASSWORD', '')
            );
        }
        return self::$app;
    }

    public static function provisioner(): PDO
    {
        if (self::$provisioner === null) {
            self::$provisioner = self::connect(
                null,
                Config::get('DB_PROVISIONER_USERNAME'),
                Config::get('DB_PROVISIONER_PASSWORD', '')
            );
        }
        return self::$provisioner;
    }

    private static function connect(?string $dbName, string $user, string $pass): PDO
    {
        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::get('DB_PORT', '3306');
        $dsn = "mysql:host={$host};port={$port}" . ($dbName ? ";dbname={$dbName}" : '') . ';charset=utf8mb4';

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
