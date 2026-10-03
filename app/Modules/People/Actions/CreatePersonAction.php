<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Data\StorePersonData;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreatePersonAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLogger,
        private readonly ResolveDepartmentAction $departments,
        private readonly SetPersonUserAction $linker,
    ) {}

    /**
     * The person and their link are saved together: if linking fails, the
     * person is not created either. The department is resolved first, outside
     * the transaction, because it is shared and safe to keep.
     */
    public function handle(Workspace $workspace, StorePersonData $data, User $actor): Person
    {
        $department = $this->departments->handle($workspace, $data->department, $actor);

        return DB::transaction(function () use ($workspace, $data, $actor, $department) {
            /** @var Person $person */
            $person = $workspace->people()->make($data->details());
            $person->department()->associate($department);
            $person->save();

            $this->auditLogger->handle(
                $workspace,
                null,
                $actor,
                AuditAction::PERSON_CREATED,
                "{$actor->name} added {$person->name} to the team.",
                $person,
            );

            $login = $data->user_id !== null
                ? User::query()->findOrFail($data->user_id)
                : $this->obviousLogin($workspace, $person);

            if ($login !== null) {
                $this->linker->handle($person, $login, $actor);
            }

            return $person;
        });
    }

    /**
     * The workspace member this new profile obviously belongs to: same email,
     * not a guest client, not connected to anyone yet. Nothing is guessed
     * beyond that; the owner can still connect a login by hand.
     */
    private function obviousLogin(Workspace $workspace, Person $person): ?User
    {
        if ($person->email === null) {
            return null;
        }

        return $workspace->users()
            ->whereRaw('lower(users.email) = ?', [Str::lower($person->email)])
            ->wherePivot('role', '!=', UserRole::CLIENT->value)
            ->whereNotIn('users.id', $workspace->people()->whereNotNull('user_id')->select('user_id'))
            ->first();
    }
}
