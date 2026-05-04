<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Models\User;
use App\Modules\Workspace\Data\WorkspaceData;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\WorkspaceService;
use App\UserRole;
use Illuminate\Support\Facades\DB;

final class CreateWorkspaceAction
{
    public function __construct(private WorkspaceService $service) {}

    public function handle(WorkspaceData $data, User $owner): Workspace
    {

        return DB::transaction(function() use ($data, $owner) {
            $workspace = Workspace::create([
                'name' => $data->name,
                'slug' => $data->slug,
                'settings' => $data->settings,
                'is_active' => $data->is_active,
                'owner_id' =>  $owner->id,
            ]);

            $workspace->users()->attach($owner->id, ['role' => UserRole::OWNER->value]);

            $this->service->switchTo($owner, $workspace);

            return $workspace;
        });
    }
}
