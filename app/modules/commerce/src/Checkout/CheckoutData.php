<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Checkout;

final readonly class CheckoutData
{
    /** @param array<string, mixed>|null $pickupPoint */
    public function __construct(
        public string $email,
        public CustomerAddress $billingAddress,
        public string $shippingMethodCode,
        public string $paymentMethodCode,
        public bool $termsAccepted,
        public bool $privacyAccepted,
        public ?CustomerAddress $shippingAddress = null,
        public ?string $phone = null,
        public ?int $customerId = null,
        public ?string $couponCode = null,
        public ?string $customerNote = null,
        public ?array $pickupPoint = null,
        public bool $invoiceRequested = false,
        public bool $marketingConsent = false,
    ) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Checkout email is invalid.');
        }
        if (trim($shippingMethodCode) === '' || trim($paymentMethodCode) === '') {
            throw new \InvalidArgumentException('Shipping and payment methods are required.');
        }
        if (!$termsAccepted || !$privacyAccepted) {
            throw new \DomainException('Required checkout consents were not accepted.');
        }
        if ($customerId !== null && $customerId <= 0) {
            throw new \InvalidArgumentException('Customer ID is invalid.');
        }
        if ($invoiceRequested && $billingAddress->company !== null && trim((string) $billingAddress->taxId) === '') {
            throw new \InvalidArgumentException('Company invoice requires a tax ID.');
        }
    }
}
