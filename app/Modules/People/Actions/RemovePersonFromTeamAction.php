<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Models\BillingPlanLine;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Actions\StopTimerAction;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RemovePersonFromTeamAction
{
    public function __construct(
        private readonly StopTimerAction $stopTimer,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(Person $person, User $actor): void
    {
        if ($person->removed_at !== null) {
            return;
        }

        $workspace = $person->workspace;

        if ($person->user_id !== null && $person->user_id === $actor->id) {
            throw PeopleException::cannotRemoveSelf();
        }

        if ($person->user_id !== null && $workspace->users()->whereKey($person->user_id)->exists()) {
            throw PeopleException::stillHasAccess($person->name);
        }

        if (filled($person->email) && WorkspaceInvitation::query()->pending()->where('workspace_id', $workspace->id)->whereRaw('lower(email) = ?', [Str::lower((string) $person->email)])->exists()) {
            throw PeopleException::invitationPending($person->name);
        }

        $line = BillingPlanLine::query()
            ->where('person_id', $person->id)
            ->whereHas('plan', fn ($query) => $query->whereNull('paused_at'))
            ->with('plan.client')
            ->first();

        if ($line !== null) {
            throw PeopleException::onRecurringInvoice($person->name, $line->plan->name, $line->plan->client->name);
        }

        $session = TimeSession::query()->where('active_person_id', $person->id)->first();

        if ($session !== null) {
            $this->stopTimer->handle($person, "remove-person:{$person->public_id}", $actor, $session->public_id);
        }

        $removed = DB::transaction(fn () => Person::query()
            ->whereKey($person->getKey())
            ->whereNull('removed_at')
            ->update(['removed_at' => now(), 'removed_by' => $actor->id, 'user_id' => null, 'updated_at' => now()]));

        if ($removed === 1) {
            $this->auditLogger->handle(
                $workspace,
                null,
                $actor,
                AuditAction::PERSON_REMOVED,
                "{$actor->name} deleted {$person->name} from the team.",
                $person,
                ['had_running_timer' => $session !== null],
            );
        }
    }
}
