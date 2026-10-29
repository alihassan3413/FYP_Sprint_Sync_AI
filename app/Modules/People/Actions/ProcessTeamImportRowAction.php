<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\People\Data\StorePersonData;
use App\Modules\People\Data\TeamImportOutcome;
use App\Modules\People\Models\Person;
use App\Modules\People\Models\TeamImportRow;
use App\Modules\TimeTracking\Actions\SaveWorkScheduleAction;
use App\Modules\TimeTracking\Models\WorkSchedule;
use App\Modules\Workspace\Actions\CreateWorkspaceInvitationAction;
use App\Modules\Workspace\Data\WorkspaceInvitationData;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProcessTeamImportRowAction
{
    public function __construct(
        private readonly CreatePersonAction $createPerson,
        private readonly GivePersonAccessAction $giveAccess,
        private readonly CreateWorkspaceInvitationAction $invitations,
        private readonly SaveWorkScheduleAction $schedules,
    ) {}

    public function handle(TeamImportRow $row, Workspace $workspace, User $actor): void
    {
        try {
            DB::transaction(function () use ($row, $workspace, $actor) {
                $locked = TeamImportRow::query()->whereKey($row->getKey())->lockForUpdate()->first();

                if ($locked === null || $locked->processed_at !== null) {
                    return;
                }

                [$outcome, $person, $message] = $this->import($locked, $workspace, $actor);

                $locked->forceFill([
                    'outcome' => $outcome,
                    'person_id' => $person?->id,
                    'messages' => $message === null ? $locked->messages : [...$locked->messages, $message],
                    'processed_at' => now(),
                ])->save();
            });
        } catch (Throwable $exception) {
            report($exception);

            TeamImportRow::query()->whereKey($row->getKey())->whereNull('processed_at')->update([
                'outcome' => TeamImportOutcome::Failed,
                'messages' => json_encode([...$row->messages, 'Could not be imported: '.Str::limit($exception->getMessage(), 200)]),
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function import(TeamImportRow $row, Workspace $workspace, User $actor): array
    {
        $email = (string) $row->email;

        if ($workspace->users()->whereRaw('lower(users.email) = ?', [$email])->exists()) {
            return [TeamImportOutcome::Skipped, null, 'Skipped: already a member of this workspace.'];
        }

        if (WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->whereRaw('lower(email) = ?', [$email])->pending()->exists()) {
            return [TeamImportOutcome::Skipped, null, 'Skipped: already invited.'];
        }

        $invitation = new WorkspaceInvitationData($email, $row->role, $row->workspace_role_id, $row->timezone);

        if ($row->role === UserRole::CLIENT) {
            $this->invitations->handle($workspace, $actor, $invitation);

            return [TeamImportOutcome::Invited, null, null];
        }

        $person = $workspace->people()->whereRaw('lower(email) = ?', [$email])->whereNull('user_id')->first()
            ?? $this->createPerson->handle($workspace, new StorePersonData($row->fullName(), $email, null, $row->department), $actor);

        $result = $this->giveAccess->handle($person, $invitation, $actor);

        $this->copySchedule($row, $workspace, $person, $actor);

        return [$result === 'linked' ? TeamImportOutcome::Linked : TeamImportOutcome::Invited, $person, null];
    }

    private function copySchedule(TeamImportRow $row, Workspace $workspace, Person $person, User $actor): void
    {
        if ($row->schedule_person_id === null) {
            return;
        }

        $source = WorkSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->where('person_id', $row->schedule_person_id)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($source === null || $source->isEmpty()) {
            return;
        }

        $this->schedules->handle($workspace, $person, $source->days ?? [], $source->start_time, $source->end_time, $source->timezone, $actor);
    }
}
