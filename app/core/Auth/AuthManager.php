<?php

declare(strict_types=1);

namespace Reklamova\Cms\Auth;

use PDO;

final class AuthManager
{
    public function __construct(private PDO $pdo)
    {
    }

    public function attempt(string $email, string $password): bool
    {
        Csrf::startSession();

        $statement = $this->pdo->prepare('SELECT id, email, password_hash, name, role FROM cms_users WHERE LOWER(email) = LOWER(?) AND active = 1 LIMIT 1');
        $statement->execute([trim($email)]);
        $user = $statement->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        unset($_SESSION['_csrf']);
        $_SESSION['admin_user'] = [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'name' => (string) $user['name'],
            'role' => $this->normalizedRole($user['role'] ?? null),
        ];

        return true;
    }

    public function user(): ?array
    {
        Csrf::startSession();
        $user = $_SESSION['admin_user'] ?? null;
        if (!is_array($user)) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT id, email, name, role FROM cms_users WHERE id = ? AND active = 1 LIMIT 1');
        $statement->execute([(int) ($user['id'] ?? 0)]);
        $currentUser = $statement->fetch();

        if (!is_array($currentUser)) {
            $this->clearAuthenticatedSession();
            return null;
        }

        $user = [
            'id' => (int) $currentUser['id'],
            'email' => (string) $currentUser['email'],
            'name' => (string) $currentUser['name'],
            'role' => $this->normalizedRole($currentUser['role'] ?? null),
        ];
        $_SESSION['admin_user'] = $user;

        return $user;
    }

    public function logout(): void
    {
        Csrf::startSession();
        $this->clearAuthenticatedSession();
    }

    public function activeUserByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, email, name FROM cms_users WHERE LOWER(email) = LOWER(?) AND active = 1 LIMIT 1');
        $statement->execute([trim($email)]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function setTemporaryPassword(int $userId, string $password): void
    {
        $statement = $this->pdo->prepare('UPDATE cms_users SET password_hash = ? WHERE id = ? AND active = 1');
        $statement->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $statement = $this->pdo->prepare('SELECT password_hash FROM cms_users WHERE id = ? AND active = 1 LIMIT 1');
        $statement->execute([$userId]);
        $user = $statement->fetch();

        if (!$user || !password_verify($currentPassword, (string) $user['password_hash'])) {
            return false;
        }

        $this->setTemporaryPassword($userId, $newPassword);
        return true;
    }

    private function clearAuthenticatedSession(): void
    {
        unset($_SESSION['admin_user'], $_SESSION['_csrf']);
        session_regenerate_id(true);
    }

    private function normalizedRole(mixed $role): string
    {
        return is_string($role) && $role !== '' ? $role : 'admin';
    }
}
