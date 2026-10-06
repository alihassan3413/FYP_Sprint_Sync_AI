<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Actions\CreateWorkspaceInvitationAction;
use App\Modules\Workspace\Data\WorkspaceInvitationData;
use Illuminate\Support\Str;

/**
 * "Give SprintSync access" from a person's profile.
 *
 * The invitation goes to the person's email (saved on the profile if it was
 * missing or different) so the pending invite shows on their row, and the
 * person is linked automatically when it is accepted. When that email already
 * belongs to a workspace member there is nobody to invite: the person is
 * simply linked to them.
 */
final class GivePersonAccessAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLogger,
        private readonly SetPersonUserAction $linker,
        private readonly CreateWorkspaceInvitationAction $invitations,
    ) {}

    /**
     * @return 'linked'|'invited'
     */
    public function handle(Person $person, WorkspaceInvitationData $invitation, User $actor): string
    {
        $workspace = $person->workspace;

        if (Str::lower((string) $person->email) !== $invitation->email) {
            $person->forceFill(['email' => $invitation->email])->save();

            $this->auditLogger->handle(
                $workspace,
                null,
                $actor,
                AuditAction::PERSON_UPDATED,
                "{$actor->name} updated {$person->name}.",
                $person,
                ['changed' => ['email']],
            );
        }

        $member = $workspace->users()->where('users.email', $invitation->email)->first();

        if ($member !== null) {
            $this->linker->handle($person, $member, $actor);

            return 'linked';
        }

        $this->invitations->handle($workspace, $actor, $invitation);

        return 'invited';
    }
}
