<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Models\Client;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * What the browser may know about a client. The sequential id and the logo's
 * storage path are deliberately absent: the client is addressed by public_id
 * and the logo by an authorized URL.
 */
#[TypeScript]
final class ClientData extends Data
{
    public function __construct(
        public string $public_id,
        public string $name,
        public string $billing_email,
        #[TypeScriptType('string[]')]
        public array $cc_emails,
        public string $currency,
        public ?string $address,
        public ?string $tax_id,
        public ?string $logo_url,
        public ?string $archived_at,
        public string $created_at,
    ) {}

    public static function fromModel(Client $client): self
    {
        return new self(
            public_id: $client->public_id,
            name: $client->name,
            billing_email: $client->billing_email,
            cc_emails: $client->cc_emails ?? [],
            currency: $client->currency->value,
            address: $client->address,
            tax_id: $client->tax_id,
            logo_url: self::logoUrl($client),
            archived_at: $client->archived_at?->toIso8601String(),
            created_at: $client->created_at->toIso8601String(),
        );
    }

    /**
     * The version query changes whenever a new logo is stored, so browsers
     * fetch the new image instead of a cached one.
     */
    private static function logoUrl(Client $client): ?string
    {
        if (! $client->hasLogo()) {
            return null;
        }

        return route('workspace.clients.logo.show', [
            'workspace' => $client->workspace->slug,
            'client' => $client->public_id,
            'v' => substr(hash('sha256', (string) $client->logo_path), 0, 12),
        ]);
    }
}
