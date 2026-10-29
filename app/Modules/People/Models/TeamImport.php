<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use App\Models\User;
use App\Modules\People\Data\TeamImportStatus;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TeamImport extends Model
{
    use HasPublicId;

    public const MAX_KILOBYTES = 2048;

    public const MAX_ROWS = 500;

    public const CHUNK_SIZE = 25;

    public const EXPIRES_AFTER_HOURS = 24;

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'status' => TeamImportStatus::class,
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(TeamImportRow::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
