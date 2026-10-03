<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Person;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The only place a person's SprintSync login changes.
 *
 * - Setting the link it already has does nothing (so retries are harmless).
 * - Only members of the person's own workspace can be linked.
 * - $reason replaces the default audit wording for automatic (un)links.
 * - UNIQUE(workspace_id, user_id) is the final word on "one user, one person";
 *   a clash from a concurrent request becomes a clear 422, not a 500.
 */
final class SetPersonUserAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Person $person, ?User $user, User $actor, ?string $reason = null): Person
    {
        $previous = $person->user;

        if ($previous?->id === $user?->id) {
            return $person;
        }

        if ($user !== null && ! $person->workspace->hasMember($user)) {
            throw PeopleException::userNotInWorkspace();
        }

        try {
            $person->forceFill(['user_id' => $user?->id])->save();
        } catch (UniqueConstraintViolationException) {
            throw PeopleException::userAlreadyLinked((string) $user?->name);
        }

        $person->setRelation('user', $user);

        if ($previous !== null) {
            $this->auditLogger->handle(
                $person->workspace,
                null,
                $actor,
                AuditAction::PERSON_USER_UNLINKED,
                $reason ?? "{$actor->name} disconnected {$person->name} from {$previous->name}'s SprintSync login.",
                $person,
                ['user_id' => $previous->id],
            );
        }

        if ($user !== null) {
            $this->auditLogger->handle(
                $person->workspace,
                null,
                $actor,
                AuditAction::PERSON_USER_LINKED,
                $reason ?? "{$actor->name} connected {$person->name} to {$user->name}'s SprintSync login.",
                $person,
                ['user_id' => $user->id],
            );
        }

        return $person;
    }
}
