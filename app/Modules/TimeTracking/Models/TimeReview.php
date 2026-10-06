<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Models;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\ReviewKind;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something for the owner to look at, and their decision. subject_key is
 * unique, so the same lunch, idle period or activity window is never flagged
 * twice however often it is reported.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $person_id
 * @property ReviewKind $kind
 * @property string $severity info | review
 * @property Carbon $work_date
 * @property string|null $subject_key
 * @property int|null $seconds
 * @property string $summary
 * @property array<string, mixed>|null $details
 * @property string $status open | resolved
 * @property string|null $decision
 * @property string|null $note
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 */
final class TimeReview extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['kind' => ReviewKind::class, 'work_date' => 'date', 'details' => 'array', 'resolved_at' => 'datetime', 'seconds' => 'integer'];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
