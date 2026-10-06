<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Models\InvoicingProfile;

/**
 * The bill_from snapshot copied onto an invoice from the workspace's invoicing
 * details. The logo is referenced by its content-addressed path, which never
 * changes meaning, so the invoice keeps exactly the logo it was prepared with.
 */
final class SenderSnapshot
{
    /** What an invoice must say about its sender before it can be approved or issued. */
    public const REQUIRED = ['business_name', 'billing_email', 'address_line1', 'city', 'country'];

    /**
     * @return array<string, string|null>|null
     */
    public static function from(?InvoicingProfile $profile): ?array
    {
        if ($profile === null) {
            return null;
        }

        return [...$profile->only(InvoicingProfile::DETAIL_FIELDS), 'logo_path' => $profile->logo_path];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function isComplete(?array $snapshot): bool
    {
        if ($snapshot === null) {
            return false;
        }

        foreach (self::REQUIRED as $field) {
            if (blank($snapshot[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
