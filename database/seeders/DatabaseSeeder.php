<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $workspace = Workspace::factory()->ownedBy($owner)->create([
            'name' => 'SprintSync',
            'slug' => 'sprintsync',
        ]);

        $owner->forceFill(['current_workspace_id' => $workspace->id])->save();

        $admin = User::factory()->create(['name' => 'Admin User', 'email' => 'admin@example.com']);
        $member = User::factory()->create(['name' => 'Member User', 'email' => 'member@example.com']);

        $workspace->users()->attach($admin->id, ['role' => UserRole::ADMIN->value]);
        $workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        WorkspaceRole::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Developer',
            'slug' => 'developer',
            'permissions' => ['projects.view' => true, 'projects.create' => true],
        ]);
    }
}
