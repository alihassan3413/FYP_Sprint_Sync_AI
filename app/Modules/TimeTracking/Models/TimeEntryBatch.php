<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Models;

use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One "Add previous hours" submission (shared project, period and reason).
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $idempotency_key
 * @property int|null $project_id
 * @property string $project_name
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $note
 * @property int|null $created_by
 */
final class TimeEntryBatch extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'batch_id');
    }
}
