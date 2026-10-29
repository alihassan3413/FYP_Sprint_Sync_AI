<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use App\Modules\People\Data\TeamImportOutcome;
use App\Modules\People\Data\TeamImportRowStatus;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TeamImportRow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'raw' => 'array',
            'messages' => 'array',
            'status' => TeamImportRowStatus::class,
            'outcome' => TeamImportOutcome::class,
            'processed_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(TeamImport::class, 'team_import_id');
    }

    public function customRole(): BelongsTo
    {
        return $this->belongsTo(WorkspaceRole::class, 'workspace_role_id');
    }

    public function schedulePerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'schedule_person_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }
}
