<?php

namespace App\Http\Middleware;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Services\WorkspaceService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {

        return array_merge(parent::share($request), [

            'auth' => [
                'user' => $request->user(),
                'timezone' => $request->user()?->resolvedTimezone(),
            ],

            'workspace' => fn () => app(WorkspaceService::class)->inertiaFor($request->user()),

            'navigation' => fn () => $this->navigationFor($request),

            'notifications' => fn () => $request->user() ? [
                'unread_count' => $request->user()->unreadNotifications()->count(),
                'recent' => $request->user()->notifications()->latest()->limit(10)->get()->map(fn ($notification) => [
                    'id' => $notification->id,
                    'type' => $notification->data['type'] ?? null,
                    'title' => $notification->data['title'] ?? '',
                    'message' => $notification->data['message'] ?? '',
                    'url' => $notification->data['url'] ?? null,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at->toIso8601String(),
                ]),
            ] : null,

            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
            ],
        ]);
    }

    /**
     * @return array<string, bool>|null
     */
    private function navigationFor(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $workspace = app(WorkspaceService::class)->currentFor($user);

        if ($workspace === null) {
            return null;
        }

        $hasAccessibleProjects = $workspace->accessibleProjectsFor($user)->exists();
        $canViewAuditLog = $user->can('viewAny', [AuditLog::class, $workspace]);

        return [
            'projects' => $hasAccessibleProjects || $user->can('create', [Project::class, $workspace]),
            'team' => true,
            'analytics' => $hasAccessibleProjects,
            'archive' => $hasAccessibleProjects,
            'audit' => $canViewAuditLog,
            'workspaceSettings' => $canViewAuditLog
                || $user->can('update', $workspace)
                || $user->can('delete', $workspace)
                || $user->can('manageMembers', $workspace)
                || $user->can('manageRoles', $workspace)
                || $user->can('invite', $workspace),
        ];
    }
}
