<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Checkout;

final readonly class CustomerAddress
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $addressLine1,
        public string $postalCode,
        public string $city,
        public string $countryCode = 'PL',
        public ?string $company = null,
        public ?string $taxId = null,
        public ?string $addressLine2 = null,
        public ?string $phone = null,
    ) {
        foreach ([$firstName, $lastName, $addressLine1, $postalCode, $city] as $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException('Address has missing required fields.');
            }
        }
        if (preg_match('/^[A-Z]{2}$/', strtoupper($countryCode)) !== 1) {
            throw new \InvalidArgumentException('Address country code is invalid.');
        }
        if ($taxId !== null && strtoupper($countryCode) === 'PL' && !$this->validPolishTaxId($taxId)) {
            throw new \InvalidArgumentException('Polish tax ID is invalid.');
        }
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'first_name' => trim($this->firstName),
            'last_name' => trim($this->lastName),
            'company' => $this->nullable($this->company),
            'tax_id' => $this->nullable($this->taxId),
            'address_line1' => trim($this->addressLine1),
            'address_line2' => $this->nullable($this->addressLine2),
            'postal_code' => trim($this->postalCode),
            'city' => trim($this->city),
            'country_code' => strtoupper($this->countryCode),
            'phone' => $this->nullable($this->phone),
        ];
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function validPolishTaxId(string $taxId): bool
    {
        $digits = preg_replace('/\D+/', '', $taxId);
        if (!is_string($digits) || strlen($digits) !== 10) {
            return false;
        }
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += ((int) $digits[$index]) * $weight;
        }
        $checksum = $sum % 11;

        return $checksum !== 10 && $checksum === (int) $digits[9];
    }
}
