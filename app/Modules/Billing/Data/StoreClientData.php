<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

/**
 * The editable details of a client, used for both creating and updating.
 */
final readonly class StoreClientData
{
    /**
     * @param  array<int, string>  $cc_emails
     */
    public function __construct(
        public string $name,
        public string $billing_email,
        public Currency $currency,
        public array $cc_emails = [],
        public ?string $address = null,
        public ?string $tax_id = null,
    ) {}

    /**
     * @return array{name: string, billing_email: string, currency: Currency, cc_emails: array<int, string>|null, address: string|null, tax_id: string|null}
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'billing_email' => $this->billing_email,
            'currency' => $this->currency,
            'cc_emails' => $this->cc_emails === [] ? null : $this->cc_emails,
            'address' => $this->address,
            'tax_id' => $this->tax_id,
        ];
    }
}
