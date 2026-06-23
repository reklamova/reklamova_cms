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

        $statement = $this->pdo->prepare('SELECT id, email, password_hash, name FROM cms_users WHERE email = ? AND active = 1 LIMIT 1');
        $statement->execute([$email]);
        $user = $statement->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $_SESSION['admin_user'] = [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
        ];

        return true;
    }

    public function user(): ?array
    {
        Csrf::startSession();
        return $_SESSION['admin_user'] ?? null;
    }

    public function logout(): void
    {
        Csrf::startSession();
        unset($_SESSION['admin_user']);
    }
}

