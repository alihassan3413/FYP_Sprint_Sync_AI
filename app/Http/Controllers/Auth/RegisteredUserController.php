<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Workspace\Actions\CreateWorkspaceAction;
use App\Support\Time\UserTime;
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
    public function store(Request $request, CreateWorkspaceAction $createWorkspace): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'workspace_name' => 'nullable|string|max:60',
            'timezone' => UserTime::rules(),
        ]);

        $user = DB::transaction(function () use ($request, $validated, $createWorkspace) {
            $user = User::create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => Hash::make($request->string('password')->toString()),
                'timezone' => $validated['timezone'] ?? null,
            ]);

            $createWorkspace->handleForUser(
                $user,
                $request->filled('workspace_name')
                    ? $request->string('workspace_name')->toString()
                    : $user->name."'s Workspace",
            );

            return $user->refresh();
        });

        event(new Registered($user));

        Auth::login($user);

        return to_route('dashboard', ['workspace' => $user->activeWorkspaceOrFail()->slug]);
    }
}
