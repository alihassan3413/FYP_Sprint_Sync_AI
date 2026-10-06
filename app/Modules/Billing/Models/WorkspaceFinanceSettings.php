<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Data\Currency;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $workspace_id
 * @property Currency $reporting_currency the currency finance totals are shown in
 */
final class WorkspaceFinanceSettings extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reporting_currency' => Currency::class];
    }

    /** USD until the owner picks one. */
    public static function reportingCurrency(int $workspaceId): Currency
    {
        return self::query()->where('workspace_id', $workspaceId)->value('reporting_currency') ?? Currency::USD;
    }
}
