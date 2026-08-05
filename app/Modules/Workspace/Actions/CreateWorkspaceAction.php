<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Models\User;
use App\Modules\Workspace\Data\WorkspaceData;
use App\Modules\Workspace\Exceptions\WorkspaceException;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Rules\WorkspaceSlug;
use App\Modules\Workspace\Services\WorkspaceService;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateWorkspaceAction
{
    public function __construct(private readonly WorkspaceService $service) {}

    public function handle(WorkspaceData $data, User $owner): Workspace
    {
        $this->ensureOwnerIsUnderLimit($owner);

        return DB::transaction(function () use ($data, $owner) {
            $workspace = Workspace::create([
                'name' => $data->name,
                'slug' => $data->slug,
                'settings' => $data->settings,
                'is_active' => $data->is_active,
                'owner_id' => $owner->id,
            ]);

            $workspace->users()->attach($owner->id, ['role' => UserRole::OWNER->value]);

            $this->service->switchTo($owner, $workspace);

            return $workspace;
        });
    }

    public function handleForUser(User $owner, string $name): Workspace
    {
        return $this->handle(
            new WorkspaceData(name: $name, slug: $this->uniqueSlug($name)),
            $owner,
        );
    }

    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '' || in_array($base, WorkspaceSlug::RESERVED, true)) {
            $base = 'workspace';
        }

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Workspace::query()->where('slug', $slug)->exists());

        return $slug;
    }

    private function ensureOwnerIsUnderLimit(User $owner): void
    {
        $limit = (int) config('workspace.max_per_owner');
        $current = Workspace::query()->where('owner_id', $owner->id)->count();

        if ($current >= $limit) {
            throw WorkspaceException::limitReached($limit, $current);
        }
    }
}
