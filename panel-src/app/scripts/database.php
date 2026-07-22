<?php
declare(strict_types=1);

/**
 * Low-level MariaDB provisioning primitives. Uses the panel_provisioner
 * PDO connection (elevated CREATE/DROP/CREATE USER/GRANT privileges only -
 * see modules/mariadb.sh). Identifiers (db/user names) cannot be bound as
 * PDO parameters, so they are strictly whitelisted with Validator BEFORE
 * being interpolated into DDL; values (passwords) are always bound.
 */

function db_create(string $dbName): void
{
    if (!Validator::dbName($dbName)) {
        throw new InvalidArgumentException('Nama database tidak valid');
    }
    $pdo = Database::provisioner();
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function db_drop(string $dbName): void
{
    if (!Validator::dbName($dbName)) {
        throw new InvalidArgumentException('Nama database tidak valid');
    }
    Database::provisioner()->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function db_user_create(string $username, string $password): void
{
    if (!Validator::dbUser($username)) {
        throw new InvalidArgumentException('Nama user database tidak valid');
    }
    $pdo = Database::provisioner();
    // CREATE USER/ALTER USER's IDENTIFIED BY clause cannot be bound as a
    // PDO parameter - MariaDB's server-side prepared statement protocol
    // does not support account-management statements, so a "?" placeholder
    // here reaches the server unsubstituted and fails with a syntax error
    // ("... near '?'"). PDO::quote() safely escapes and quotes the value
    // for direct interpolation instead, same as identifiers elsewhere in
    // this file that also cannot be bound.
    $quotedPassword = $pdo->quote($password);
    $pdo->exec("CREATE USER IF NOT EXISTS `{$username}`@'localhost' IDENTIFIED BY {$quotedPassword}");
    $pdo->exec("ALTER USER `{$username}`@'localhost' IDENTIFIED BY {$quotedPassword}");
}

function db_user_drop(string $username): void
{
    if (!Validator::dbUser($username)) {
        throw new InvalidArgumentException('Nama user database tidak valid');
    }
    Database::provisioner()->exec("DROP USER IF EXISTS `{$username}`@'localhost'");
}

function db_grant_all(string $dbName, string $username): void
{
    if (!Validator::dbName($dbName) || !Validator::dbUser($username)) {
        throw new InvalidArgumentException('Nama database/user tidak valid');
    }
    $pdo = Database::provisioner();
    $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO `{$username}`@'localhost'");
    $pdo->exec('FLUSH PRIVILEGES');
}

/** @return array<int, array{name:string, size_mb:float}> */
function db_list(): array
{
    $pdo = Database::provisioner();
    $stmt = $pdo->query(
        "SELECT table_schema AS name, ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
         FROM information_schema.tables
         WHERE table_schema NOT IN ('mysql','information_schema','performance_schema','sys')
         GROUP BY table_schema
         ORDER BY table_schema"
    );
    return $stmt->fetchAll();
}

function db_dump(string $dbName, string $outFile): array
{
    if (!Validator::dbName($dbName)) {
        throw new InvalidArgumentException('Nama database tidak valid');
    }
    return Executor::run('mysqldump-db', [$dbName, $outFile], null, 120);
}

function db_restore(string $dbName, string $inFile): array
{
    if (!Validator::dbName($dbName)) {
        throw new InvalidArgumentException('Nama database tidak valid');
    }
    return Executor::run('mysql-restore-db', [$dbName, $inFile], null, 120);
}
