<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Customers;

use PDO;

final class CustomerAuthService
{
    private const MAX_ATTEMPTS = 5;
    private const ATTEMPT_WINDOW_SECONDS = 900;
    private const TOKEN_TTL_SECONDS = 3600;
    private const DUMMY_PASSWORD_HASH = '$2y$10$9AzEcF2wzJ9TcxnrEwq63.0esSV9UPND17PhAwPr7bgRAHjJrUj8O';

    public function __construct(
        private PDO $pdo,
        private string $storeCode,
        private string $pepper,
    ) {
        $this->storeCode = trim($this->storeCode);
        if ($this->storeCode === '') {
            throw new \InvalidArgumentException('Store code is required.');
        }
        if (strlen($this->pepper) < 16) {
            throw new \InvalidArgumentException('Customer authentication pepper must contain at least 16 characters.');
        }
    }

    /** @return array<string, mixed> */
    public function authenticate(string $email, string $password, string $remoteIdentity): array
    {
        $email = $this->normalizeEmail($email);
        if (strlen($password) > 4096) {
            throw new \DomainException('Nieprawidłowy e-mail lub hasło.');
        }
        $storeId = $this->storeId();
        $identityHashes = $this->identityHashes($email, $remoteIdentity);
        foreach ($identityHashes as $identityHash) {
            if ($this->recentFailures($storeId, 'login', $identityHash) >= self::MAX_ATTEMPTS) {
                throw new \DomainException('Zbyt wiele prób logowania. Spróbuj ponownie za 15 minut.');
            }
        }

        $customer = $this->customerByEmail($storeId, $email);
        $hash = is_array($customer) ? (string) ($customer['password_hash'] ?? '') : '';
        $verified = password_verify($password, $hash !== '' ? $hash : self::DUMMY_PASSWORD_HASH);
        $allowed = $verified
            && is_array($customer)
            && (string) $customer['status'] === 'active'
            && !(bool) $customer['password_reset_required'];

        foreach ($identityHashes as $identityHash) {
            $this->recordAttempt($storeId, 'login', $identityHash, $allowed);
        }
        if (!$allowed || !is_array($customer)) {
            throw new \DomainException('Nieprawidłowy e-mail lub hasło.');
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->pdo->prepare('UPDATE commerce_customers SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $customer['id']]);
        }
        $this->pdo->prepare(
            'UPDATE commerce_customers SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$customer['id']]);

        return $this->publicCustomer($customer);
    }

    /**
     * Returns token data only when an active customer exists. Public callers must
     * always render the same response whether this method returns null or data.
     *
     * @return array<string, mixed>|null
     */
    public function issuePasswordToken(string $email, string $remoteIdentity): ?array
    {
        $email = $this->normalizeEmail($email);
        $storeId = $this->storeId();
        $identityHashes = $this->identityHashes($email, $remoteIdentity);
        foreach ($identityHashes as $identityHash) {
            if ($this->recentFailures($storeId, 'password_link', $identityHash) >= 3) {
                return null;
            }
        }
        foreach ($identityHashes as $identityHash) {
            $this->recordAttempt($storeId, 'password_link', $identityHash, false);
        }

        $customer = $this->customerByEmail($storeId, $email);
        if (!is_array($customer) || (string) $customer['status'] !== 'active') {
            password_verify('not-a-password', self::DUMMY_PASSWORD_HASH);
            return null;
        }

        $purpose = (bool) $customer['password_reset_required'] || empty($customer['password_hash'])
            ? 'activate'
            : 'reset';
        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE commerce_customer_tokens
                 SET used_at = CURRENT_TIMESTAMP
                 WHERE customer_id = ? AND purpose = ? AND used_at IS NULL'
            )->execute([$customer['id'], $purpose]);
            $this->pdo->prepare(
                'INSERT INTO commerce_customer_tokens
                    (customer_id, purpose, token_hash, expires_at)
                 VALUES (?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND))'
            )->execute([$customer['id'], $purpose, $tokenHash, self::TOKEN_TTL_SECONDS]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'customer_id' => (int) $customer['id'],
            'email' => (string) $customer['email'],
            'first_name' => (string) ($customer['first_name'] ?? ''),
            'purpose' => $purpose,
            'token' => $rawToken,
            'expires_in' => self::TOKEN_TTL_SECONDS,
        ];
    }

