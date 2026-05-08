<?php

namespace App\Modules\Workspace\Actions;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Str;

final class CreateWorkspaceForUserAction
{
    public function handle(User $user, string $workspaceName): Workspace
    {
        return DB::transaction(function () use ($user, $workspaceName) {
            $workspace = Workspace::create([
                'name' => $workspaceName,
                'slug' => $this->generateSlug($workspaceName),
                'owner_id' => $user->id,
            ]);

            $workspace->users()->attach($user->id, ['role' => UserRole::OWNER->value]);

            return $workspace;
        });
    }

    private function generateSlug(string $name): string
    {
        return Str::slug($name).'-'.Str::random(6);
    }
}
