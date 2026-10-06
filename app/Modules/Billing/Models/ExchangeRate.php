<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Data\Currency;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * "1 base = rate quote", from rate_date on. The rate is an exact decimal
 * string. Never edited: a correction is a newer entry.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property Currency $base_currency
 * @property Currency $quote_currency
 * @property string $rate
 * @property Carbon $rate_date
 * @property string $source manual (provider later)
 * @property int|null $created_by
 * @property Carbon $created_at
 */
final class ExchangeRate extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['base_currency' => Currency::class, 'quote_currency' => Currency::class, 'rate_date' => 'date'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Exchange rates are never edited. Enter a newer rate instead.'));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
