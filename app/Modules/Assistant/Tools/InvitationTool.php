<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Workspace\Actions\CreateWorkspaceInvitationAction;
use App\Modules\Workspace\Data\WorkspaceInvitationData;
use App\UserRole;

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
            .'The default role is "member"; use "admin" only when the user asks for it. '
            .'Show both the email and the role before confirmation.';
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
                    'description' => 'The role to assign to the invitee. Defaults to "member".',
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

        $invitation = $this->createInvitation->handle($workspace, $user, WorkspaceInvitationData::from([
            'email' => $email,
            'role' => $args['role'] ?? UserRole::MEMBER->value,
        ]));

        return [
            'success' => true,
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at->toDateTimeString(),
            ],
            'message' => "Invitation sent to {$invitation->email} as {$invitation->role->label()}.",
        ];
    }
}