    /** @return array<string, mixed> */
    public function completePasswordToken(string $rawToken, string $password): array
    {
        $this->assertPassword($password);
        if (strlen($rawToken) < 32 || strlen($rawToken) > 500) {
            throw new \DomainException('Link aktywacyjny jest nieprawidłowy lub wygasł.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT t.id AS token_id, t.customer_id, c.email, c.first_name, c.last_name, c.status
                 FROM commerce_customer_tokens t
                 INNER JOIN commerce_customers c ON c.id = t.customer_id
                 INNER JOIN commerce_stores s ON s.id = c.store_id
                 WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > CURRENT_TIMESTAMP
                   AND s.code = ?
                 LIMIT 1 FOR UPDATE'
            );
            $statement->execute([hash('sha256', $rawToken), $this->storeCode]);
            $customer = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$customer || (string) $customer['status'] !== 'active') {
                throw new \DomainException('Link aktywacyjny jest nieprawidłowy lub wygasł.');
            }

            $this->pdo->prepare(
                'UPDATE commerce_customers
                 SET password_hash = ?, password_reset_required = 0,
                     email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            )->execute([password_hash($password, PASSWORD_DEFAULT), $customer['customer_id']]);
            $this->pdo->prepare(
                'UPDATE commerce_customer_tokens SET used_at = CURRENT_TIMESTAMP
                 WHERE customer_id = ? AND used_at IS NULL'
            )->execute([$customer['customer_id']]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $this->publicCustomer($customer);
    }

    /** @return array<string, mixed>|null */
    public function customer(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.email, c.first_name, c.last_name, c.phone, c.company, c.tax_id,
                    c.status, c.password_reset_required
             FROM commerce_customers c
             INNER JOIN commerce_stores s ON s.id = c.store_id
             WHERE c.id = ? AND c.status = "active" AND s.code = ? LIMIT 1'
        );
        $statement->execute([$customerId, $this->storeCode]);
        $customer = $statement->fetch(PDO::FETCH_ASSOC);

        return $customer ? $this->publicCustomer($customer) : null;
    }

    private function assertPassword(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new \DomainException('Hasło musi mieć co najmniej 12 znaków.');
        }
        if (!preg_match('/[a-z]/u', $password) || !preg_match('/[A-Z]/u', $password) || !preg_match('/\d/u', $password)) {
            throw new \DomainException('Hasło musi zawierać małą i wielką literę oraz cyfrę.');
        }
    }

    private function storeId(): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM commerce_stores WHERE code = ? AND status = "active" LIMIT 1');
        $statement->execute([$this->storeCode]);
        $id = (int) $statement->fetchColumn();
        if ($id <= 0) {
            throw new \DomainException('Sklep jest niedostępny.');
        }

        return $id;
    }

    /** @return array<string, mixed>|null */
    private function customerByEmail(int $storeId, string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, password_hash, first_name, last_name, phone, company, tax_id,
                    status, password_reset_required
             FROM commerce_customers WHERE store_id = ? AND email = ? LIMIT 1'
        );
        $statement->execute([$storeId, $email]);
        $customer = $statement->fetch(PDO::FETCH_ASSOC);

        return $customer ?: null;
    }

    private function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new \DomainException('Podaj prawidłowy adres e-mail.');
        }

        return $email;
    }

    /** @return array<int, string> */
    private function identityHashes(string $email, string $remoteIdentity): array
    {
        $remoteIdentity = trim($remoteIdentity);

        return [
            hash_hmac('sha256', 'email|' . $email, $this->pepper),
            hash_hmac('sha256', 'remote|' . substr($remoteIdentity, 0, 190), $this->pepper),
        ];
    }

    private function recentFailures(int $storeId, string $action, string $identityHash): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM commerce_customer_auth_attempts
             WHERE store_id = ? AND action = ? AND identity_hash = ? AND succeeded = 0
               AND attempted_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? SECOND)'
        );
        $statement->execute([$storeId, $action, $identityHash, self::ATTEMPT_WINDOW_SECONDS]);

        return (int) $statement->fetchColumn();
    }

    private function recordAttempt(int $storeId, string $action, string $identityHash, bool $succeeded): void
    {
        $this->pdo->prepare(
            'INSERT INTO commerce_customer_auth_attempts (store_id, action, identity_hash, succeeded)
             VALUES (?, ?, ?, ?)'
        )->execute([$storeId, $action, $identityHash, $succeeded ? 1 : 0]);
    }

    /** @param array<string, mixed> $customer @return array<string, mixed> */
    private function publicCustomer(array $customer): array
    {
        return [
            'id' => (int) ($customer['customer_id'] ?? $customer['id']),
            'email' => (string) $customer['email'],
            'first_name' => (string) ($customer['first_name'] ?? ''),
            'last_name' => (string) ($customer['last_name'] ?? ''),
            'phone' => (string) ($customer['phone'] ?? ''),
            'company' => (string) ($customer['company'] ?? ''),
            'tax_id' => (string) ($customer['tax_id'] ?? ''),
        ];
    }
}
