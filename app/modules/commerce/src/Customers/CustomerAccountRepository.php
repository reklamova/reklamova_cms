<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Customers;

use PDO;
use Reklamova\Cms\Commerce\Orders\OrderAccessRepository;

final class CustomerAccountRepository
{
    public function __construct(private PDO $pdo, private string $storeCode)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function orders(int $customerId, int $limit = 50): array
    {
        if ($customerId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.order_number, o.order_status, o.payment_status, o.currency,
                    o.total_minor, o.placed_at, o.created_at
             FROM commerce_orders o
             INNER JOIN commerce_stores s ON s.id = o.store_id
             WHERE o.customer_id = ? AND s.code = ?
             ORDER BY COALESCE(o.placed_at, o.created_at) DESC, o.id DESC
             LIMIT ' . $limit
        );
        $statement->execute([$customerId, $this->storeCode]);

        return array_map(static function (array $order): array {
            $order['id'] = (int) $order['id'];
            $order['total_minor'] = (int) $order['total_minor'];
            return $order;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function order(int $customerId, int $orderId): ?array
    {
        return (new OrderAccessRepository($this->pdo))->findByCustomer($orderId, $customerId, $this->storeCode);
    }

    /** @return array<int, array<string, mixed>> */
    public function addresses(int $customerId): array
    {
        if (!$this->activeCustomer($customerId)) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT a.* FROM commerce_customer_addresses a
             INNER JOIN commerce_customers c ON c.id=a.customer_id
             INNER JOIN commerce_stores s ON s.id=c.store_id
             WHERE a.customer_id=? AND s.code=?
             ORDER BY a.type, a.is_default DESC, a.id'
        );
        $statement->execute([$customerId, $this->storeCode]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function defaultAddress(int $customerId, string $type): ?array
    {
        if (!in_array($type, ['billing', 'shipping'], true)) {
            return null;
        }
        foreach ($this->addresses($customerId) as $address) {
            if ((string) $address['type'] === $type) {
                return $address;
            }
        }

        return null;
    }

    public function saveAddress(int $customerId, array $data, ?int $addressId = null): int
    {
        if (!$this->activeCustomer($customerId)) {
            throw new \DomainException('Konto klienta jest niedostępne.');
        }
        $type = in_array((string) ($data['type'] ?? ''), ['billing', 'shipping'], true)
            ? (string) $data['type']
            : throw new \DomainException('Nieprawidłowy typ adresu.');
        $line1 = $this->required($data['address_line1'] ?? '', 'Ulica i numer', 255);
        $postal = $this->required($data['postal_code'] ?? '', 'Kod pocztowy', 30);
        $city = $this->required($data['city'] ?? '', 'Miasto', 120);
        $country = strtoupper(trim((string) ($data['country_code'] ?? 'PL')));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new \DomainException('Nieprawidłowy kod kraju.');
        }
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $values = [
            $type,
            $this->nullable($data['label'] ?? '', 120),
            $this->nullable($data['first_name'] ?? '', 120),
            $this->nullable($data['last_name'] ?? '', 120),
            $this->nullable($data['company'] ?? '', 190),
            $this->nullable($data['tax_id'] ?? '', 40),
            $line1,
            $this->nullable($data['address_line2'] ?? '', 255),
            $postal,
            $city,
            $country,
            $this->nullable($data['phone'] ?? '', 80),
            $isDefault,
        ];
        $this->pdo->beginTransaction();
        try {
            if ($isDefault === 1) {
                $this->pdo->prepare('UPDATE commerce_customer_addresses SET is_default=0 WHERE customer_id=? AND type=?')->execute([$customerId, $type]);
            }
            if ($addressId !== null && $addressId > 0) {
                $statement = $this->pdo->prepare('UPDATE commerce_customer_addresses SET type=?,label=?,first_name=?,last_name=?,company=?,tax_id=?,address_line1=?,address_line2=?,postal_code=?,city=?,country_code=?,phone=?,is_default=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND customer_id=?');
                $statement->execute([...$values, $addressId, $customerId]);
                if ($statement->rowCount() === 0 && !$this->addressOwnedBy($addressId, $customerId)) {
                    throw new \DomainException('Nie znaleziono adresu.');
                }
            } else {
                $statement = $this->pdo->prepare('INSERT INTO commerce_customer_addresses (customer_id,type,label,first_name,last_name,company,tax_id,address_line1,address_line2,postal_code,city,country_code,phone,is_default) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $statement->execute([$customerId, ...$values]);
                $addressId = (int) $this->pdo->lastInsertId();
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return (int) $addressId;
    }

    public function deleteAddress(int $customerId, int $addressId): bool
    {
        if (!$this->activeCustomer($customerId) || $addressId <= 0) {
            return false;
        }
        $statement = $this->pdo->prepare('DELETE FROM commerce_customer_addresses WHERE id=? AND customer_id=?');
        $statement->execute([$addressId, $customerId]);

        return $statement->rowCount() === 1;
    }

    private function activeCustomer(int $customerId): bool
    {
        if ($customerId <= 0) {
            return false;
        }
        $statement = $this->pdo->prepare('SELECT 1 FROM commerce_customers c INNER JOIN commerce_stores s ON s.id=c.store_id WHERE c.id=? AND s.code=? AND c.status="active" LIMIT 1');
        $statement->execute([$customerId, $this->storeCode]);

        return (bool) $statement->fetchColumn();
    }

    private function addressOwnedBy(int $addressId, int $customerId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM commerce_customer_addresses WHERE id=? AND customer_id=? LIMIT 1');
        $statement->execute([$addressId, $customerId]);

        return (bool) $statement->fetchColumn();
    }

    private function required(mixed $value, string $label, int $max): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new \DomainException($label . ' jest wymagane i musi mieścić się w limicie.');
        }

        return $value;
    }

    private function nullable(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            throw new \DomainException('Pole adresu przekracza dozwolony limit.');
        }

        return $value === '' ? null : $value;
    }
}
