<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Models\Client;
use App\Modules\Expenses\Data\ExpenseCategory;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A monthly expense template ("Office rent, PKR 300,000, on the 1st").
 * Changing it only affects months not yet recorded: each month's occurrence
 * is its own snapshot.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property ExpenseCategory $category
 * @property string $description
 * @property string|null $vendor
 * @property int $amount_minor
 * @property Currency $currency
 * @property int $day_of_month 1–28
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property int|null $client_id
 * @property int|null $project_id
 * @property string|null $notes
 * @property Carbon|null $stopped_at
 */
final class RecurringExpense extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'currency' => Currency::class,
            'amount_minor' => 'integer',
            'day_of_month' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'stopped_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
