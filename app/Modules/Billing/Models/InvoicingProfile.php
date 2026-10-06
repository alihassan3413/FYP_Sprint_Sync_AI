<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The workspace's details as the sender of its invoices ("Settings →
 * Invoicing"). Invoices snapshot these into bill_from; they never read this
 * row again to describe themselves.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $business_name
 * @property string|null $legal_name
 * @property string $billing_email
 * @property string|null $phone
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string $city
 * @property string|null $region
 * @property string|null $postal_code
 * @property string $country
 * @property string|null $tax_id
 * @property string|null $logo_path content-addressed (…/{sha256}.png), never overwritten
 */
final class InvoicingProfile extends Model
{
    /** Private disk: logos are only streamed through routes that check the policy. */
    public const LOGO_DISK = 'local';

    public const DETAIL_FIELDS = [
        'business_name', 'legal_name', 'billing_email', 'phone',
        'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country', 'tax_id',
    ];

    protected $fillable = self::DETAIL_FIELDS;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
