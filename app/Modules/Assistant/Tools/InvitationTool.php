<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Workspace\Actions\CreateWorkspaceInvitationAction;
use App\Modules\Workspace\Data\WorkspaceInvitationData;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Support\Str;

final class InvitationTool implements AssistantTool
{
    public function __construct(private readonly CreateWorkspaceInvitationAction $createInvitation) {}

    public function name(): string
    {
        return 'invite_user';
    }

    public function description(): string
    {
        return 'Invites a person to the current workspace by email. Ask for the email address if it is missing. '
            .'The base role is "member" by default; use "admin" only when the user asks for it, and "client" for an '
            .'outside client or stakeholder. A client sees nothing until they are added to a project with '
            .'add_project_member, and what they can do there comes from their custom role. '
            .'To invite someone with a custom workspace role, pass its exact name in custom_role — '
            .'look the name up with get_workspace_info (include_roles=true) first, never guess it. '
            .'Show the email, the base role and the custom role before confirmation.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                    'description' => 'The email address of the person to invite.',
                ],
                'role' => [
                    'type' => 'string',
                    'enum' => UserRole::invitationRoles(),
                    'description' => 'The base role to assign to the invitee. Defaults to "member". '
                        .'Use "client" for someone outside the team who should only see specific projects.',
                ],
                'custom_role' => [
                    'type' => 'string',
                    'description' => 'Optional custom workspace role name, exactly as returned by get_workspace_info. '
                        .'The custom role adds permissions on top of the base role.',
                ],
            ],
            'required' => ['email'],
            'additionalProperties' => false,
        ];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->workspace !== null
            && $context->user->can('invite', $context->workspace);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ToolContext $context): array
    {
        $workspace = $context->workspace;
        $user = $context->user;

        if ($workspace === null || ! $user->can('invite', $workspace)) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => 'You do not have permission to invite people to this workspace.',
            ];
        }

        $email = (string) $args['email'];

        if ($workspace->users()->where('users.email', $email)->exists()) {
            return [
                'success' => false,
                'error_code' => 'already_member',
                'error' => "{$email} is already a member of {$workspace->name}.",
            ];
        }

        $customRoleName = isset($args['custom_role']) ? trim((string) $args['custom_role']) : '';
        $customRole = null;

        if ($customRoleName !== '') {
            $customRole = $this->resolveCustomRole($workspace, $customRoleName);

            if ($customRole === null) {
                $available = $workspace->roles()->orderBy('name')->pluck('name')->all();

                return [
                    'success' => false,
                    'error_code' => 'unknown_custom_role',
                    'error' => "{$workspace->name} has no custom role named \"{$customRoleName}\".",
                    'available_custom_roles' => array_map(
                        fn (string $name) => UntrustedText::inline($name),
                        $available,
                    ),
                ];
            }
        }

        $invitation = $this->createInvitation->handle($workspace, $user, WorkspaceInvitationData::from([
            'email' => $email,
            'role' => $args['role'] ?? UserRole::MEMBER->value,
            'workspace_role_id' => $customRole?->id,
        ]));

        $customRoleLabel = $customRole === null ? null : UntrustedText::inline($customRole->name);

        return [
            'success' => true,
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'custom_role' => $customRoleLabel,
                'expires_at' => $invitation->expires_at->toDateTimeString(),
            ],
            'message' => $customRole === null
                ? "Invitation sent to {$invitation->email} as {$invitation->role->label()}."
                : "Invitation sent to {$invitation->email} as {$customRoleLabel} (base role {$invitation->role->label()}).",
        ];
    }

    /**
     * Matches a custom role by name or slug, case-insensitively, within the workspace.
     */
    private function resolveCustomRole(Workspace $workspace, string $name): ?WorkspaceRole
    {
        $needle = Str::lower($name);

        return $workspace->roles()
            ->get()
            ->first(fn (WorkspaceRole $role) => Str::lower($role->name) === $needle
                || Str::lower((string) $role->slug) === $needle
                || Str::slug($role->name) === Str::slug($name));
    }
}
