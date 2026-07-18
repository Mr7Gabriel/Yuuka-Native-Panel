<?php
declare(strict_types=1);

final class UserService
{
    private const ROLES = ['admin', 'operator', 'developer', 'viewer'];

    public static function roles(): array
    {
        return self::ROLES;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listUsers(): array
    {
        return Database::app()->query(
            'SELECT id, username, email, role, is_active, last_login_at, last_login_ip, created_at FROM panel_users ORDER BY username'
        )->fetchAll();
    }

    public static function create(string $username, string $email, string $password, string $role, ?int $actingUserId): void
    {
        if (!Validator::username($username)) {
            throw new InvalidArgumentException('Username tidak valid (3-64 karakter, huruf/angka/._-)');
        }
        if (!Validator::email($email)) {
            throw new InvalidArgumentException('Email tidak valid');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Role tidak dikenal');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password minimal 8 karakter');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = Database::app()->prepare(
            'INSERT INTO panel_users (username, email, password_hash, role, is_active) VALUES (:u, :e, :h, :r, 1)'
        );
        try {
            $stmt->execute(['u' => $username, 'e' => $email, 'h' => $hash, 'r' => $role]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException('Username atau email sudah digunakan');
            }
            throw $e;
        }

        ActivityLog::record($actingUserId, 'user.create', "User panel dibuat: {$username} ({$role})");
    }

    public static function setActive(int $userId, bool $active, ?int $actingUserId): void
    {
        Database::app()->prepare('UPDATE panel_users SET is_active = :a WHERE id = :id')
            ->execute(['a' => $active ? 1 : 0, 'id' => $userId]);
        ActivityLog::record($actingUserId, 'user.toggle', "User #{$userId} " . ($active ? 'diaktifkan' : 'dinonaktifkan'));
    }

    public static function changeRole(int $userId, string $role, ?int $actingUserId): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Role tidak dikenal');
        }
        Database::app()->prepare('UPDATE panel_users SET role = :r WHERE id = :id')
            ->execute(['r' => $role, 'id' => $userId]);
        ActivityLog::record($actingUserId, 'user.role_change', "User #{$userId} role diubah ke {$role}");
    }

    public static function changePassword(int $userId, string $newPassword, ?int $actingUserId): void
    {
        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException('Password minimal 8 karakter');
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        Database::app()->prepare('UPDATE panel_users SET password_hash = :h WHERE id = :id')
            ->execute(['h' => $hash, 'id' => $userId]);
        ActivityLog::record($actingUserId, 'user.password_change', "Password user #{$userId} diubah");
    }

    public static function delete(int $userId, ?int $actingUserId): void
    {
        if ($userId === $actingUserId) {
            throw new InvalidArgumentException('Tidak dapat menghapus akun sendiri');
        }
        Database::app()->prepare('DELETE FROM panel_users WHERE id = :id')->execute(['id' => $userId]);
        ActivityLog::record($actingUserId, 'user.delete', "User #{$userId} dihapus");
    }
}
