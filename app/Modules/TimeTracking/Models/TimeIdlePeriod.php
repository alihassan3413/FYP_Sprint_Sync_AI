<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Models;

use App\Modules\People\Models\Person;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An automatic idle pause: from the last activity (started_at) until the
 * timer resumed (ended_at). decision: pending, kept (as work, with a reason)
 * or discarded. The detection itself is never removed.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $person_id
 * @property int $time_session_id
 * @property Carbon $started_at
 * @property Carbon $detected_at
 * @property Carbon|null $ended_at
 * @property int $threshold_seconds
 * @property string $decision
 * @property string|null $reason
 * @property int|null $kept_entry_id
 * @property Carbon|null $decided_at
 */
final class TimeIdlePeriod extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'detected_at' => 'datetime', 'ended_at' => 'datetime', 'decided_at' => 'datetime', 'threshold_seconds' => 'integer'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TimeSession::class, 'time_session_id');
    }

    public function seconds(): int
    {
        return $this->ended_at === null ? 0 : max(0, (int) $this->started_at->diffInSeconds($this->ended_at, false));
    }
}
