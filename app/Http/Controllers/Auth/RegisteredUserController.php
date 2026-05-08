<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Workspace\Actions\CreateWorkspaceForUserAction;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, CreateWorkspaceForUserAction $createWorkspaceForUserAction): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'workspace_name' => 'nullable|string|max:255',
        ]);

        $user = DB::transaction(function () use ($request, $createWorkspaceForUserAction) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $workspaceName = $request->input('workspace_name', $user->name."'s Workspace");
            $workspace = $createWorkspaceForUserAction->handle($user, $workspaceName);

            $user->current_workspace_id = $workspace->id;
            $user->save();

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return to_route('dashboard', ['workspace' => $user->activeWorkspaceOrFail()->slug]);
    }
}
