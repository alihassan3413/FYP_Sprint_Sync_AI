<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Models;

use App\Models\User;
use App\Modules\TimeTracking\Data\OvertimeMode;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One version of the workspace's time rules. Never edited: a change is a new
 * version (see TimePolicies).
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property Carbon $effective_from
 * @property bool $idle_enabled
 * @property int|null $idle_seconds
 * @property int|null $weekly_target_seconds
 * @property bool $lunch_enabled
 * @property int|null $lunch_allowance_seconds
 * @property OvertimeMode $overtime_mode
 * @property bool $integrity_enabled
 * @property int|null $created_by
 */
final class TimePolicy extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'idle_enabled' => 'boolean',
            'idle_seconds' => 'integer',
            'weekly_target_seconds' => 'integer',
            'lunch_enabled' => 'boolean',
            'lunch_allowance_seconds' => 'integer',
            'overtime_mode' => OvertimeMode::class,
            'integrity_enabled' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Time policy versions are never edited. Save a new version.'));
        self::deleting(fn () => throw new LogicException('Time policy versions are never deleted.'));
    }
}
