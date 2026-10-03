<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Database\Factories\ClientFactory;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A business the workspace sends invoices to.
 *
 * workspace_id, public_id, logo_path and archived_at are deliberately not
 * fillable: a client is always created through its workspace's relation, the
 * public id is generated once and never changes, and logo and archive state
 * go through their own audited actions.
 *
 * Routes and props use public_id (a ULID), never the sequential id. That is a
 * privacy measure only: access is still decided by tenancy and ClientPolicy.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $name
 * @property string $billing_email
 * @property array<int, string>|null $cc_emails
 * @property Currency $currency
 * @property string|null $address
 * @property string|null $tax_id
 * @property string|null $logo_path
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, HasUlids;

    /**
     * Logos are client data, so they live on the private disk and are only
     * served through ClientLogoController after a policy check.
     */
    public const LOGO_DISK = 'local';

    protected $fillable = [
        'name',
        'billing_email',
        'cc_emails',
        'currency',
        'address',
        'tax_id',
    ];

    protected function casts(): array
    {
        return [
            'cc_emails' => 'array',
            'currency' => Currency::class,
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (Client $client) {
            if ($client->isDirty('public_id')) {
                throw new LogicException('A client\'s public id cannot be changed.');
            }
        });
    }

    /**
     * HasUlids fills these on create. The integer primary key stays as is.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function hasLogo(): bool
    {
        return $this->logo_path !== null;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }
}
