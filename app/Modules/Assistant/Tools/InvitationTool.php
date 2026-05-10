<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Workspace\Actions\CreateWorkspaceInvitationAction;
use App\Modules\Workspace\Data\WorkspaceInvitationData;

class InvitationTool implements AssistantTool
{
    public function __construct(
        private readonly CreateWorkspaceInvitationAction $createInvitation,
    ) {}

    public function name(): string
    {
        return 'invite_user';
    }

    public function description(): string
    {
        return 'Invites a user to the current workspace. Ask for the email address if missing. The default role is "member". If the user says admin, use role "admin". Before confirmation, show both email and role.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'email' => [
                    'type' => 'string',
                    'description' => 'The email address of the person to invite.',
                    'format' => 'email',
                ],
                'role' => [
                    'type' => 'string',
                    'description' => 'The role to assign to the invitee (optional, defaults to "member").',
                    'enum' => ['member', 'admin'],
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

    public function authorize(User $user): bool
    {
        return $user !== null;
    }

    public function execute(array $args, User $user): array
    {
        $email = $args['email'] ?? null;
        $role = $args['role'] ?? 'member';

        if (! $email) {
            return [
                'error' => true,
                'message' => 'Please provide the email address of the person you want to invite.',
            ];
        }

        if (! in_array($role, ['member', 'admin'], true)) {
            $role = 'member';
        }

        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return [
                'error' => true,
                'message' => 'No active workspace found. Please select a workspace first.',
            ];
        }

        $data = WorkspaceInvitationData::from([
            'email' => $email,
            'role' => $role,
        ]);

        $invitation = $this->createInvitation->handle($workspace, $user, $data);

        return [
            'invitation_id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toDateTimeString(),
            'message' => "Invitation sent to {$invitation->email} with role {$invitation->role}.",
        ];
    }
}
