<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Models\User;
use App\Modules\People\Actions\BuildPeopleDirectoryAction;
use App\Modules\People\Actions\CreatePersonAction;
use App\Modules\People\Actions\GivePersonAccessAction;
use App\Modules\People\Actions\SetPersonUserAction;
use App\Modules\People\Actions\UpdatePersonAction;
use App\Modules\People\Data\DepartmentData;
use App\Modules\People\Data\PersonData;
use App\Modules\People\Http\Requests\GivePersonAccessRequest;
use App\Modules\People\Http\Requests\StorePersonRequest;
use App\Modules\People\Http\Requests\UpdatePersonRequest;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PersonController
{
    /**
     * The Team page. Everyone who could see the team roster still can; job
     * titles, departments and people without a login are only merged in for
     * viewers allowed to see team profiles.
     */
    public function index(Request $request, Workspace $workspace, BuildPeopleDirectoryAction $directory): Response
    {
        $user = $request->user();
        abort_unless($user->can('viewTeam', $workspace), 403);

        $canViewProfiles = $user->can('viewAny', [Person::class, $workspace]);
        $canManagePeople = $user->can('create', [Person::class, $workspace]);

        return Inertia::render('people/index', [
            'members' => $directory->handle($workspace, $user, $canViewProfiles),
            'canViewProfiles' => $canViewProfiles,
            'canManagePeople' => $canManagePeople,
            ...$this->accessOptions($request, $workspace),
            ...($canManagePeople ? $this->formOptions($workspace) : []),
        ]);
    }

    public function show(Request $request, Workspace $workspace, Person $person, BuildPeopleDirectoryAction $directory): Response
    {
        abort_unless($request->user()->can('view', $person), 403);

        $person->load(['department', 'user:id,name,email']);

        return Inertia::render('people/show', [
            'person' => PersonData::fromModel($person),
            'access' => $directory->forPerson($workspace, $request->user(), $person),
            'canManagePeople' => $request->user()->can('update', $person),
            ...$this->accessOptions($request, $workspace),
            ...$this->formOptions($workspace),
        ]);
    }

    public function store(StorePersonRequest $request, Workspace $workspace, CreatePersonAction $action): RedirectResponse
    {
        $person = $action->handle($workspace, $request->toDTO(), $request->user());

        // "added" lets the profile ask whether they need SprintSync access.
        return to_route('workspace.people.show', ['workspace' => $workspace->slug, 'person' => $person, 'added' => 1])
            ->with('success', $person->user_id === null
                ? "{$person->name} added."
                : "{$person->name} added and connected to their SprintSync login.");
    }

    public function update(UpdatePersonRequest $request, Workspace $workspace, Person $person, UpdatePersonAction $action): RedirectResponse
    {
        $person = $action->handle($person, $request->toDTO(), $request->user());

        return back()->with('success', "{$person->name} updated.");
    }

    public function giveAccess(GivePersonAccessRequest $request, Workspace $workspace, Person $person, GivePersonAccessAction $action): RedirectResponse
    {
        $outcome = $action->handle($person, $request->toInvitation(), $request->user());

        return back()->with('success', $outcome === 'linked'
            ? "{$person->name} already had SprintSync access, so their profile is now linked."
            : "Invitation sent to {$person->email}.");
    }

    public function unlinkUser(Request $request, Workspace $workspace, Person $person, SetPersonUserAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('update', $person), 403);

        $action->handle($person, null, $request->user());

        return back()->with('success', "{$person->name} is no longer linked to a SprintSync login.");
    }

    /**
     * What the viewer may do with SprintSync access, unchanged from the old
     * Team page: inviting, changing access roles and removing access each keep
     * their own workspace permission.
     *
     * @return array{canManageMembers: bool, canInviteMembers: bool, workspaceRoles: mixed}
     */
    private function accessOptions(Request $request, Workspace $workspace): array
    {
        return [
            'canManageMembers' => $request->user()->can('manageMembers', $workspace),
            'canInviteMembers' => $request->user()->can('invite', $workspace),
            'workspaceRoles' => $workspace->roles()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (WorkspaceRole $role) => ['id' => $role->id, 'name' => $role->name])
                ->values(),
        ];
    }

    /**
     * Departments to pick from, and workspace members (never guest clients)
     * who can be connected. A
     * member already linked to someone carries that person's public id so the
     * form can show who has them.
     *
     * @return array{departments: mixed, linkableUsers: mixed}
     */
    private function formOptions(Workspace $workspace): array
    {
        $linkedTo = $workspace->people()->whereNotNull('user_id')->pluck('public_id', 'user_id');

        return [
            'departments' => $workspace->departments()->orderBy('name')->get()->map(DepartmentData::fromModel(...))->values(),
            'linkableUsers' => $workspace->users()
                ->select('users.id', 'users.name', 'users.email')
                ->wherePivot('role', '!=', UserRole::CLIENT->value)
                ->orderBy('users.name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'linked_person' => $linkedTo->get($user->id),
                ])
                ->values(),
        ];
    }
}
