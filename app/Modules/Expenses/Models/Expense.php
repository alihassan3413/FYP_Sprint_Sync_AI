<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use App\Models\User;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\Money;
use App\Modules\Billing\Models\Client;
use App\Modules\Expenses\Data\ExpenseCategory;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One expense in its own currency (integer minor units). Never edited after
 * it is recorded except to void it; a correction is "void, then record the
 * right one", so history stays explainable.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property ExpenseCategory $category
 * @property string $description
 * @property string|null $vendor
 * @property int $amount_minor
 * @property Currency $currency
 * @property Carbon $expense_date
 * @property int|null $recurring_expense_id
 * @property Carbon|null $period_start the month a recurring occurrence belongs to
 * @property int|null $client_id
 * @property int|null $project_id
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 */
final class Expense extends Model
{
    use HasPublicId;

    private const VOID_COLUMNS = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'currency' => Currency::class,
            'amount_minor' => 'integer',
            'expense_date' => 'date',
            'period_start' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $expense) {
            if (array_diff(array_keys($expense->getDirty()), self::VOID_COLUMNS) !== []) {
                throw new LogicException('Expenses are not edited. Void it and record the correct one.');
            }
        });

        self::deleting(fn () => throw new LogicException('Expenses are never deleted. Void them instead.'));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    public function money(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function recurring(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class, 'recurring_expense_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
